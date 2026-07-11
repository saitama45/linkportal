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
        $rows = $this->lineItemsForProjection();

        DB::transaction(function () use ($rows) {
            $this->lineItems()->delete();

            foreach ($rows as $index => $row) {
                $this->lineItems()->create([
                    'line_no' => $index + 1,
                    'description' => isset($row['description']) ? mb_substr((string) $row['description'], 0, 500) : null,
                    'quantity' => is_numeric($row['quantity'] ?? null) ? $row['quantity'] : null,
                    'uom' => isset($row['uom']) && $row['uom'] !== '' ? mb_substr((string) $row['uom'], 0, 50) : null,
                    'unit_price' => is_numeric($row['unit_price'] ?? null) ? $row['unit_price'] : null,
                    'line_total' => is_numeric($row['line_total'] ?? null) ? $row['line_total'] : null,
                ]);
            }
        });
    }

    /** Normalize either shape (validated JSON or raw extraction cells) to flat rows. */
    private function lineItemsForProjection(): array
    {
        if (! empty($this->validated_line_items)) {
            return $this->validated_line_items;
        }

        $extraction = $this->relationLoaded('latestExtraction')
            ? $this->latestExtraction
            : $this->latestExtraction()->first();

        return collect($extraction?->line_items ?? [])->map(fn ($row) => [
            'description' => $row['cells']['description']['value'] ?? null,
            'quantity' => $row['cells']['quantity']['value'] ?? null,
            'uom' => $row['cells']['uom']['value'] ?? null,
            'unit_price' => $row['cells']['unit_price']['value'] ?? null,
            'line_total' => $row['cells']['line_total']['value'] ?? null,
        ])->all();
    }
}
