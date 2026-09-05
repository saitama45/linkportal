<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the trading side of the portal — accreditation documents, document
 * uploads and payments.
 *
 * A Cashier is a store till, not a supplier: it never gets accredited, never
 * submits an invoice and is never paid through accounts payable. Hiding those
 * nav items is not a boundary, so the routes are refused here too.
 *
 * Deliberately keyed on the vendor type alone, not CashierContext: a cashier
 * whose store has not been assigned yet is still not a supplier.
 */
class EnsureVendorIsNotCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = $request->user('vendor');

        if ($vendor instanceof Vendor && $vendor->isCashier()) {
            if ($request->expectsJson()) {
                abort(403, 'This section is not available to cashier accounts.');
            }

            return redirect()
                ->route('vendor.dashboard')
                ->with('error', 'That section is for supplier and partner accounts.');
        }

        return $next($request);
    }
}
