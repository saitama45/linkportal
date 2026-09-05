<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class InventoryTransaction extends Model
{
    protected $fillable = [
        'asset_id', 'location', 'transaction_type', 'quantity',
        'reference_type', 'reference_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'quantity' => 'integer',
        'reference_id' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The hub's ledger rules, copied verbatim. `reference_type` stores fully
     * qualified class names, and both apps namespace these models `App\Models`,
     * so ::class comparisons resolve to the same strings the hub wrote.
     */
    public function scopeValidInventoryLedger(
        Builder $query,
        string $table = 'inventory_transactions',
        string $aliasPrefix = 'valid_inventory'
    ): Builder {
        $stockIns = "{$aliasPrefix}_stock_ins";
        $stockReceivings = "{$aliasPrefix}_stock_receivings";
        $stockTransfers = "{$aliasPrefix}_stock_transfers";

        return $query
            ->leftJoin("stock_ins as {$stockIns}", function ($join) use ($table, $stockIns) {
                $join->on("{$table}.reference_id", '=', "{$stockIns}.id")
                    ->where("{$table}.reference_type", '=', StockIn::class);
            })
            ->leftJoin("stock_receivings as {$stockReceivings}", function ($join) use ($table, $stockReceivings) {
                $join->on("{$table}.reference_id", '=', "{$stockReceivings}.id")
                    ->where("{$table}.reference_type", '=', StockReceiving::class);
            })
            ->leftJoin("stock_transfers as {$stockTransfers}", function ($join) use ($table, $stockTransfers) {
                $join->on("{$table}.reference_id", '=', "{$stockTransfers}.id")
                    ->where("{$table}.reference_type", '=', StockTransfer::class);
            })
            ->where(function ($query) use ($table, $stockIns, $stockReceivings, $stockTransfers) {
                $query->where(function ($query) use ($table, $stockIns) {
                    $query->where("{$table}.reference_type", StockIn::class)
                        ->where("{$stockIns}.status", 'Posted');
                })->orWhere(function ($query) use ($table, $stockReceivings) {
                    $query->where("{$table}.reference_type", StockReceiving::class)
                        ->where("{$table}.transaction_type", 'Transfer In')
                        ->where("{$stockReceivings}.status", 'Received');
                })->orWhere(function ($query) use ($table, $stockReceivings) {
                    $query->where("{$table}.reference_type", StockReceiving::class)
                        ->where("{$table}.transaction_type", 'Receiving Declined')
                        ->where("{$stockReceivings}.status", 'Declined');
                })->orWhere(function ($query) use ($table, $stockTransfers) {
                    $query->where("{$table}.reference_type", StockTransfer::class)
                        ->where("{$table}.transaction_type", 'Transfer Out')
                        ->whereIn("{$stockTransfers}.status", ['Posted', 'Received', 'Declined']);
                })->orWhere(function ($query) use ($table) {
                    $query->where("{$table}.reference_type", StampRedemption::class)
                        ->where("{$table}.transaction_type", 'Stamp Redemption');
                });
            });
    }
}
