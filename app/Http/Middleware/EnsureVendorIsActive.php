<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended/deactivated vendor accounts. Vendors in 'pending' status may
 * still access onboarding areas (profile, documents) but routes wrapped with
 * this middleware require a fully active account.
 */
class EnsureVendorIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = auth('vendor')->user();

        if (! $vendor || ! $vendor->isActive()) {
            return redirect()
                ->route('vendor.dashboard')
                ->with('error', 'Your account must be approved and active to access this area.');
        }

        return $next($request);
    }
}
