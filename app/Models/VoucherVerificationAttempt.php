<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class VoucherVerificationAttempt extends Model
{
    protected $fillable = [
        'voucher_id', 'scanned_code', 'result', 'store_id', 'verified_by',
        'cashier_vendor_id', 'verified_at',
    ];

    protected $casts = ['verified_at' => 'datetime'];
}
