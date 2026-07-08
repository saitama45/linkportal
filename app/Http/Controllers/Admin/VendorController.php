<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\NumberingService;
use App\Http\Services\PortalNotifier;
use App\Models\Company;
use App\Models\ReferenceOption;
use App\Models\Vendor;
use App\Models\VendorBankAccount;
use App\Models\VendorDocument;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $query = Vendor::with('company:id,name')->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render('Vendors/Index', [
            'vendors' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'status']),
            'companies' => Company::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'vendorTypeOptions' => ReferenceOption::ofType('vendor_type')
                ->get(['value', 'label']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('vendors.create'), 403);

        $validated = $request->validate([
            'company_id' => [
                'nullable',
                Rule::exists('companies', 'id')->where('is_active', true),
            ],
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:portal_vendors,email',
            'phone' => 'nullable|string|max:30',
            'vendor_type' => [
                'nullable',
                'string',
                'max:50',
                Rule::exists('portal_reference_options', 'value')
                    ->where('type', 'vendor_type')
                    ->where('is_active', true),
            ],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $vendor = DB::transaction(function () use ($request, $validated) {
            $vendor = Vendor::create([
                'company_id' => $validated['company_id'] ?? null,
                'code' => NumberingService::next('vendor'),
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['phone'] ?? null,
                'vendor_type' => $validated['vendor_type'] ?? null,
                'status' => 'pending',
                'email_verified_at' => now(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            VendorProfile::create([
                'vendor_id' => $vendor->id,
                'approval_status' => 'draft',
            ]);

            return $vendor;
        });

        AuditLogger::log('vendor_created', $vendor, null, $vendor->only([
            'company_id',
            'code',
            'name',
            'email',
            'phone',
            'vendor_type',
            'status',
        ]));

        return redirect()->back()->with('success', "Vendor {$vendor->code} created successfully.");
    }

    public function show(Vendor $vendor)
    {
        return Inertia::render('Vendors/Show', [
            'vendor' => $vendor->load([
                'company:id,name',
                'profile',
                'contacts',
                'bankAccounts',
                'documents.documentType:id,label',
                'documents.reviewer:id,name',
            ]),
        ]);
    }

    public function update(Request $request, Vendor $vendor)
    {
        abort_unless($request->user()->can('vendors.edit'), 403);

        $validated = $request->validate([
            'company_id' => [
                'nullable',
                Rule::exists('companies', 'id')->where('is_active', true),
            ],
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('portal_vendors', 'email')->ignore($vendor->id),
            ],
            'phone' => 'nullable|string|max:30',
            'vendor_type' => [
                'nullable',
                'string',
                'max:50',
                Rule::exists('portal_reference_options', 'value')
                    ->where('type', 'vendor_type')
                    ->where('is_active', true),
            ],
        ]);

        $before = $vendor->only([
            'company_id',
            'name',
            'email',
            'phone',
            'vendor_type',
        ]);

        $vendor->update([
            ...$validated,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::log('vendor_updated', $vendor, $before, $vendor->only([
            'company_id',
            'name',
            'email',
            'phone',
            'vendor_type',
        ]));

        return redirect()->back()->with('success', "Vendor {$vendor->code} updated successfully.");
    }

    /**
     * Activate / suspend the vendor account.
     */
    public function updateStatus(Request $request, Vendor $vendor)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,suspended,deactivated',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $before = ['status' => $vendor->status];

        $vendor->update([
            'status' => $validated['status'],
            'approved_by' => $validated['status'] === 'active' ? $request->user()->id : $vendor->approved_by,
            'approved_at' => $validated['status'] === 'active' ? now() : $vendor->approved_at,
        ]);

        AuditLogger::log('vendor_status_changed', $vendor, $before, ['status' => $vendor->status]);

        PortalNotifier::notifyVendor(
            $vendor,
            'account_status',
            'Account '.$validated['status'],
            $validated['status'] === 'active'
                ? 'Your vendor account has been activated. You now have full portal access.'
                : 'Your vendor account status changed to '.$validated['status'].($validated['remarks'] ? ': '.$validated['remarks'] : '.'),
            null
        );

        return redirect()->back()->with('success', "Vendor {$validated['status']}.");
    }

    /**
     * Maker-checker: approve or reject staged profile changes.
     */
    public function reviewProfile(Request $request, Vendor $vendor)
    {
        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $profile = $vendor->profile;

        if ($profile->approval_status !== 'pending') {
            return redirect()->back()->with('error', 'No pending profile changes to review.');
        }

        $before = $profile->only(['legal_name', 'tin', 'address']);

        if ($validated['action'] === 'approved' && $profile->pending_changes) {
            $profile->fill($profile->pending_changes);
        }

        $profile->forceFill([
            'pending_changes' => null,
            'approval_status' => $validated['action'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['remarks'] ?? null,
        ])->save();

        AuditLogger::log('profile_'.$validated['action'], $profile, $before, $profile->only(['legal_name', 'tin', 'address']));

        PortalNotifier::notifyVendor(
            $vendor,
            'profile_'.$validated['action'],
            'Profile changes '.$validated['action'],
            'Your profile changes were '.$validated['action'].($validated['remarks'] ? ': '.$validated['remarks'] : '.'),
            null
        );

        return redirect()->back()->with('success', 'Profile changes '.$validated['action'].'.');
    }

    public function reviewDocument(Request $request, VendorDocument $document)
    {
        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($document->status !== 'pending') {
            return redirect()->back()->with('error', 'This document is not pending review.');
        }

        $document->update([
            'status' => $validated['action'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['remarks'] ?? null,
        ]);

        AuditLogger::log('document_'.$validated['action'], $document);

        PortalNotifier::notifyVendor(
            $document->vendor,
            'document_'.$validated['action'],
            'Document '.$validated['action'],
            "Your document \"{$document->title}\" was {$validated['action']}".($validated['remarks'] ? ': '.$validated['remarks'] : '.'),
            null
        );

        return redirect()->back()->with('success', 'Document '.$validated['action'].'.');
    }

    public function reviewBankAccount(Request $request, VendorBankAccount $bankAccount)
    {
        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($bankAccount->approval_status !== 'pending') {
            return redirect()->back()->with('error', 'This bank account is not pending verification.');
        }

        $bankAccount->update([
            'approval_status' => $validated['action'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['remarks'] ?? null,
        ]);

        AuditLogger::log('bank_account_'.$validated['action'], $bankAccount);

        PortalNotifier::notifyVendor(
            $bankAccount->vendor,
            'bank_account_'.$validated['action'],
            'Bank account '.$validated['action'],
            "Your bank account ({$bankAccount->bank_name}) was {$validated['action']}".($validated['remarks'] ? ': '.$validated['remarks'] : '.'),
            null
        );

        return redirect()->back()->with('success', 'Bank account '.$validated['action'].'.');
    }
}
