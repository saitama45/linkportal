<?php

namespace App\Http\Services;

use App\Models\EmailOtp;
use App\Models\Vendor;
use App\Notifications\VendorEmailOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the 6-digit code a vendor must enter before their account
 * can be used. Codes are single-use, expire quickly, and are stored hashed.
 */
class EmailOtpService
{
    public const LENGTH = 6;
    public const TTL_MINUTES = 10;
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Replace any outstanding code with a fresh one and email it.
     * Returns null when the previous code is still within its resend cooldown.
     */
    public static function issue(Vendor $vendor): ?EmailOtp
    {
        return DB::transaction(function () use ($vendor) {
            $latest = self::outstanding($vendor)->latest('id')->first();

            if ($latest && $latest->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
                return null;
            }

            // A newly issued code invalidates every earlier one.
            self::outstanding($vendor)->update(['consumed_at' => now()]);

            // random_int, not rand(): this gates account access.
            $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

            $otp = EmailOtp::create([
                'vendor_id' => $vendor->id,
                'email' => $vendor->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            $vendor->notify(new VendorEmailOtp($code, self::TTL_MINUTES));

            return $otp;
        });
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    public static function verify(Vendor $vendor, string $code): array
    {
        $otp = self::outstanding($vendor)->latest('id')->first();

        if (! $otp) {
            return ['ok' => false, 'message' => 'That code has expired. Request a new one.'];
        }

        if ($otp->attempts >= EmailOtp::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => 'Too many incorrect attempts. Request a new code.'];
        }

        // Count the attempt before checking, so a crash mid-check cannot be used
        // to retry for free.
        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            $left = EmailOtp::MAX_ATTEMPTS - $otp->attempts;

            return [
                'ok' => false,
                'message' => $left > 0
                    ? "That code is incorrect. {$left} attempt".($left === 1 ? '' : 's').' remaining.'
                    : 'Too many incorrect attempts. Request a new code.',
            ];
        }

        // The code was issued to an address the vendor may since have changed.
        if ($otp->email !== $vendor->email) {
            return ['ok' => false, 'message' => 'Your email changed since this code was sent. Request a new one.'];
        }

        DB::transaction(function () use ($otp, $vendor) {
            $otp->update(['consumed_at' => now()]);
            $vendor->forceFill(['email_verified_at' => now()])->save();
        });

        return ['ok' => true, 'message' => null];
    }

    public static function secondsUntilResend(Vendor $vendor): int
    {
        $latest = self::outstanding($vendor)->latest('id')->first();

        if (! $latest) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN_SECONDS - $latest->created_at->diffInSeconds(now()));
    }

    private static function outstanding(Vendor $vendor)
    {
        return EmailOtp::where('vendor_id', $vendor->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }
}
