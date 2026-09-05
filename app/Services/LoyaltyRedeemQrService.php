<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Decodes the signed redemption code a member's mobile app shows when they tap
 * "Redeem Now". Port of the LINK HUB's service of the same name, and subject to
 * the same shared-`APP_KEY` requirement described in {@see LoyaltyQrService}.
 *
 * The code is keyed on a STAMP CARD, so it authorises one specific full card.
 * It carries no expiry: the card's own status is what stops a screenshotted
 * code being redeemed twice, and that check belongs to the caller.
 *
 * Format: "LRDM1:{stamp_card_id}:{signature}".
 */
class LoyaltyRedeemQrService
{
    private const PREFIX = 'LRDM1';

    /** The stamp card id if the payload is well-formed and signed, else null. */
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

    private static function sign(int $cardId): string
    {
        return substr(
            hash_hmac('sha256', self::PREFIX.':'.$cardId, Config::get('app.key')),
            0,
            24
        );
    }
}
