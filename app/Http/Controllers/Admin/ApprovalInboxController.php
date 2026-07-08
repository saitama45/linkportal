<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\ApprovalService;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Vendor;
use App\Models\VendorBankAccount;
use App\Models\VendorDocument;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Aggregated cross-type queue of everything awaiting the current user's action,
 * filtered by their approve permissions.
 */
class ApprovalInboxController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $items = collect();

        // Transaction documents routed through the ApprovalService
        $transactionTypes = [
            'invoice' => [Invoice::class, 'invoices.show', 'Invoice'],
            'purchase_order' => [PurchaseOrder::class, 'purchase-orders.show', 'Purchase Order'],
            'quotation' => [Quotation::class, 'quotations.show', 'Quotation'],
        ];

        foreach ($transactionTypes as $documentType => [$modelClass, $routeName, $label]) {
            $pending = $modelClass::with('vendor:id,name')->pendingApproval()->latest('submitted_at')->get();

            foreach ($pending as $document) {
                $permission = ApprovalService::requiredPermission(
                    $documentType,
                    max(1, (int) $document->current_approval_level),
                    $document->company_id,
                    (float) ($document->total_amount ?? 0) ?: null
                );

                if ($user->can($permission)) {
                    $items->push([
                        'type' => $documentType,
                        'label' => $label,
                        'id' => $document->id,
                        'reference' => $document->reference_no,
                        'vendor' => $document->vendor?->name,
                        'amount' => $document->total_amount,
                        'level' => $document->current_approval_level,
                        'submitted_at' => $document->submitted_at,
                        'url' => route($routeName, $document->id),
                    ]);
                }
            }
        }

        // Vendor account activations
        if ($user->can('vendors.approve')) {
            foreach (Vendor::where('status', 'pending')->latest()->get() as $vendor) {
                $items->push([
                    'type' => 'vendor_account',
                    'label' => 'Vendor Activation',
                    'id' => $vendor->id,
                    'reference' => $vendor->code,
                    'vendor' => $vendor->name,
                    'amount' => null,
                    'level' => 1,
                    'submitted_at' => $vendor->created_at,
                    'url' => route('vendors.show', $vendor->id),
                ]);
            }

            foreach (VendorProfile::with('vendor:id,code,name')->where('approval_status', 'pending')->get() as $profile) {
                $items->push([
                    'type' => 'vendor_profile',
                    'label' => 'Profile Change',
                    'id' => $profile->id,
                    'reference' => $profile->vendor?->code,
                    'vendor' => $profile->vendor?->name,
                    'amount' => null,
                    'level' => 1,
                    'submitted_at' => $profile->updated_at,
                    'url' => $profile->vendor ? route('vendors.show', $profile->vendor->id) : null,
                ]);
            }

            foreach (VendorBankAccount::with('vendor:id,code,name')->where('approval_status', 'pending')->get() as $account) {
                $items->push([
                    'type' => 'vendor_bank_account',
                    'label' => 'Bank Verification',
                    'id' => $account->id,
                    'reference' => $account->vendor?->code,
                    'vendor' => $account->vendor?->name,
                    'amount' => null,
                    'level' => 1,
                    'submitted_at' => $account->updated_at,
                    'url' => $account->vendor ? route('vendors.show', $account->vendor->id) : null,
                ]);
            }
        }

        if ($user->can('vendor-documents.approve')) {
            foreach (VendorDocument::with('vendor:id,code,name')->where('status', 'pending')->latest()->get() as $document) {
                $items->push([
                    'type' => 'vendor_document',
                    'label' => 'Document Review',
                    'id' => $document->id,
                    'reference' => $document->title,
                    'vendor' => $document->vendor?->name,
                    'amount' => null,
                    'level' => 1,
                    'submitted_at' => $document->created_at,
                    'url' => $document->vendor ? route('vendors.show', $document->vendor->id) : null,
                ]);
            }
        }

        return Inertia::render('Approvals/Index', [
            'items' => $items->sortByDesc('submitted_at')->values(),
        ]);
    }
}
