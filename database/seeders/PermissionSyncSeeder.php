<?php

namespace Database\Seeders;

use App\Http\Services\RoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent permission sync — safe to run in production on every boot.
 *
 * Ensures every permission declared in RoleService::$modules exists in the DB
 * and is granted to the Admin role, then clears the permission caches. Unlike
 * RolesAndPermissionSeeder it creates NO default company and NO default user, so
 * it never introduces backdoor credentials. This is what makes newly-added
 * modules (e.g. Document Intake / OCR Templates) appear after a deploy without
 * touching the shared schema.
 */
class PermissionSyncSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleService::$modules as $moduleKey => $config) {
            foreach ($config['permissions'] as $action) {
                Permission::findOrCreate("{$moduleKey}.{$action}", 'web');
            }
        }

        $admin = Role::where('name', 'Admin')->first();

        if ($admin) {
            // Additive — never removes permissions an operator set manually.
            $admin->givePermissionTo(Permission::all());

            foreach ($admin->users()->pluck('users.id') as $userId) {
                try {
                    Cache::store(env('USER_PERMISSION_CACHE_STORE', 'database'))
                        ->forget('user_permissions_'.$userId);
                } catch (\Throwable) {
                    //
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Permissions synced from RoleService and granted to Admin.');
    }
}
