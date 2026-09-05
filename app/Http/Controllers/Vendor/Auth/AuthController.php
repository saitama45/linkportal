<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\EmailOtpService;
use App\Http\Services\NumberingService;
use App\Http\Services\PortalNotifier;
use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Vendor/Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->email).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $vendor = Vendor::where('email', $request->email)->first();

        // `vendors` also holds back-office-only reference rows with no password;
        // those are not portal accounts and must never authenticate.
        if (! $vendor || ! $vendor->hasPortalAccess() || ! Hash::check($request->password, $vendor->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($vendor->isLoginBlocked()) {
            throw ValidationException::withMessages([
                'email' => $vendor->status === 'rejected'
                    ? 'Your registration was not approved. Please contact the administrator.'
                    : 'Your account has been suspended. Please contact the administrator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Credentials are correct, but an unverified address must clear the
        // emailed code first. No session is granted until then.
        //
        // Cashiers are exempt for the reason given in register(), and the
        // exemption has to live here too: an account created before the rule
        // changed is still unverified, and would otherwise be stranded at a
        // code screen for a mailbox nobody reads.
        if (! $vendor->hasVerifiedEmail() && $vendor->isCashier()) {
            $vendor->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $vendor->hasVerifiedEmail()) {
            EmailOtpService::issue($vendor);

            $request->session()->put('otp_vendor_id', $vendor->id);

            return redirect()->route('vendor.otp.show')
                ->with('status', 'Enter the 6-digit code we emailed to '.$vendor->email.'.');
        }

        Auth::guard('vendor')->login($vendor, $request->boolean('remember'));
        $request->session()->regenerate();

        $vendor->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('vendor_login', $vendor);

        return redirect()->intended(route('vendor.dashboard'));
    }

    public function showRegister()
    {
        return Inertia::render('Vendor/Auth/Register', [
            // Managed in the back office, not hard-coded here, so a type an
            // admin adds on /vendors is immediately selectable on registration.
            'vendorTypes' => Vendor::types(),
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:vendors,email',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'phone' => 'nullable|string|max:30',
            // Labels are what the form posts and what the shared table stores;
            // the legacy slugs stay accepted because the model still maps them.
            'vendor_type' => [
                'nullable',
                'string',
                'max:50',
                Rule::in([...Vendor::types(), ...array_keys(Vendor::VENDOR_TYPE_LABELS)]),
            ],
        ]);

        // `vendors` is shared with the back office, so a registrant may already
        // exist there as a reference-only record carrying tickets and payment
        // history. Claim that row rather than creating a second one for the same
        // company — its id must survive, since other tables point at it.
        $vendor = DB::transaction(function () use ($validated) {
            $existing = Vendor::whereNull('password')
                ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', [mb_strtolower(trim($validated['name']))])
                ->lockForUpdate()
                ->first();

            $credentials = [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['phone'] ?? null,
                'status' => 'pending',
                'is_active' => false,
                // Unverified until the emailed code is entered.
                'email_verified_at' => null,
            ];

            if ($existing) {
                $existing->update($credentials + [
                    // Keep the back office's own code and vendor_type if it set them.
                    'code' => $existing->code ?: NumberingService::next('vendor'),
                    'vendor_type' => $existing->vendor_type ?: ($validated['vendor_type'] ?? null),
                ]);

                return $existing;
            }

            return Vendor::create($credentials + [
                'code' => NumberingService::next('vendor'),
                'name' => $validated['name'],
                'vendor_type' => $validated['vendor_type'] ?? null,
            ]);
        });

        VendorProfile::firstOrCreate(
            ['vendor_id' => $vendor->id],
            ['approval_status' => 'draft'],
        );

        AuditLogger::log('vendor_registered', $vendor);

        PortalNotifier::notifyUsersWithPermission(
            'vendors.approve',
            null,
            'vendor_registered',
            'New vendor registration',
            "Vendor \"{$vendor->name}\" ({$vendor->code}) registered and awaits account activation.",
            null
        );

        // A cashier is onboarded at the counter, not through a mailbox: the
        // address on a till account is typically a shared store one nobody
        // watches, so an emailed code would lock the account out of itself. The
        // real gate for a cashier is the back office, which still has to
        // activate the account and assign its store before Campaigns opens.
        // Every other vendor type must clear the code as before.
        if ($vendor->isCashier()) {
            $vendor->forceFill(['email_verified_at' => now()])->save();

            Auth::guard('vendor')->login($vendor);
            $request->session()->regenerate();

            AuditLogger::log('vendor_cashier_registered_without_otp', $vendor);

            return redirect()->route('vendor.dashboard')
                ->with('success', 'Account created. An administrator will activate it and assign your store.');
        }

        EmailOtpService::issue($vendor);

        // Deliberately NOT logged in: the account is unusable until verified.
        $request->session()->put('otp_vendor_id', $vendor->id);

        return redirect()->route('vendor.otp.show')
            ->with('status', 'We emailed a 6-digit code to '.$vendor->email.'. Enter it to activate your login.');
    }

    public function showOtp(Request $request)
    {
        $vendor = $this->pendingVendor($request);

        if (! $vendor) {
            return redirect()->route('vendor.login');
        }

        return Inertia::render('Vendor/Auth/VerifyOtp', [
            'email' => $vendor->email,
            'status' => session('status'),
            'resendAvailableIn' => EmailOtpService::secondsUntilResend($vendor),
            'codeLength' => EmailOtpService::LENGTH,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $vendor = $this->pendingVendor($request);

        if (! $vendor) {
            return redirect()->route('vendor.login');
        }

        $request->validate([
            'code' => 'required|string|digits:'.EmailOtpService::LENGTH,
        ]);

        // Independent of the per-code attempt counter: this also caps someone
        // cycling fresh codes to brute-force the space.
        $throttleKey = 'vendor-otp|'.$vendor->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            throw ValidationException::withMessages([
                'code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $result = EmailOtpService::verify($vendor, $request->string('code')->toString());

        if (! $result['ok']) {
            RateLimiter::hit($throttleKey, 900);

            throw ValidationException::withMessages(['code' => $result['message']]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->forget('otp_vendor_id');

        Auth::guard('vendor')->login($vendor);
        $request->session()->regenerate();

        AuditLogger::log('vendor_email_verified', $vendor);

        return redirect()->route('vendor.dashboard')
            ->with('success', 'Email verified. Complete your profile and upload accreditation documents while your account awaits activation.');
    }

    public function resendOtp(Request $request)
    {
        $vendor = $this->pendingVendor($request);

        if (! $vendor) {
            return redirect()->route('vendor.login');
        }

        if (EmailOtpService::issue($vendor) === null) {
            return back()->with('error', 'A code was just sent. Please wait a moment before requesting another.');
        }

        return back()->with('status', 'A new code is on its way to '.$vendor->email.'.');
    }

    /**
     * The vendor awaiting verification. Resolved from the session rather than a
     * URL parameter so the screen cannot be pointed at someone else's account.
     */
    private function pendingVendor(Request $request): ?Vendor
    {
        $id = $request->session()->get('otp_vendor_id');

        if (! $id) {
            return null;
        }

        $vendor = Vendor::whereNotNull('password')->find($id);

        return $vendor && ! $vendor->hasVerifiedEmail() ? $vendor : null;
    }

    public function logout(Request $request)
    {
        Auth::guard('vendor')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vendor.login');
    }
}
