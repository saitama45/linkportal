<?php

namespace App\Http\Middleware;

use App\Support\CashierContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Campaigns module. Hiding the nav item is not a boundary — a
 * supplier who types /vendor/campaigns must be refused, so every Campaigns
 * route sits behind this as well as `vendor.active`.
 *
 * A cashier with no assigned store is refused too: without one there is no
 * scope for anything the module reads or writes.
 */
class EnsureVendorIsCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CashierContext::isCashier()) {
            if ($request->expectsJson()) {
                abort(403, 'This area requires a cashier account assigned to a store.');
            }

            return redirect()
                ->route('vendor.dashboard')
                ->with('error', 'This area is available to cashier accounts assigned to a store.');
        }

        return $next($request);
    }
}
