<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class StampRedemption extends Model
{
    protected $fillable = [
        'stamp_card_id', 'customer_id', 'stamp_program_id', 'asset_id', 'location',
        'quantity', 'inventory_transaction_id', 'remarks', 'created_by', 'updated_by',
        'cashier_vendor_id',
    ];

    protected $casts = [
        'stamp_card_id' => 'integer',
        'customer_id' => 'integer',
        'stamp_program_id' => 'integer',
        'asset_id' => 'integer',
        'quantity' => 'integer',
        'inventory_transaction_id' => 'integer',
    ];

    public function card()
    {
        return $this->belongsTo(StampCard::class, 'stamp_card_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function program()
    {
        return $this->belongsTo(StampProgram::class, 'stamp_program_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function units()
    {
        return $this->hasMany(StampRedemptionUnit::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashierVendor()
    {
        return $this->belongsTo(Vendor::class, 'cashier_vendor_id');
    }
}
