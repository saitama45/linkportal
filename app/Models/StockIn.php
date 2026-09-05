<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class StockIn extends Model
{
    protected $fillable = [
        'source_stock_in_id', 'origin_location', 'destination_location', 'status',
        'asset_id', 'quantity', 'serial_no', 'barcode', 'qrcode',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'source_stock_in_id' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function sourceStockTransfers()
    {
        return $this->hasMany(StockTransfer::class, 'source_stock_in_id');
    }
}
