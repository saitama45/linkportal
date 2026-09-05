<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Laravel names the CSRF cookie "XSRF-TOKEN" in every application, and browsers
 * scope cookies by host only — the port is not part of a cookie's identity.
 *
 * This portal runs alongside the back office (ghelpdesk) on the same host during
 * development (127.0.0.1:8002 and :8000) and both wrote that one shared name, so
 * loading either app silently overwrote the other's token. The session cookies
 * are already named per-app, so each app still found its own session — but the
 * CSRF token in it belonged to the other, which is a guaranteed 419.
 *
 * Deriving the cookie name from the (already unique) session cookie name keeps
 * the two apps from ever colliding again, with no extra configuration to keep
 * in sync. The request HEADER stays X-XSRF-TOKEN: headers are per-request, not
 * per-host, so it never collided.
 */
class ValidateCsrfToken extends Middleware
{
    /**
     * The cookie name for this app, e.g. "link-portal-session" => "link-portal-xsrf".
     */
    public static function cookieName(): string
    {
        $session = config('session.cookie') ?: 'laravel';

        return preg_replace('/[_-]?session$/', '', $session).'-xsrf';
    }

    protected function newCookie($request, $config)
    {
        return new Cookie(
            self::cookieName(),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }
}
