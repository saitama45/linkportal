<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class StockReceiving extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'receiving_no',
        'receiving_date',
        'origin_location',
        'destination_location',
        'asset_id',
        'stock_pack_id',
        'source_stock_in_id',
        'serial_no',
        'barcode',
        'qrcode',
        'asset_type',
        'is_allocation',
        'warranty_months',
        'eol_months',
        'cost',
        'price',
        'transferred_quantity',
        'received_quantity',
        'condition',
        'damage_notes',
        'status',
        'received_by',
        'received_at',
        'remarks',
        'dr_image_path',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stock_transfer_id' => 'integer',
        'asset_id' => 'integer',
        'stock_pack_id' => 'integer',
        'source_stock_in_id' => 'integer',
        'is_allocation' => 'boolean',
        'transferred_quantity' => 'integer',
        'received_quantity' => 'integer',
        'receiving_date' => 'date:Y-m-d',
        'received_at' => 'datetime',
        'warranty_months' => 'integer',
        'eol_months' => 'integer',
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /** The bulk unit (box/pack) this piece was sent in, if any. */
    public function pack()
    {
        return $this->belongsTo(StockPack::class, 'stock_pack_id');
    }
}
