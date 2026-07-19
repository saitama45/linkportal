<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class IntakeDocument extends Model
{
    use SoftDeletes;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_CONVERTING = 'converting';
    public const STATUS_CONVERSION_FAILED = 'conversion_failed';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_EXTRACTION_FAILED = 'extraction_failed';
    public const STATUS_NEEDS_VALIDATION = 'needs_validation';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_SENDING = 'sending';
    public const STATUS_HANDOFF_FAILED = 'handoff_failed';
    public const STATUS_PENDING_EXTERNAL_REVIEW = 'pending_external_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const DOCUMENT_TYPES = ['invoice', 'purchase_order', 'quotation'];

    /** Standard line-item columns used when a document has no template. */
    public const DEFAULT_LINE_COLUMNS = [
        ['key' => 'description', 'label' => 'Description'],
        ['key' => 'quantity', 'label' => 'Quantity'],
        ['key' => 'uom', 'label' => 'UOM'],
        ['key' => 'unit_price', 'label' => 'Unit Price'],
        ['key' => 'line_total', 'label' => 'Line Total'],
    ];

    protected $table = 'portal_intake_documents';

    protected $fillable = [
        'reference_no', 'vendor_id', 'company_id', 'document_type', 'source',
        'inbound_email_id', 'original_filename', 'mime_type', 'file_size',
        'file_hash', 'file_path', 'converted_pdf_path', 'page_count', 'page_meta',
        'status', 'template_version_id',
        'invoice_no', 'po_number', 'matched_po_intake_document_id',
        'document_date', 'due_date', 'currency',
        'subtotal', 'tax_amount', 'total_amount',
        'validated_fields', 'validated_line_items', 'overall_confidence',
        'validated_by', 'validated_at', 'submitted_by', 'submitted_at', 'submission_count',
        'external_review_id', 'external_status', 'external_decision',
        'external_decision_remarks', 'external_reviewer_name', 'external_decided_at',
        'transaction_type', 'transaction_id', 'uploaded_by_vendor_user',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'company_id' => 'integer',
            'inbound_email_id' => 'integer',
            'template_version_id' => 'integer',
            'matched_po_intake_document_id' => 'integer',
            'page_meta' => 'array',
            'document_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'validated_fields' => 'array',
            'validated_line_items' => 'array',
            'overall_confidence' => 'decimal:4',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'external_decided_at' => 'datetime',
            'uploaded_by_vendor_user' => 'boolean',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function inboundEmail()
    {
        return $this->belongsTo(InboundEmail::class);
    }

    public function templateVersion()
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'template_version_id');
    }

    public function extractions()
    {
        return $this->hasMany(DocumentExtraction::class)->orderByDesc('attempt_no');
    }

    public function latestExtraction()
    {
        return $this->hasOne(DocumentExtraction::class)->latestOfMany('attempt_no');
    }

    public function exceptions()
    {
        return $this->hasMany(DocumentException::class);
    }

    public function openExceptions()
    {
        return $this->exceptions()->where('status', 'open');
    }

    public function events()
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('created_at');
    }

    public function lineItems()
    {
        return $this->hasMany(IntakeLineItem::class)->orderBy('line_no');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Approved purchase orders that nothing has been billed against yet.
     *
     * A PO and the invoice that bills it are related only by a (vendor,
     * po_number) string match — there is no persisted link between the two
     * documents — so "has an invoice" is derived rather than stored. Cancelled
     * and rejected invoices don't count as billed.
     */
    public function scopeApprovedPoAwaitingInvoice($query)
    {
        $table = $this->getTable();

        return $query->where('document_type', 'purchase_order')
            ->where('status', self::STATUS_APPROVED)
            ->whereNotNull('po_number')
            ->whereNotExists(function ($sub) use ($table) {
                // Raw builder: the SoftDeletes global scope doesn't reach in here,
                // so deleted_at is filtered explicitly.
                $sub->selectRaw('1')
                    ->from("{$table} as inv")
                    ->whereColumn('inv.po_number', "{$table}.po_number")
                    ->whereColumn('inv.vendor_id', "{$table}.vendor_id")
                    ->where('inv.document_type', 'invoice')
                    ->whereNull('inv.deleted_at')
                    ->whereNotIn('inv.status', [self::STATUS_CANCELLED, self::STATUS_REJECTED]);
            });
    }

    // ---- PO ↔ Invoice matching (derived by vendor + po_number, not persisted) ----

    /** Invoices that count as billed: not cancelled, not rejected. */
    private const BILLING_INVOICE_STATUSES_EXCLUDED = [self::STATUS_CANCELLED, self::STATUS_REJECTED];

    /** The PO this invoice was matched to at validation time (persisted cache). */
    public function matchedPoDocument()
    {
        return $this->belongsTo(self::class, 'matched_po_intake_document_id');
    }

    /**
     * The approved purchase order this invoice bills against. Prefers the link
     * persisted at validation time (stable if the PO's number is later edited);
     * falls back to deriving by (vendor, po_number) for invoices not yet
     * validated. Returns a model, not a relation.
     */
    public function matchedPo(): ?self
    {
        if ($this->document_type !== 'invoice') {
            return null;
        }

        if ($this->matched_po_intake_document_id) {
            $po = $this->matchedPoDocument()->first();
            if ($po) {
                return $po;
            }
            // Linked PO was deleted — fall through to re-derive.
        }

        return $this->deriveMatchedPo();
    }

    /** Resolve the matching approved PO purely from (vendor, po_number). */
    public function deriveMatchedPo(): ?self
    {
        if ($this->document_type !== 'invoice' || ! $this->vendor_id || ! $this->po_number) {
            return null;
        }

        return static::query()
            ->where('document_type', 'purchase_order')
            ->where('vendor_id', $this->vendor_id)
            ->where('po_number', $this->po_number)
            ->where('status', self::STATUS_APPROVED)
            ->latest('external_decided_at')
            ->first();
    }

    /**
     * Persist (or clear) this invoice's matched-PO link from the current
     * (vendor, po_number). Idempotent — call whenever the PO number or
     * validation state changes. No-op for non-invoices. Returns the resolved PO.
     */
    public function resolveMatchedPo(): ?self
    {
        if ($this->document_type !== 'invoice') {
            return null;
        }

        $po = $this->deriveMatchedPo();
        $newId = $po?->id;

        if ($this->matched_po_intake_document_id !== $newId) {
            $this->forceFill(['matched_po_intake_document_id' => $newId])->save();
        }

        return $po;
    }

    /**
     * Every invoice billed against this purchase order (this doc must be a PO),
     * excluding cancelled/rejected. Optionally excludes one invoice id — used
     * when evaluating an invoice that isn't persisted with its final values yet.
     */
    public function invoicesForPo(?int $excludeInvoiceId = null)
    {
        $query = static::query()
            ->where('document_type', 'invoice')
            ->where('vendor_id', $this->vendor_id)
            ->where('po_number', $this->po_number)
            ->whereNotIn('status', self::BILLING_INVOICE_STATUSES_EXCLUDED);

        if ($excludeInvoiceId !== null) {
            $query->where('id', '!=', $excludeInvoiceId);
        }

        return $query;
    }

    /** Sum of totals already billed against this PO (excludes cancelled/rejected). */
    public function invoicedToDate(?int $excludeInvoiceId = null): float
    {
        return (float) $this->invoicesForPo($excludeInvoiceId)->sum('total_amount');
    }

    /** PO total minus what has been billed. Null when the PO has no total. */
    public function remainingBalance(): ?float
    {
        if ($this->total_amount === null) {
            return null;
        }

        return round((float) $this->total_amount - $this->invoicedToDate(), 2);
    }

    /**
     * Fulfillment of this PO derived from matched invoice totals:
     * 'open' (nothing billed), 'partially_invoiced', or 'fully_invoiced'.
     * $tolerance absorbs rounding when deciding "fully" (fraction of PO total).
     */
    public function fulfillmentStatus(float $tolerance = 0.01): string
    {
        $poTotal = (float) ($this->total_amount ?? 0);
        $billed = $this->invoicedToDate();

        if ($billed <= 0) {
            return 'open';
        }
        if ($poTotal <= 0) {
            return 'partially_invoiced'; // something billed against a zero/absent PO total
        }

        return $billed >= $poTotal * (1 - $tolerance) ? 'fully_invoiced' : 'partially_invoiced';
    }

    /**
     * Whether this approved PO has passed its validity window with billing still
     * open. $validityDays comes from the po_expired rule config; null/0 means
     * POs never expire (the default), so nothing is ever expired.
     */
    public function isExpired(?int $validityDays): bool
    {
        if (! $validityDays || $validityDays <= 0
            || $this->document_type !== 'purchase_order'
            || $this->status !== self::STATUS_APPROVED
            || ! $this->external_decided_at) {
            return false;
        }

        // A fully-invoiced PO has nothing left to bill, so expiry is moot.
        if ($this->fulfillmentStatus() === 'fully_invoiced') {
            return false;
        }

        return $this->external_decided_at->lt(now()->subDays($validityDays));
    }

    /** Days configured before an approved PO expires; null = never. */
    public static function poValidityDays(): ?int
    {
        $rule = DocumentExceptionRule::where('rule_key', 'po_expired')->first();
        if (! $rule || ! $rule->enabled) {
            return null;
        }
        $days = (int) $rule->configValue('validity_days', 0);

        return $days > 0 ? $days : null;
    }

    public function hasOpenBlockers(): bool
    {
        return $this->openExceptions()->where('severity', 'blocker')->exists();
    }

    public function isPdf(): bool
    {
        return str_ends_with(strtolower($this->original_filename), '.pdf');
    }

    public function canBeValidated(): bool
    {
        return in_array($this->status, [self::STATUS_NEEDS_VALIDATION, self::STATUS_VALIDATED, self::STATUS_RETURNED]);
    }

    public function canBeSubmitted(): bool
    {
        return $this->status === self::STATUS_VALIDATED && ! $this->hasOpenBlockers();
    }

    public function transitionTo(string $status): void
    {
        $this->forceFill(['status' => $status])->save();
    }

    public function recordEvent(string $event, ?string $notes = null, array $meta = [], ?string $actorType = null, ?int $actorId = null): DocumentEvent
    {
        return $this->events()->create([
            'event' => $event,
            'actor_type' => $actorType ?? 'system',
            'actor_id' => $actorId,
            'notes' => $notes,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Rebuild the flat portal_intake_line_items projection from this document's
     * line items — the corrected snapshot (validated_line_items) when present,
     * otherwise the latest raw extraction. Idempotent: replaces the doc's rows.
     */
    public function syncLineItems(): void
    {
        $rows = $this->resolvedLineItems();
        $standard = ['description', 'quantity', 'uom', 'unit_price', 'line_total'];

        DB::transaction(function () use ($rows, $standard) {
            $this->lineItems()->delete();

            foreach ($rows as $index => $row) {
                // Custom / unlimited template columns can't each be a fixed SQL
                // column — keep the standard five flat and stash the rest as JSON
                // so they're still reportable via JSON_VALUE(extra, '$.<key>').
                $extra = collect($row)
                    ->except($standard)
                    ->reject(fn ($v) => $v === null || $v === '')
                    ->all();

                $this->lineItems()->create([
                    'line_no' => $index + 1,
                    'description' => isset($row['description']) ? mb_substr((string) $row['description'], 0, 500) : null,
                    'quantity' => is_numeric($row['quantity'] ?? null) ? $row['quantity'] : null,
                    'uom' => isset($row['uom']) && $row['uom'] !== '' ? mb_substr((string) $row['uom'], 0, 50) : null,
                    'unit_price' => is_numeric($row['unit_price'] ?? null) ? $row['unit_price'] : null,
                    'line_total' => is_numeric($row['line_total'] ?? null) ? $row['line_total'] : null,
                    'extra' => $extra ?: null,
                ]);
            }
        });
    }

    /**
     * Line-item column definitions ([{key, label}]) for this document: taken
     * from the template (custom names + unlimited count), falling back to the
     * five standard columns for template-less / manual documents. Drives the
     * accounting handoff headers and the generic extraction mapping.
     */
    public function lineItemColumns(): array
    {
        $tv = $this->relationLoaded('templateVersion') ? $this->templateVersion : $this->templateVersion()->first();
        $cols = data_get($tv, 'annotations.table.columns');

        if (! empty($cols)) {
            return collect($cols)->map(fn ($c) => [
                'key' => $c['key'],
                'label' => $c['label'] ?? self::standardLineLabel($c['key']),
            ])->all();
        }

        return self::DEFAULT_LINE_COLUMNS;
    }

    private static function standardLineLabel(string $key): string
    {
        foreach (self::DEFAULT_LINE_COLUMNS as $col) {
            if ($col['key'] === $key) {
                return $col['label'];
            }
        }

        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * The authoritative line items for this document: the admin-corrected snapshot
     * (validated_line_items) if present, otherwise normalized from the raw
     * extraction. Keyed by the document's line-item columns (custom keys ride
     * through). Used for the flat projection AND the accounting handoff so a
     * document validated without explicit edits still carries its line items.
     */
    public function resolvedLineItems(): array
    {
        if (! empty($this->validated_line_items)) {
            return $this->validated_line_items;
        }

        $extraction = $this->relationLoaded('latestExtraction')
            ? $this->latestExtraction
            : $this->latestExtraction()->first();

        $columns = $this->lineItemColumns();

        return collect($extraction?->line_items ?? [])->map(function ($row) use ($columns) {
            $out = [];
            foreach ($columns as $col) {
                $out[$col['key']] = $row['cells'][$col['key']]['value'] ?? null;
            }

            return $out;
        })->all();
    }

    /**
     * Header field key => value for the accounting handoff: raw extraction values
     * as the base, overridden by the admin-corrected validated_fields. Ensures
     * extraction-only fields (e.g. vendor_address) still travel even when the
     * document was validated without explicit corrections.
     */
    public function resolvedFields(): array
    {
        $extraction = $this->relationLoaded('latestExtraction')
            ? $this->latestExtraction
            : $this->latestExtraction()->first();

        $fromExtraction = collect($extraction?->header_fields ?? [])
            ->filter(fn ($f) => isset($f['key']))
            ->mapWithKeys(fn ($f) => [$f['key'] => $f['value'] ?? null])
            ->all();

        return array_merge($fromExtraction, $this->validated_fields ?? []);
    }
}
