<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class Asset extends Model
{
    protected $fillable = ['item_code', 'brand', 'model', 'description', 'cost', 'type', 'is_active'];

    protected $casts = [
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function stockIns()
    {
        return $this->hasMany(StockIn::class);
    }
}
