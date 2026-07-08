<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\PortalNotifier;
use App\Models\ReferenceOption;
use App\Models\VendorContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $vendor = $request->user('vendor');
        $vendor->loadMissing(['profile', 'contacts', 'bankAccounts']);

        return Inertia::render('Vendor/Profile', [
            'vendor' => $vendor,
            'paymentTermsOptions' => ReferenceOption::ofType('payment_terms')->get(),
            'currencyOptions' => ReferenceOption::ofType('currency')->get(),
            'vendorTypeOptions' => ReferenceOption::ofType('vendor_type')->get(),
        ]);
    }

    /**
     * Maker-checker: profile edits are staged into pending_changes and require
     * internal approval before becoming the live profile values.
     */
    public function update(Request $request)
    {
        $vendor = $request->user('vendor');
        $profile = $vendor->profile;

        $validated = $request->validate([
            'legal_name' => 'nullable|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'tin' => 'nullable|string|max:30',
            'rdo_code' => 'nullable|string|max:10',
            'business_type' => 'nullable|string|max:50',
            'vat_type' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',
            'website' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:3',
        ]);

        $profile->forceFill([
            'pending_changes' => $validated,
            'approval_status' => 'pending',
        ])->save();

        AuditLogger::log('profile_change_requested', $profile, null, $validated);

        PortalNotifier::notifyUsersWithPermission(
            'vendors.approve',
            $vendor->company_id,
            'profile_pending',
            'Vendor profile change pending approval',
            "Vendor \"{$vendor->name}\" submitted profile changes for review.",
            null
        );

        return redirect()->back()->with('success', 'Profile changes submitted for approval.');
    }

    public function storeContact(Request $request)
    {
        $vendor = $request->user('vendor');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'is_primary' => 'boolean',
        ]);

        if (! empty($validated['is_primary'])) {
            $vendor->contacts()->update(['is_primary' => false]);
        }

        $vendor->contacts()->create($validated);

        return redirect()->back()->with('success', 'Contact added.');
    }

    public function destroyContact(Request $request, VendorContact $contact)
    {
        abort_unless($contact->vendor_id === $request->user('vendor')->id, 403);

        $contact->delete();

        return redirect()->back()->with('success', 'Contact removed.');
    }

    public function updatePassword(Request $request)
    {
        $vendor = $request->user('vendor');

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($validated['current_password'], $vendor->password)) {
            return redirect()->back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $vendor->forceFill(['password' => $validated['password']])->save();
        AuditLogger::log('vendor_password_changed', $vendor);

        return redirect()->back()->with('success', 'Password updated.');
    }
}
