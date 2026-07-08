<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\NumberingService;
use App\Http\Services\PortalNotifier;
use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

        if (! $vendor || ! Hash::check($request->password, $vendor->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (in_array($vendor->status, ['suspended', 'deactivated'], true)) {
            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended. Please contact the administrator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('vendor')->login($vendor, $request->boolean('remember'));
        $request->session()->regenerate();

        $vendor->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('vendor_login', $vendor);

        return redirect()->intended(route('vendor.dashboard'));
    }

    public function showRegister()
    {
        return Inertia::render('Vendor/Auth/Register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:portal_vendors,email',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'phone' => 'nullable|string|max:30',
            'vendor_type' => 'nullable|string|max:50',
        ]);

        $vendor = Vendor::create([
            'code' => NumberingService::next('vendor'),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['phone'] ?? null,
            'vendor_type' => $validated['vendor_type'] ?? null,
            'status' => 'pending',
            'email_verified_at' => now(),
        ]);

        VendorProfile::create([
            'vendor_id' => $vendor->id,
            'approval_status' => 'draft',
        ]);

        AuditLogger::log('vendor_registered', $vendor);

        PortalNotifier::notifyUsersWithPermission(
            'vendors.approve',
            null,
            'vendor_registered',
            'New vendor registration',
            "Vendor \"{$vendor->name}\" ({$vendor->code}) registered and awaits account activation.",
            null
        );

        Auth::guard('vendor')->login($vendor);
        $request->session()->regenerate();

        return redirect()->route('vendor.dashboard')
            ->with('success', 'Registration successful. Complete your profile and upload accreditation documents while your account awaits activation.');
    }

    public function logout(Request $request)
    {
        Auth::guard('vendor')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vendor.login');
    }
}
