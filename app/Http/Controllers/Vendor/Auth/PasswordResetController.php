<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Models\Vendor;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Self-service password reset for the vendor guard. Runs on the `vendors`
 * broker, which has its own token table and a provider that resolves only rows
 * holding portal credentials — a back-office reference vendor can never be
 * turned into a login account through this flow.
 */
class PasswordResetController extends Controller
{
    private const BROKER = 'vendors';

    public function showRequest()
    {
        return Inertia::render('Vendor/Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);

        Password::broker(self::BROKER)->sendResetLink($request->only('email'));

        // The outcome is deliberately not reflected back: a different message
        // for a known address would turn this screen into a vendor-email oracle.
        return back()->with(
            'status',
            'If that address belongs to a vendor account, a reset link is on its way.'
        );
    }

    public function showReset(Request $request, string $token)
    {
        return Inertia::render('Vendor/Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|string|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Vendor $vendor) use ($request) {
                $vendor->forceFill([
                    'password' => $request->password,
                    'remember_token' => Str::random(60),
                    // Reaching the emailed link proves the address, which is the
                    // same thing the registration OTP proves. Verifying it here
                    // keeps a vendor from being bounced straight back into OTP.
                    'email_verified_at' => $vendor->email_verified_at ?? now(),
                ])->save();

                AuditLogger::log('vendor_password_reset', $vendor);

                event(new PasswordReset($vendor));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('vendor.login')
            ->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
