<?php

namespace App\Providers;

use App\Auth\VendorUserProvider;
use App\Models\Company;
use App\Models\PortalPersonalAccessToken;
use App\Policies\CompanyPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }

        Gate::policy(Company::class, CompanyPolicy::class);

        // Shared DB with ghelpdesk: keep API tokens in a portal-prefixed table
        Sanctum::usePersonalAccessTokenModel(PortalPersonalAccessToken::class);

        // `vendors` is shared with the back office, so the vendor guard must only
        // ever resolve rows that actually hold portal credentials.
        Auth::provider('vendors', fn ($app, array $config) => new VendorUserProvider(
            $app['hash'],
            $config['model'],
        ));

        Vite::prefetch(concurrency: 3);
    }
}
