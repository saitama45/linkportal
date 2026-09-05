<?php

namespace App\Http\Controllers\Concerns;

use App\Models\StockIn;
use App\Models\Store;
use Closure;
use Illuminate\Support\Collection;

/**
 * Port of the LINK HUB trait of the same name. Reward redemption deducts
 * specific, individually coded stock units, and "which units are at this
 * store right now" is a question the hub already answers one particular way —
 * this keeps the portal's answer identical rather than inventing a second one.
 */
trait LocatesInventoryUnits
{
    /** Resolve a store code or name down to its canonical store code. */
    protected function normalizeStoreCode(?string $value): ?string
    {
        if (! $value) {
            return $value;
        }

        $store = Store::query()
            ->where('code', $value)
            ->orWhere('name', $value)
            ->first(['code']);

        return $store?->code ?? $value;
    }

    /**
     * The location strings that mean the same physical place, because the
     * ledger's `location` column is denormalised text (sometimes a code,
     * sometimes a name).
     */
    protected function locationVariants(?string $code): array
    {
        if (! $code) {
            return [];
        }

        $variants = [$code];

        $store = Store::query()
            ->where('code', $code)
            ->orWhere('name', $code)
            ->first(['code', 'name']);

        if ($store) {
            if ($store->code && ! in_array($store->code, $variants, true)) {
                $variants[] = $store->code;
            }
            if ($store->name && ! in_array($store->name, $variants, true)) {
                $variants[] = $store->name;
            }
        }

        return $variants;
    }

    /**
     * Posted StockIn unit rows whose CURRENT location is one of the variants.
     * Current location = destination of the latest "Received" transfer, else the
     * original StockIn destination.
     *
     * @return Collection<int, StockIn>
     */
    protected function fixedUnitsCurrentlyAt(array $locationVariants, ?Closure $stockInQuery = null): Collection
    {
        if (empty($locationVariants)) {
            return collect();
        }

        $query = StockIn::query()
            ->where('stock_ins.status', 'Posted')
            ->with(['sourceStockTransfers' => function ($q) {
                $q->whereIn('status', ['For Posting', 'Posted', 'Received'])
                    ->select('id', 'source_stock_in_id', 'transfer_no', 'status', 'destination_location')
                    ->orderByDesc('id');
            }])
            ->orderBy('serial_no');

        if ($stockInQuery) {
            $stockInQuery($query);
        }

        return $query->get()
            ->filter(function (StockIn $unit) use ($locationVariants) {
                $lastReceived = $unit->sourceStockTransfers->firstWhere('status', 'Received');

                if ($lastReceived) {
                    return in_array($lastReceived->destination_location, $locationVariants, true);
                }

                return in_array($unit->destination_location, $locationVariants, true);
            })
            ->values();
    }
}
