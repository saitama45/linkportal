<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\ApInvoiceStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Vendor-facing AP snapshot: read-only payment status per invoice, fed by
 * accounting. Never the ledger — display only.
 */
class AccountsPayableController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        $query = ApInvoiceStatus::where('vendor_id', $vendor->id)
            ->orderByDesc('updated_at');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('invoice_no', 'like', "%{$request->search}%")
                    ->orWhere('payment_reference_no', 'like', "%{$request->search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render('Vendor/AccountsPayable/Index', [
            'statuses' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'status']),
            'statusOptions' => ApInvoiceStatus::STATUSES,
        ]);
    }
}
