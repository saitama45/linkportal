<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\PortalNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, PortalNotification $notification)
    {
        $vendor = $request->user('vendor');

        abort_unless(
            $notification->notifiable_type === 'vendor' && $notification->notifiable_id === $vendor->id,
            403
        );

        $notification->update(['read_at' => now()]);

        return redirect()->back();
    }

    public function markAllRead(Request $request)
    {
        PortalNotification::for('vendor', $request->user('vendor')->id)
            ->unread()
            ->update(['read_at' => now()]);

        return redirect()->back();
    }
}
