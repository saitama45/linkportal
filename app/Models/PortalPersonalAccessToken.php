<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token model on a portal-prefixed table. The shared SQL Server DB
 * already contains ghelpdesk's personal_access_tokens; using our own table
 * prevents tokens issued by one app from authenticating against the other.
 */
class PortalPersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'portal_personal_access_tokens';
}
