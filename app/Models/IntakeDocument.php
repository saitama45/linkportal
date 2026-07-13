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
        'invoice_no', 'po_number', 'document_date', 'due_date', 'currency',
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
