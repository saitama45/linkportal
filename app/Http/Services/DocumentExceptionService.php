<?php

namespace App\Http\Services;

use App\Models\DocumentException;
use App\Models\DocumentExceptionRule;
use App\Models\IntakeDocument;
use Illuminate\Support\Collection;

/**
 * Table-driven exception rules with hardcoded evaluators. Rule rows in
 * portal_document_exception_rules control enabled/severity/thresholds; the
 * checks themselves live here. Evaluations are idempotent: re-running a
 * checkpoint creates missing exceptions and auto-resolves ones that no longer
 * apply (waived ones are left alone).
 */
class DocumentExceptionService
{
    /** Rules re-evaluated per checkpoint. Others (failed_conversion, failed_handoff, overdue_review) are raised directly. */
    private const CHECKPOINTS = [
        'intake' => ['vendor_inactive', 'unsupported_file', 'duplicate_file', 'unmatched_email', 'missing_document_type'],
        'extraction' => ['vendor_inactive', 'missing_document_type', 'missing_template', 'missing_required_field',
            'low_confidence', 'duplicate_invoice_no', 'po_mismatch', 'po_line_mismatch', 'po_amount_exceeded',
            'po_expired', 'total_mismatch', 'duplicate_file'],
    ];

    public function evaluate(IntakeDocument $document, string $checkpoint): void
    {
        $ruleKeys = self::CHECKPOINTS[$checkpoint] ?? [];
        $rules = DocumentExceptionRule::whereIn('rule_key', $ruleKeys)->get()->keyBy('rule_key');

        foreach ($ruleKeys as $key) {
            $rule = $rules->get($key);
            if (! $rule || ! $rule->enabled) {
                continue;
            }

            $raised = collect($this->{'check'.str_replace(' ', '', ucwords(str_replace('_', ' ', $key)))}($document, $rule));
            $this->sync($document, $rule, $raised);
        }
    }

    /**
     * Raise an exception outside the evaluators (job failures, overdue sweeps).
     */
    public function raise(IntakeDocument $document, string $ruleKey, string $message, array $context = [], ?string $fieldKey = null): ?DocumentException
    {
        $rule = DocumentExceptionRule::where('rule_key', $ruleKey)->first();
        if ($rule && ! $rule->enabled) {
            return null;
        }

        return DocumentException::firstOrCreate(
            [
                'intake_document_id' => $document->id,
                'rule_key' => $ruleKey,
                'field_key' => $fieldKey,
                'status' => 'open',
            ],
            [
                'severity' => $rule->severity ?? 'blocker',
                'message' => $message,
                'context' => $context ?: null,
            ],
        );
    }

    public function resolveByRule(IntakeDocument $document, string|array $ruleKeys, ?int $userId = null, string $note = 'auto-resolved'): void
    {
        $document->exceptions()
            ->whereIn('rule_key', (array) $ruleKeys)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_by' => $userId,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);
    }

