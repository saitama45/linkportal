<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'invoices.create',
        'purchase-orders.create',
        'quotations.create',
    ];

    public function up(): void
    {
        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        $admin = Role::query()
            ->where('name', 'Admin')
            ->first();

        $admin?->givePermissionTo($permissions);

        foreach ($admin?->users()->pluck('users.id') ?? [] as $userId) {
            try {
                Cache::store(env('USER_PERMISSION_CACHE_STORE', 'database'))
                    ->forget('user_permissions_'.$userId);
            } catch (\Throwable) {
                //
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
