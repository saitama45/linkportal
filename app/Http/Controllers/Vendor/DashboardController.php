<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\IntakeDocument;
use App\Models\PortalNotification;
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
                'uploads' => IntakeDocument::forVendor($vendor->id)->count(),
                'uploads_returned' => IntakeDocument::forVendor($vendor->id)->where('status', IntakeDocument::STATUS_RETURNED)->count(),
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
