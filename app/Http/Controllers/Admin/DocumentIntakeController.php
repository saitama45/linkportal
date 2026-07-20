<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\DocumentExceptionService;
use App\Http\Services\DocumentIntakeService;
use App\Models\IntakeDocument;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DocumentIntakeController extends Controller
{
    public function __construct(
        private DocumentIntakeService $intake,
        private DocumentExceptionService $exceptions,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('document-intake.view'), 403);

        $query = IntakeDocument::query()
            ->with(['vendor:id,code,name'])
            ->withCount(['openExceptions'])
            ->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_no', 'like', "%{$request->search}%")
                    ->orWhere('original_filename', 'like', "%{$request->search}%")
                    ->orWhere('invoice_no', 'like', "%{$request->search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$request->search}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        // Follow-up queue: approved POs nobody has billed against yet, oldest
        // approval first so the most overdue surfaces at the top.
        if ($request->boolean('awaiting_invoice')) {
            $query->approvedPoAwaitingInvoice()->reorder('external_decided_at');
        }

        // Conditional aggregation instead of GROUP BY CASE: SQL Server refuses to
        // match the SELECT and GROUP BY CASE expressions once parameter-bound.
        $aging = \App\Models\DocumentException::query()
            ->where('status', 'open')
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS d0_3, '
                .'SUM(CASE WHEN created_at < ? AND created_at >= ? THEN 1 ELSE 0 END) AS d4_7, '
                .'SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) AS d8_plus',
                [now()->subDays(3), now()->subDays(3), now()->subDays(7), now()->subDays(7)]
            )
            ->first();

        return Inertia::render('Admin/DocumentIntake/Index', [
            'stats' => [
                'pending_validation' => IntakeDocument::where('status', IntakeDocument::STATUS_NEEDS_VALIDATION)->count(),
                'pending_review' => IntakeDocument::whereIn('status', [IntakeDocument::STATUS_SENDING, IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW])->count(),
                'open_exceptions' => (int) ($aging->d0_3 ?? 0) + (int) ($aging->d4_7 ?? 0) + (int) ($aging->d8_plus ?? 0),
                'exception_aging' => ['0-3' => (int) ($aging->d0_3 ?? 0), '4-7' => (int) ($aging->d4_7 ?? 0), '8+' => (int) ($aging->d8_plus ?? 0)],
                'po_awaiting_invoice' => IntakeDocument::approvedPoAwaitingInvoice()->count(),
            ],
            'cycleTimes' => $this->cycleTimeMetrics(),
            'documents' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'status', 'document_type', 'source', 'date_from', 'date_to', 'awaiting_invoice']),
            'canUpload' => $request->user()->can('document-intake.validate'),
            'canDelete' => $request->user()->can('document-intake.delete'),
            'vendors' => Vendor::where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'statuses' => [
                IntakeDocument::STATUS_RECEIVED, IntakeDocument::STATUS_CONVERTING,
                IntakeDocument::STATUS_CONVERSION_FAILED, IntakeDocument::STATUS_EXTRACTING,
                IntakeDocument::STATUS_EXTRACTION_FAILED, IntakeDocument::STATUS_NEEDS_VALIDATION,
                IntakeDocument::STATUS_VALIDATED, IntakeDocument::STATUS_SENDING,
                IntakeDocument::STATUS_HANDOFF_FAILED, IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW,
                IntakeDocument::STATUS_APPROVED, IntakeDocument::STATUS_RETURNED,
                IntakeDocument::STATUS_REJECTED, IntakeDocument::STATUS_CANCELLED,
            ],
        ]);
    }

    /** Admin uploads a document on a vendor's behalf, into the same pipeline. */
    public function store(Request $request)
    {
        abort_unless($request->user()->can('document-intake.validate'), 403);

        $validated = $request->validate([
            'vendor_id' => 'required|integer|exists:portal_vendors,id',
            'document_type' => 'required|in:invoice,purchase_order,quotation',
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx',
        ]);

        $vendor = Vendor::findOrFail($validated['vendor_id']);
        $document = $this->intake->createFromAdminUpload(
            $vendor,
            $request->file('file'),
            $validated['document_type'],
            $request->user()->id,
        );

        AuditLogger::log('intake_document_uploaded_by_admin', $document);

        return redirect()
            ->route('document-intake.show', $document->id)
            ->with('success', "Document {$document->reference_no} received and queued for processing.");
    }

    public function show(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.view'), 403);

        return Inertia::render('Admin/DocumentIntake/Validate', [
            'document' => $intakeDocument->load([
                'vendor:id,code,name,status',
                'latestExtraction',
                'templateVersion.template:id,name,document_type',
                'exceptions' => fn ($q) => $q->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('severity'),
                'events',
            ]),
            'poMatch' => $this->poMatchSummary($intakeDocument),
            'vendors' => $intakeDocument->vendor_id
                ? []
                : Vendor::orderBy('name')->get(['id', 'code', 'name']),
            'documentTypes' => IntakeDocument::DOCUMENT_TYPES,
            'canValidate' => $request->user()->can('document-intake.validate'),
            'canSubmit' => $request->user()->can('document-intake.approve'),
            'canResolveExceptions' => $request->user()->can('document-exceptions.resolve'),
            'canDelete' => $request->user()->can('document-intake.delete'),
        ]);
    }

    /**
     * PO↔Invoice reconciliation shown on the detail panel: for an invoice, the
     * PO it bills and that PO's remaining balance; for a PO, its own fulfillment.
     * Null for anything that has no PO relationship to show.
     */
    private function poMatchSummary(IntakeDocument $doc): ?array
    {
        $validityDays = IntakeDocument::poValidityDays();

        if ($doc->document_type === 'invoice') {
            $po = $doc->matchedPo();
            if (! $po) {
                return null;
            }

            return [
                'role' => 'invoice',
                'po_reference_no' => $po->reference_no,
                'po_number' => $po->po_number,
                'po_id' => $po->id,
                // 'locked' once persisted at validation — stable even if the PO's
                // number is later edited; 'derived' while still provisional.
                'link' => $doc->matched_po_intake_document_id ? 'locked' : 'derived',
                'po_total' => $po->total_amount !== null ? (float) $po->total_amount : null,
                'invoiced_to_date' => $po->invoicedToDate(),
                'remaining_balance' => $po->remainingBalance(),
                'fulfillment' => $po->fulfillmentStatus(),
                'expired' => $po->isExpired($validityDays),
            ];
        }

        return $this->poMatchSummaryPurchaseOrder($doc, $validityDays);
    }

    private function poMatchSummaryPurchaseOrder(IntakeDocument $doc, ?int $validityDays): ?array
    {
        if ($doc->document_type === 'purchase_order' && $doc->status === IntakeDocument::STATUS_APPROVED) {
            // The invoices billed against this PO — makes partial invoicing traceable.
            $invoices = $doc->invoicesForPo()
                ->latest('created_at')
                ->get(['id', 'reference_no', 'invoice_no', 'total_amount', 'status', 'created_at'])
                ->map(fn ($inv) => [
                    'id' => $inv->id,
                    'reference_no' => $inv->reference_no,
                    'invoice_no' => $inv->invoice_no,
                    'total_amount' => $inv->total_amount !== null ? (float) $inv->total_amount : null,
                    'status' => $inv->status,
                ]);

            return [
                'role' => 'purchase_order',
                'po_total' => $doc->total_amount !== null ? (float) $doc->total_amount : null,
                'invoiced_to_date' => $doc->invoicedToDate(),
                'remaining_balance' => $doc->remainingBalance(),
                'fulfillment' => $doc->fulfillmentStatus(),
                'expired' => $doc->isExpired($validityDays),
                'invoice_count' => $invoices->count(),
                'invoices' => $invoices,
            ];
        }

        return null;
    }

    /**
     * Pipeline cycle-time averages (in minutes; the UI humanizes). Each metric
     * reports a sample count so a tiny/zero sample reads honestly rather than as
     * a misleading average. deleted_at is filtered explicitly since these are raw
     * builder queries outside the SoftDeletes global scope.
     */
    private function cycleTimeMetrics(): array
    {
        $table = (new IntakeDocument)->getTable();

        // Single AVG(minutes) + COUNT over one filtered span, SQL-Server-safe
        // (DATEDIFF, cast to float so the average keeps its decimals).
        $span = function (string $fromCol, string $toCol, callable $filter) use ($table): array {
            $q = DB::table($table)
                ->selectRaw("AVG(CAST(DATEDIFF(MINUTE, {$fromCol}, {$toCol}) AS FLOAT)) AS avg_min, COUNT(*) AS n")
                ->whereNull('deleted_at')
                ->whereNotNull($fromCol)
                ->whereNotNull($toCol);
            $filter($q);
            $row = $q->first();

            return [
                'avg_minutes' => $row->avg_min !== null ? round((float) $row->avg_min, 1) : null,
                'count' => (int) $row->n,
            ];
        };

        // PO approved -> first invoice against it. Two set-based queries + a PHP
        // join keeps it off CROSS APPLY while staying a bounded number of queries.
        $firstInvoice = DB::table($table)
            ->selectRaw('vendor_id, po_number, MIN(created_at) AS first_created')
            ->where('document_type', 'invoice')
            ->whereNull('deleted_at')
            ->whereNotNull('po_number')
            ->whereNotIn('status', [IntakeDocument::STATUS_CANCELLED, IntakeDocument::STATUS_REJECTED])
            ->groupBy('vendor_id', 'po_number')
            ->get()
            ->keyBy(fn ($r) => $r->vendor_id.'|'.$r->po_number);

        $pos = DB::table($table)
            ->select('vendor_id', 'po_number', 'external_decided_at')
            ->where('document_type', 'purchase_order')
            ->where('status', IntakeDocument::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->whereNotNull('po_number')
            ->whereNotNull('external_decided_at')
            ->get();

        $poDeltas = [];
        foreach ($pos as $po) {
            $fi = $firstInvoice->get($po->vendor_id.'|'.$po->po_number);
            if (! $fi || ! $fi->first_created) {
                continue;
            }
            // Signed: only count invoices submitted after the PO was approved.
            $mins = Carbon::parse($po->external_decided_at)->diffInMinutes(Carbon::parse($fi->first_created), false);
            if ($mins > 0) {
                $poDeltas[] = $mins;
            }
        }

        return [
            'intake_to_validated' => $span('created_at', 'validated_at', fn ($q) => $q),
            'review_turnaround' => $span('submitted_at', 'external_decided_at', fn ($q) => $q),
            'end_to_end' => $span('created_at', 'external_decided_at', fn ($q) => $q->where('status', IntakeDocument::STATUS_APPROVED)),
            'po_to_first_invoice' => [
                'avg_minutes' => $poDeltas ? round(array_sum($poDeltas) / count($poDeltas), 1) : null,
                'count' => count($poDeltas),
            ],
        ];
    }

    /** Soft-delete an intake document (files are retained for restore/audit). */
    public function destroy(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.delete'), 403);

        // Documents already handed off to Accounting must not be deleted out from
        // under the external review — resolve the handoff first.
        $inFlight = [
            IntakeDocument::STATUS_SENDING,
            IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW,
        ];
        if (in_array($intakeDocument->status, $inFlight, true)) {
            return redirect()
                ->route('document-intake.index')
                ->with('error', 'This document is with Accounting for review and cannot be deleted.');
        }

        $reference = $intakeDocument->reference_no;

        $intakeDocument->recordEvent('deleted', 'Document deleted by admin', [], 'user', $request->user()->id);
        AuditLogger::log('intake_document_deleted', $intakeDocument);
        $intakeDocument->delete();

        return redirect()
            ->route('document-intake.index')
            ->with('success', "Document {$reference} deleted.");
    }

    /** Stream the working PDF for the in-browser viewer. */
    public function file(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.view'), 403);
        $path = $intakeDocument->converted_pdf_path ?? $intakeDocument->file_path;
        abort_unless($path && Storage::disk(DocumentIntakeService::DISK)->exists($path), 404);

        return Storage::disk(DocumentIntakeService::DISK)->response($path, $intakeDocument->original_filename);
    }

    /** Persist corrected header fields + line items and re-run the rules. */
    /**
     * Values for the header fields this document's template defines that are not
     * promoted columns. Only keys the template actually declares are accepted, so
     * an arbitrary payload cannot inflate the stored JSON.
     */
    private function customFieldValues(Request $request, IntakeDocument $intakeDocument): array
    {
        $promoted = ['invoice_no', 'po_number', 'document_date', 'due_date',
            'currency', 'subtotal', 'tax_amount', 'total_amount', 'vendor_address'];

        $declared = collect(data_get($intakeDocument->templateVersion, 'annotations.fields', []))
            ->pluck('key')
            ->filter()
            ->reject(fn ($key) => in_array($key, $promoted, true));

        $submitted = (array) $request->input('fields', []);

        return $declared
            ->filter(fn ($key) => array_key_exists($key, $submitted))
            ->mapWithKeys(fn ($key) => [
                $key => is_scalar($submitted[$key]) ? mb_substr(trim((string) $submitted[$key]), 0, 500) : null,
            ])
            ->all();
    }

    public function saveCorrections(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.validate'), 403);
        abort_unless($intakeDocument->canBeValidated(), 422, 'Document is not in a validatable state.');

        $validated = $request->validate([
            'fields' => 'required|array',
            'fields.invoice_no' => 'nullable|string|max:50',
            'fields.po_number' => 'nullable|string|max:50',
            'fields.document_date' => 'nullable|date',
            'fields.due_date' => 'nullable|date',
            'fields.currency' => 'nullable|string|size:3',
            'fields.subtotal' => 'nullable|numeric|min:0',
            'fields.tax_amount' => 'nullable|numeric|min:0',
            'fields.total_amount' => 'nullable|numeric|min:0',
            'fields.vendor_address' => 'nullable|string|max:500',
            'line_items' => 'nullable|array',
            'line_items.*.description' => 'required|string|max:500',
            'line_items.*.quantity' => 'nullable|numeric',
            'line_items.*.uom' => 'nullable|string|max:20',
            'line_items.*.unit_price' => 'nullable|numeric',
            'line_items.*.line_total' => 'nullable|numeric',
        ]);

        // A template may define header fields beyond the promoted ones. Those have
        // no column and no rule above, so validate() would drop them — merge them
        // back (as trimmed strings) to keep them in validated_fields.
        $fields = $validated['fields'] + $this->customFieldValues($request, $intakeDocument);

        $intakeDocument->fill([
            'invoice_no' => $fields['invoice_no'] ?? null,
            'po_number' => $fields['po_number'] ?? null,
            'document_date' => $fields['document_date'] ?? null,
            'due_date' => $fields['due_date'] ?? null,
            'currency' => $fields['currency'] ?? 'PHP',
            'subtotal' => $fields['subtotal'] ?? null,
            'tax_amount' => $fields['tax_amount'] ?? null,
            'total_amount' => $fields['total_amount'] ?? null,
            'validated_fields' => $fields,
            'validated_line_items' => $validated['line_items'] ?? null,
        ])->save();

        $intakeDocument->syncLineItems();
        // po_number may have just changed — refresh the persisted PO link before
        // the PO-based checks (over-billing, expiry) evaluate against it.
        $intakeDocument->resolveMatchedPo();
        $intakeDocument->recordEvent('corrections_saved', null, [], 'user', $request->user()->id);
        $this->exceptions->evaluate($intakeDocument->fresh(['vendor', 'latestExtraction']), 'extraction');

        return redirect()->back()->with('success', 'Corrections saved.');
    }

    public function markValidated(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.validate'), 403);
        abort_unless($intakeDocument->canBeValidated(), 422, 'Document is not in a validatable state.');

        $this->exceptions->evaluate($intakeDocument->load(['vendor', 'latestExtraction']), 'extraction');
        $intakeDocument->refresh();

        if ($intakeDocument->hasOpenBlockers()) {
            return redirect()->back()->with('error', 'Resolve or waive blocking exceptions before validating.');
        }

        $intakeDocument->forceFill([
            'status' => IntakeDocument::STATUS_VALIDATED,
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
        ])->save();
        $intakeDocument->syncLineItems();
        $intakeDocument->resolveMatchedPo(); // lock in the matched PO at validation
        $intakeDocument->recordEvent('validated', null, [], 'user', $request->user()->id);
        AuditLogger::log('intake_document_validated', $intakeDocument);

        return redirect()->back()->with('success', 'Document marked as validated.');
    }

    /** Re-run conversion + extraction (new attempt). */
    public function rerunOcr(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.validate'), 403);

        $blocked = [
            IntakeDocument::STATUS_SENDING, IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW,
            IntakeDocument::STATUS_APPROVED, IntakeDocument::STATUS_REJECTED, IntakeDocument::STATUS_CANCELLED,
        ];
        if (in_array($intakeDocument->status, $blocked, true)) {
            return redirect()->back()->with('error', 'Document is past the extraction stage.');
        }
        if (! $intakeDocument->vendor_id || ! $intakeDocument->document_type) {
            return redirect()->back()->with('error', 'Assign a vendor and document type first.');
        }

        $this->intake->reprocess($intakeDocument, $request->user()->id);

        return redirect()->back()->with('success', 'OCR re-run queued.');
    }

    /** Hand a validated document off to ghelpdesk for accounting review. */
    public function submit(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.approve'), 403);

        $retryable = [IntakeDocument::STATUS_VALIDATED, IntakeDocument::STATUS_HANDOFF_FAILED];
        if (! in_array($intakeDocument->status, $retryable, true)) {
            return redirect()->back()->with('error', 'Only validated documents can be submitted.');
        }

        $this->exceptions->evaluate($intakeDocument->load(['vendor', 'latestExtraction']), 'extraction');
        $intakeDocument->refresh();
        if ($intakeDocument->hasOpenBlockers()) {
            return redirect()->back()->with('error', 'Resolve or waive blocking exceptions before submitting.');
        }

        $this->exceptions->resolveByRule($intakeDocument, 'failed_handoff', $request->user()->id, 'resubmitted');

        $intakeDocument->forceFill([
            'status' => IntakeDocument::STATUS_SENDING,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
            'submission_count' => $intakeDocument->submission_count + 1,
        ])->save();

        // Hand off immediately so the status advances without depending on a
        // running queue worker; fall back to the queue (retry-with-backoff) if
        // ghelpdesk is briefly unreachable.
        try {
            \App\Jobs\SubmitDocumentReviewJob::dispatchSync($intakeDocument->id);
        } catch (\Throwable $e) {
            \App\Jobs\SubmitDocumentReviewJob::dispatch($intakeDocument->id)->onQueue(config('queue.portal'));
        }
        AuditLogger::log('intake_document_submitted', $intakeDocument);

        return redirect()->back()->with('success', 'Document sent to Accounting for review.');
    }

    /** Assign vendor / document type for unmatched or unclassified documents. */
    public function classify(Request $request, IntakeDocument $intakeDocument)
    {
        abort_unless($request->user()->can('document-intake.validate'), 403);

        $validated = $request->validate([
            'vendor_id' => 'nullable|exists:portal_vendors,id',
            'document_type' => 'nullable|in:invoice,purchase_order,quotation',
            'remember_sender' => 'nullable|boolean',
        ]);

        $this->intake->assignVendorAndType(
            $intakeDocument,
            $validated['vendor_id'] ?? null,
            $validated['document_type'] ?? null,
            $request->user()->id,
        );

        // Vendor/type may have changed the matching basis — keep the link honest.
        $intakeDocument->refresh()->resolveMatchedPo();

        if (($validated['remember_sender'] ?? false) && $intakeDocument->vendor_id && $intakeDocument->inboundEmail) {
            \App\Models\VendorIntakeEmail::firstOrCreate(
                ['type' => 'email', 'value' => strtolower($intakeDocument->inboundEmail->from_email)],
                ['vendor_id' => $intakeDocument->vendor_id, 'created_by' => $request->user()->id],
            );
        }

        return redirect()->back()->with('success', 'Document classified.');
    }
}
