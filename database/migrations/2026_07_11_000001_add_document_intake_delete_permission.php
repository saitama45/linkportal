<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'document-intake.delete';

    public function up(): void
    {
        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        $admin = Role::query()
            ->where('name', 'Admin')
            ->first();

        $admin?->givePermissionTo($permission);

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
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
