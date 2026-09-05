<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class VoucherRedemption extends Model
{
    protected $fillable = [
        'voucher_id', 'customer_id', 'store_id', 'receipt_number', 'sale_date',
        'gross_sale_total', 'applied_amount', 'forfeited_amount', 'redeemed_at',
        'redeemed_by', 'cashier_vendor_id', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'sale_date' => 'date:Y-m-d',
        'gross_sale_total' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'forfeited_amount' => 'decimal:2',
        'redeemed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    public function cashierVendor()
    {
        return $this->belongsTo(Vendor::class, 'cashier_vendor_id');
    }
}
