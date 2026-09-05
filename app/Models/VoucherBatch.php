<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class VoucherBatch extends Model
{
    protected $appends = ['effective_status'];

    protected $fillable = [
        'company_id', 'partner_name', 'title', 'description', 'quantity', 'face_value',
        'turnover_date', 'claim_starts_on', 'claim_ends_on', 'claim_instructions',
        'short_terms', 'partner_logo_path', 'status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'quantity' => 'integer',
        'face_value' => 'decimal:2',
        'turnover_date' => 'date:Y-m-d',
        'claim_starts_on' => 'date:Y-m-d',
        'claim_ends_on' => 'date:Y-m-d',
        'activated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    /** Mirrors the hub's VoucherBatch::effectiveStatus(); keep the two identical. */
    public function effectiveStatus(): string
    {
        $status = $this->attributes['status'] ?? null;
        if (! is_string($status) || $status === '') {
            return 'unknown';
        }
        if ($status !== 'active') {
            return $status;
        }
        if (! array_key_exists('claim_starts_on', $this->attributes) || ! array_key_exists('claim_ends_on', $this->attributes)) {
            return 'unknown';
        }

        $today = now()->toDateString();
        if ($this->claim_starts_on && $today < $this->claim_starts_on->format('Y-m-d')) {
            return 'not_yet_valid';
        }
        if ($this->claim_ends_on && $today > $this->claim_ends_on->format('Y-m-d')) {
            return 'expired';
        }

        return 'active';
    }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->effectiveStatus();
    }
}
