<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PortalNotification;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\VendorDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        return Inertia::render('Vendor/Dashboard', [
            'stats' => [
                'invoices' => Invoice::forVendor($vendor->id)->count(),
                'invoices_pending' => Invoice::forVendor($vendor->id)->pendingApproval()->count(),
                'purchase_orders' => PurchaseOrder::forVendor($vendor->id)->count(),
                'quotations' => Quotation::forVendor($vendor->id)->count(),
                'documents' => VendorDocument::where('vendor_id', $vendor->id)->count(),
                'documents_pending' => VendorDocument::where('vendor_id', $vendor->id)->where('status', 'pending')->count(),
            ],
            'expiringDocuments' => VendorDocument::where('vendor_id', $vendor->id)
                ->expiringWithin(30)
                ->orderBy('expiry_date')
                ->take(5)
                ->get(),
            'recentNotifications' => PortalNotification::for('vendor', $vendor->id)
                ->latest()
                ->take(8)
                ->get(),
            'accountStatus' => $vendor->status,
            'profileStatus' => $vendor->profile?->approval_status ?? 'draft',
        ]);
    }
}