    /**
     * Create newly-raised exceptions and auto-resolve open ones the evaluator
     * no longer reports. Keyed by rule_key + field_key.
     */
    private function sync(IntakeDocument $document, DocumentExceptionRule $rule, Collection $raised): void
    {
        // A human who Waived or Resolved an exception has made a deliberate
        // decision — re-evaluation must not resurrect it. Auto-resolved ones
        // (resolved_by null, raised then cleared automatically) are excluded, so a
        // condition that genuinely returns is still re-flagged.
        $existing = $document->exceptions()
            ->where('rule_key', $rule->rule_key)
            ->whereIn('status', ['open', 'waived', 'resolved'])
            ->get();

        $open = $existing->where('status', 'open')->keyBy(fn ($e) => (string) $e->field_key);
        $acknowledged = $existing
            ->filter(fn ($e) => $e->status === 'waived' || ($e->status === 'resolved' && $e->resolved_by !== null))
            ->keyBy(fn ($e) => (string) $e->field_key);

        $raisedKeys = [];
        foreach ($raised as $item) {
            $fieldKey = $item['field_key'] ?? null;
            $raisedKeys[] = (string) $fieldKey;

            // A human already decided on this exception — don't re-raise it.
            if ($acknowledged->has((string) $fieldKey)) {
                continue;
            }

            if ($existingOpen = $open->get((string) $fieldKey)) {
                $existingOpen->update(['message' => $item['message'], 'context' => $item['context'] ?? null]);
                continue;
            }

            $document->exceptions()->create([
                'rule_key' => $rule->rule_key,
                'severity' => $rule->severity,
                'field_key' => $fieldKey,
                'message' => $item['message'],
                'context' => $item['context'] ?? null,
                'status' => 'open',
            ]);
            $document->recordEvent('exception_raised', $item['message'], ['rule_key' => $rule->rule_key]);
        }

        // Auto-resolve OPEN exceptions the evaluator no longer reports (waived untouched).
        foreach ($open as $fieldKey => $exception) {
            if (! in_array($fieldKey, $raisedKeys, true)) {
                $exception->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolution_note' => 'auto-resolved: condition no longer applies',
                ]);
            }
        }
    }

    // ---- Evaluators: each returns [] or a list of {field_key?, message, context?} ----

    protected function checkVendorInactive(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if (! $doc->vendor_id || ! $doc->vendor) {
            return [];
        }

        return $doc->vendor->isActive() ? [] : [[
            'message' => "Vendor {$doc->vendor->name} is {$doc->vendor->status}.",
            'context' => ['vendor_status' => $doc->vendor->status],
        ]];
    }

    protected function checkUnsupportedFile(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        $allowed = $rule->configValue('allowed_extensions', ['pdf', 'doc', 'docx']);
        $ext = strtolower(pathinfo($doc->original_filename, PATHINFO_EXTENSION));

        return in_array($ext, $allowed, true) ? [] : [[
            'message' => "Unsupported file type .{$ext} (allowed: ".implode(', ', $allowed).').',
            'context' => ['extension' => $ext],
        ]];
    }

    protected function checkDuplicateFile(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        $duplicate = IntakeDocument::where('file_hash', $doc->file_hash)
            ->where('id', '!=', $doc->id)
            ->whereNotIn('status', [IntakeDocument::STATUS_CANCELLED, IntakeDocument::STATUS_REJECTED])
            ->first();

        return $duplicate ? [[
            'message' => "Identical file already uploaded as {$duplicate->reference_no}.",
            'context' => ['duplicate_reference_no' => $duplicate->reference_no, 'duplicate_id' => $duplicate->id],
        ]] : [];
    }

    protected function checkUnmatchedEmail(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if ($doc->source !== 'email' || $doc->vendor_id) {
            return [];
        }

        return [[
            'message' => 'Email sender could not be matched to a registered vendor.',
            'context' => ['from_email' => $doc->inboundEmail?->from_email],
        ]];
    }

    protected function checkMissingDocumentType(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        return $doc->document_type ? [] : [[
            'message' => 'Document type has not been classified (invoice / purchase order / quotation).',
        ]];
    }

    protected function checkMissingTemplate(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        return $doc->template_version_id ? [] : [[
            'message' => 'No active OCR template for this vendor and document type; all fields need manual entry.',
        ]];
    }

    protected function checkMissingRequiredField(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if (! $doc->document_type) {
            return [];
        }
        $required = $rule->configValue("required_fields.{$doc->document_type}", []);
        $raised = [];
        foreach ($required as $key) {
            $value = $this->fieldValue($doc, $key);
            if ($value === null || $value === '') {
                $raised[] = [
                    'field_key' => $key,
                    'message' => "Required field \"{$key}\" is missing or empty.",
                ];
            }
        }

        return $raised;
    }

    protected function checkLowConfidence(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        $extraction = $doc->latestExtraction;
        if (! $extraction || $extraction->status !== 'completed' || ! $doc->template_version_id) {
            return [];
        }

        $minField = (float) $rule->configValue('min_field_confidence', 0.75);
        $minOverall = (float) $rule->configValue('min_overall_confidence', 0.80);
        $corrected = array_keys($doc->validated_fields ?? []);
        $raised = [];

        foreach ($extraction->header_fields ?? [] as $field) {
            // A manually corrected field no longer depends on OCR confidence
            if (in_array($field['key'], $corrected, true)) {
                continue;
            }
            if (($field['confidence'] ?? 0) < $minField) {
                $raised[] = [
                    'field_key' => $field['key'],
                    'message' => sprintf('Field "%s" extracted with low confidence (%.0f%%).', $field['key'], ($field['confidence'] ?? 0) * 100),
                    'context' => ['confidence' => $field['confidence'] ?? 0],
                ];
            }
        }

        if (($extraction->overall_confidence ?? 0) < $minOverall && ! $doc->validated_at) {
            $raised[] = [
                'field_key' => '_overall',
                'message' => sprintf('Overall extraction confidence is low (%.0f%%).', ($extraction->overall_confidence ?? 0) * 100),
                'context' => ['confidence' => (float) ($extraction->overall_confidence ?? 0)],
            ];
        }

        return $raised;
    }

    protected function checkDuplicateInvoiceNo(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if ($doc->document_type !== 'invoice' || ! $doc->vendor_id || ! $doc->invoice_no) {
            return [];
        }

        $inPortal = \App\Models\Invoice::where('vendor_id', $doc->vendor_id)
            ->where('invoice_no', $doc->invoice_no)
            ->exists();

        $inIntake = IntakeDocument::where('vendor_id', $doc->vendor_id)
            ->where('invoice_no', $doc->invoice_no)
            ->where('id', '!=', $doc->id)
            ->whereNotIn('status', [IntakeDocument::STATUS_CANCELLED, IntakeDocument::STATUS_REJECTED])
            ->exists();

        return ($inPortal || $inIntake) ? [[
            'field_key' => 'invoice_no',
            'message' => "Invoice number \"{$doc->invoice_no}\" already exists for this vendor.",
            'context' => ['in_portal_invoices' => $inPortal, 'in_intake' => $inIntake],
        ]] : [];
    }

    protected function checkPoMismatch(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        // Only an invoice *references* a PO. A purchase-order document's own
        // po_number identifies itself, so reconciling it against the PO list
        // would just match itself once approved — and false-positive before then.
        if ($doc->document_type !== 'invoice' || ! $doc->vendor_id || ! $doc->po_number) {
            return [];
        }

        // POs now arrive as intake documents; portal_purchase_orders is frozen but
        // still holds pre-pipeline history, so both are consulted.
        $intakePos = IntakeDocument::query()
            ->where('vendor_id', $doc->vendor_id)
            ->where('document_type', 'purchase_order')
            ->whereNotIn('status', [IntakeDocument::STATUS_CANCELLED, IntakeDocument::STATUS_REJECTED]);
        $legacyPos = \App\Models\PurchaseOrder::where('vendor_id', $doc->vendor_id);

        if (! $intakePos->clone()->exists() && ! $legacyPos->clone()->exists()) {
            return []; // vendor has no POs on record; nothing to reconcile against
        }

        $samePo = $intakePos->clone()->where('po_number', $doc->po_number)->first(['reference_no', 'status']);

        // Approval is what authorises billing against a PO.
        if ($samePo?->status === IntakeDocument::STATUS_APPROVED
            || $legacyPos->clone()->where('po_number', $doc->po_number)->exists()) {
            return [];
        }

        // The number is right but the PO hasn't cleared accounting — a different
        // problem from a wrong number, and worth saying so plainly.
        if ($samePo) {
            return [[
                'field_key' => 'po_number',
                'message' => "PO \"{$doc->po_number}\" ({$samePo->reference_no}) is not approved yet — it is currently "
                    .str_replace('_', ' ', $samePo->status).'.',
                'context' => ['po_reference_no' => $samePo->reference_no, 'po_status' => $samePo->status],
            ]];
        }

        // Listing what this vendor *does* have approved makes a typo obvious.
        $approved = $intakePos->clone()
            ->where('status', IntakeDocument::STATUS_APPROVED)
            ->whereNotNull('po_number')
            ->orderByDesc('external_decided_at')
            ->limit(5)
            ->pluck('po_number')
            ->values();

        return [[
            'field_key' => 'po_number',
            'message' => $approved->isEmpty()
                ? "PO number \"{$doc->po_number}\" not found among this vendor's purchase orders."
                : "PO number \"{$doc->po_number}\" not found. This vendor's approved POs: ".$approved->implode(', ').'.',
            'context' => ['approved_po_numbers' => $approved->all()],
        ]];
    }

    protected function checkTotalMismatch(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        $tolerance = (float) $rule->configValue('tolerance', 0.05);
        $total = $doc->total_amount !== null ? (float) $doc->total_amount : null;
        if ($total === null) {
            return [];
        }

        $lineSum = $this->lineItemSum($doc);
        if ($lineSum === null) {
            return [];
        }

        $expected = $lineSum + (float) ($doc->tax_amount ?? 0);
        // Some vendors quote line totals tax-inclusive; accept either reconciliation
        if (abs($total - $expected) <= $tolerance || abs($total - $lineSum) <= $tolerance) {
            return [];
        }

        return [[
            'field_key' => 'total_amount',
            'message' => sprintf('Total %.2f does not reconcile with line items (%.2f) + tax (%.2f).', $total, $lineSum, (float) ($doc->tax_amount ?? 0)),
            'context' => ['total' => $total, 'line_sum' => $lineSum, 'tax' => (float) ($doc->tax_amount ?? 0), 'tolerance' => $tolerance],
        ]];
    }

    /**
     * Three-way-match, line level: an invoice's items compared against the
     * purchase order it bills. Flags a per-unit price that differs from the PO
     * and any line item the PO never ordered. Quantity differences are expected
     * (partial invoicing), so they're handled by amount, not here.
     */
    protected function checkPoLineMismatch(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if ($doc->document_type !== 'invoice') {
            return [];
        }

        $po = $doc->matchedPo();
        if (! $po) {
            return []; // no approved PO to reconcile against; po_mismatch owns that signal
        }

        // Fraction the unit price may drift before it's called a variance.
        $priceTolerance = (float) $rule->configValue('price_tolerance', 0.02);

        $poByDesc = $po->lineItems
            ->filter(fn ($i) => $this->normalizeDesc($i->description) !== '')
            ->keyBy(fn ($i) => $this->normalizeDesc($i->description));

        $raised = [];
        foreach ($doc->lineItems as $line) {
            $key = $this->normalizeDesc($line->description);
            if ($key === '') {
                continue;
            }

            $poLine = $poByDesc->get($key);
            if (! $poLine) {
                $raised[] = [
                    'field_key' => 'line:'.$line->line_no,
                    'message' => "Line \"{$line->description}\" is not on PO {$po->po_number}.",
                    'context' => ['line_no' => $line->line_no, 'po_reference_no' => $po->reference_no],
                ];
                continue;
            }

            $invPrice = $line->unit_price !== null ? (float) $line->unit_price : null;
            $poPrice = $poLine->unit_price !== null ? (float) $poLine->unit_price : null;
            if ($invPrice !== null && $poPrice !== null && $poPrice > 0
                && abs($invPrice - $poPrice) > $poPrice * $priceTolerance) {
                $raised[] = [
                    'field_key' => 'line:'.$line->line_no,
                    'message' => sprintf('Unit price for "%s" is %.2f on the invoice but %.2f on PO %s.',
                        $line->description, $invPrice, $poPrice, $po->po_number),
                    'context' => ['line_no' => $line->line_no, 'invoice_price' => $invPrice, 'po_price' => $poPrice],
                ];
            }
        }

        return $raised;
    }

    /**
     * Over-billing guard: this invoice plus everything already billed against
     * the PO must not exceed the PO's approved total (beyond a small tolerance).
     */
    protected function checkPoAmountExceeded(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if ($doc->document_type !== 'invoice' || $doc->total_amount === null) {
            return [];
        }

        $po = $doc->matchedPo();
        if (! $po || $po->total_amount === null) {
            return [];
        }

        $tolerance = (float) $rule->configValue('tolerance', 0.01);
        $poTotal = (float) $po->total_amount;

        // Everything else billed against the PO, plus this invoice's own total.
        $priorBilled = $po->invoicedToDate($doc->id);
        $cumulative = $priorBilled + (float) $doc->total_amount;

        if ($cumulative <= $poTotal * (1 + $tolerance)) {
            return [];
        }

        return [[
            'field_key' => 'total_amount',
            'message' => sprintf('Billing %.2f against PO %s would bring the total invoiced to %.2f, over the PO amount of %.2f.',
                (float) $doc->total_amount, $po->po_number, $cumulative, $poTotal),
            'context' => [
                'po_reference_no' => $po->reference_no,
                'po_total' => $poTotal,
                'already_billed' => $priorBilled,
                'this_invoice' => (float) $doc->total_amount,
                'cumulative' => round($cumulative, 2),
            ],
        ]];
    }

    /**
     * Enforce the PO validity window: an invoice billing a PO that was approved
     * longer ago than the configured window is flagged. Off by default
     * (validity_days null) so POs stay open indefinitely until a policy is set.
     */
    protected function checkPoExpired(IntakeDocument $doc, DocumentExceptionRule $rule): array
    {
        if ($doc->document_type !== 'invoice') {
            return [];
        }

        $validityDays = (int) $rule->configValue('validity_days', 0);
        if ($validityDays <= 0) {
            return []; // no expiration policy configured
        }

        $po = $doc->matchedPo();
        if (! $po || ! $po->isExpired($validityDays)) {
            return [];
        }

        $expiredOn = $po->external_decided_at->copy()->addDays($validityDays);

        return [[
            'field_key' => 'po_number',
            'message' => sprintf('PO %s expired on %s (%d-day validity from approval on %s).',
                $po->po_number, $expiredOn->toDateString(), $validityDays, $po->external_decided_at->toDateString()),
            'context' => [
                'po_reference_no' => $po->reference_no,
                'approved_at' => $po->external_decided_at->toIso8601String(),
                'expired_on' => $expiredOn->toIso8601String(),
                'validity_days' => $validityDays,
            ],
        ]];
    }

    // ---- helpers ----

    /** Lowercased, whitespace-collapsed description for loose line matching. */
    private function normalizeDesc(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $value)));
    }

    private function fieldValue(IntakeDocument $doc, string $key)
    {
        // Promoted columns first (kept in sync by extraction + validation)
        if (in_array($key, ['invoice_no', 'po_number', 'document_date', 'due_date', 'subtotal', 'tax_amount', 'total_amount'], true)) {
            return $doc->{$key};
        }
        if (isset($doc->validated_fields[$key])) {
            return $doc->validated_fields[$key];
        }

        return $doc->latestExtraction?->field($key)['value'] ?? null;
    }

    private function lineItemSum(IntakeDocument $doc): ?float
    {
        $items = $doc->validated_line_items
            ?? $doc->latestExtraction?->line_items;
        if (! $items) {
            return null;
        }

        $sum = 0.0;
        $found = false;
        foreach ($items as $item) {
            // validated shape: {line_total: 123.45}; extraction shape: {cells: {line_total: {value}}}
            $value = $item['line_total'] ?? ($item['cells']['line_total']['value'] ?? null);
            if (is_numeric($value)) {
                $sum += (float) $value;
                $found = true;
            }
        }

        return $found ? round($sum, 2) : null;
    }
}
