<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 *
 * A box label: scanning its barcode at receiving accounts for every piece of
 * the box that was sent in that receiving.
 */
class StockPack extends Model
{
    protected $fillable = ['asset_id', 'barcode', 'qrcode', 'bulk_uom', 'units_per_pack'];

    protected $casts = [
        'asset_id' => 'integer',
        'units_per_pack' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
