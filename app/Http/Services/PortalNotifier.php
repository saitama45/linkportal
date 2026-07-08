<?php

namespace App\Http\Services;

use App\Models\PortalNotification;
use App\Models\User;
use App\Models\Vendor;

class PortalNotifier
{
    public static function notifyVendor(Vendor $vendor, string $type, string $title, ?string $message = null, ?string $url = null): void
    {
        PortalNotification::create([
            'notifiable_type' => 'vendor',
            'notifiable_id' => $vendor->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'url' => $url,
        ]);
    }

    public static function notifyUser(User $user, string $type, string $title, ?string $message = null, ?string $url = null): void
    {
        PortalNotification::create([
            'notifiable_type' => 'user',
            'notifiable_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'url' => $url,
        ]);
    }

    /**
     * Notify every internal user holding a permission (optionally scoped to a
     * company through the role_company link).
     */
    public static function notifyUsersWithPermission(string $permission, ?int $companyId, string $type, string $title, ?string $message = null, ?string $url = null): void
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles', function ($roleQuery) use ($permission, $companyId) {
                $roleQuery->whereHas('permissions', fn ($q) => $q->where('name', $permission));

                if ($companyId) {
                    $roleQuery->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId));
                }
            })
            ->get();

        foreach ($users as $user) {
            self::notifyUser($user, $type, $title, $message, $url);
        }
    }
}
