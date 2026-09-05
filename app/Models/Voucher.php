<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class Voucher extends Model
{
    protected $fillable = ['voucher_batch_id', 'code', 'status', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['voided_at' => 'datetime'];

    public function batch()
    {
        return $this->belongsTo(VoucherBatch::class, 'voucher_batch_id');
    }

    public function activeRedemption()
    {
        return $this->hasOne(VoucherRedemption::class)->whereNull('voided_at')->latestOfMany();
    }
}
