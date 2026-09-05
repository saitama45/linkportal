<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Decodes the signed member code printed on a customer's loyalty card QR.
 *
 * A byte-for-byte port of the LINK HUB's service of the same name — the two
 * MUST agree, because the hub (and the member's mobile app) issue the codes the
 * portal scans. That agreement rests on both apps sharing one `APP_KEY`: the
 * signature is a truncated HMAC-SHA256 keyed on it, so a portal deployed with
 * its own key would reject every genuine member QR.
 *
 * Format: "LCARD1:{customer_id}:{signature}".
 */
class LoyaltyQrService
{
    private const PREFIX = 'LCARD1';

    /** The customer id if the payload is well-formed and signed, else null. */
    public static function decode(string $payload): ?int
    {
        $parts = explode(':', trim($payload));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        [, $rawId, $signature] = $parts;

        if (! ctype_digit($rawId)) {
            return null;
        }

        $id = (int) $rawId;

        return hash_equals(self::sign($id), $signature) ? $id : null;
    }

    private static function sign(int $customerId): string
    {
        return substr(
            hash_hmac('sha256', self::PREFIX.':'.$customerId, Config::get('app.key')),
            0,
            24
        );
    }
}
