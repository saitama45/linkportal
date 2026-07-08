<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\PortalNotifier;
use App\Models\VendorBankAccount;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function store(Request $request)
    {
        $vendor = $request->user('vendor');

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'branch' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'currency' => 'nullable|string|max:3',
            'is_default' => 'boolean',
        ]);

        $account = $vendor->bankAccounts()->create([
            ...$validated,
            'currency' => $validated['currency'] ?? 'PHP',
            'approval_status' => 'pending', // fraud control: bank details never go live unverified
        ]);

        AuditLogger::log('bank_account_added', $account, null, ['bank' => $account->bank_name]);
        $this->notifyApprovers($vendor, 'added');

        return redirect()->back()->with('success', 'Bank account submitted for verification.');
    }

    /**
     * Fraud control: any change to bank details resets verification back to pending.
     */
    public function update(Request $request, VendorBankAccount $bankAccount)
    {
        $vendor = $request->user('vendor');
        abort_unless($bankAccount->vendor_id === $vendor->id, 403);

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'branch' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'currency' => 'nullable|string|max:3',
            'is_default' => 'boolean',
        ]);

        $before = $bankAccount->only(['bank_name', 'account_name', 'account_number']);

        $bankAccount->update([
            ...$validated,
            'approval_status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_remarks' => null,
        ]);

        AuditLogger::log('bank_account_changed', $bankAccount, $before, $bankAccount->only(['bank_name', 'account_name', 'account_number']));
        $this->notifyApprovers($vendor, 'changed');

        return redirect()->back()->with('success', 'Bank account changes submitted for re-verification.');
    }

    public function destroy(Request $request, VendorBankAccount $bankAccount)
    {
        abort_unless($bankAccount->vendor_id === $request->user('vendor')->id, 403);

        AuditLogger::log('bank_account_deleted', $bankAccount);
        $bankAccount->delete();

        return redirect()->back()->with('success', 'Bank account removed.');
    }

    private function notifyApprovers($vendor, string $action): void
    {
        PortalNotifier::notifyUsersWithPermission(
            'vendors.approve',
            $vendor->company_id,
            'bank_account_pending',
            "Vendor bank account {$action}",
            "Vendor \"{$vendor->name}\" {$action} bank details — verification required before payments.",
            null
        );
    }
}
