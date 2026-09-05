<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class StampEntry extends Model
{
    protected $fillable = [
        'stamp_card_id', 'store_id', 'quantity', 'source', 'purchase_amount', 'note',
        'created_by', 'cashier_vendor_id',
    ];

    protected $casts = [
        'stamp_card_id' => 'integer',
        'store_id' => 'integer',
        'quantity' => 'integer',
        'purchase_amount' => 'decimal:2',
    ];

    public function card()
    {
        return $this->belongsTo(StampCard::class, 'stamp_card_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
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
