<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The `vendors` table is shared with the back office and holds two kinds of row:
 * portal login accounts (password set) and reference-only vendors (password
 * null). Only the former may ever be resolved as an authenticated identity.
 *
 * This matters on session resume as much as at login: a session issued before
 * the portal_vendors merge carries the old id, which now names a different
 * vendor entirely. Filtering here means such a session resolves to nobody and
 * the visitor is simply sent back to the login page.
 */
class VendorUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->portalAccounts()
            ->where($this->createModel()->getAuthIdentifierName(), $identifier)
            ->first();
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        $vendor = parent::retrieveByToken($identifier, $token);

        return $vendor && $vendor->hasPortalAccess() ? $vendor : null;
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $vendor = parent::retrieveByCredentials($credentials);

        return $vendor && $vendor->hasPortalAccess() ? $vendor : null;
    }

    protected function portalAccounts()
    {
        return $this->newModelQuery()->whereNotNull('password');
    }
}
