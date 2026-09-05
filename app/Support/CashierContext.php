<?php

namespace App\Support;

use App\Models\Store;
use App\Models\Vendor;

/**
 * The one place that answers "which store is this portal session working in?".
 *
 * A Cashier is a vendor-portal login whose `vendors.store_id` binds it to a
 * single outlet, assigned in the back office on /vendors. Everything the
 * Campaigns module reads or writes is scoped through here, so there is exactly
 * one definition of that boundary rather than a `where` clause repeated in
 * every query.
 */
class CashierContext
{
    public static function vendor(): ?Vendor
    {
        $vendor = auth('vendor')->user();

        return $vendor instanceof Vendor ? $vendor : null;
    }

    /** True when this session may use Campaigns at all. */
    public static function isCashier(): bool
    {
        $vendor = self::vendor();

        return $vendor !== null && $vendor->isCashier() && $vendor->store_id !== null;
    }

    /**
     * The bound store. Aborts rather than returning null: every Campaigns route
     * runs behind the cashier middleware, so a missing store here means the
     * guard was bypassed, not that the page should quietly show nothing.
     */
    public static function store(): Store
    {
        $vendor = self::vendor();

        abort_unless($vendor?->store_id, 403, 'This portal account is not assigned to a store.');

        $store = Store::find($vendor->store_id);

        abort_unless($store, 403, 'The store assigned to this account no longer exists.');

        return $store;
    }

    public static function storeId(): int
    {
        return (int) self::store()->id;
    }

    /**
     * The entity that owns the store. Programs and voucher batches are scoped by
     * company, exactly as the hub scopes them to its active entity.
     */
    public static function companyId(): ?int
    {
        return self::store()->company_id ? (int) self::store()->company_id : null;
    }
}
