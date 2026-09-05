<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class StampRedemptionUnit extends Model
{
    protected $fillable = ['stamp_redemption_id', 'stock_in_id', 'serial_no', 'barcode', 'qrcode'];

    protected $casts = [
        'stamp_redemption_id' => 'integer',
        'stock_in_id' => 'integer',
    ];

    public function redemption()
    {
        return $this->belongsTo(StampRedemption::class, 'stamp_redemption_id');
    }

    public function stockIn()
    {
        return $this->belongsTo(StockIn::class);
    }
}
