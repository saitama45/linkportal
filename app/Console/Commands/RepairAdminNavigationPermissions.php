<?php

namespace App\Console\Commands;

use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RepairAdminNavigationPermissions extends Command
{
    protected $signature = 'permissions:repair-admin-navigation
        {--role=Admin : Role name to repair}
        {--dry-run : Show intended changes without writing to the database}';

    protected $description = 'Ensure the admin role has Users and Roles navigation permissions.';

    public function handle(): int
    {
        $roleName = (string) $this->option('role');
        $dryRun = (bool) $this->option('dry-run');
        $permissionNames = [
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
        ];

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->error("Role [{$roleName}] was not found.");

            return self::FAILURE;
        }

        $existingPermissions = Permission::whereIn('name', $permissionNames)
            ->pluck('name')
            ->all();
        $missingPermissions = array_values(array_diff($permissionNames, $existingPermissions));

        $assignedPermissions = $role->permissions()
            ->whereIn('name', $permissionNames)
            ->pluck('name')
            ->all();
        $missingAssignments = array_values(array_diff($permissionNames, $assignedPermissions));

        if ($dryRun) {
            $this->info('Dry run only. No database changes were made.');
            $this->line('Missing permission records: '.($missingPermissions ? implode(', ', $missingPermissions) : 'none'));
            $this->line("Missing assignments for role [{$roleName}]: ".($missingAssignments ? implode(', ', $missingAssignments) : 'none'));

            return self::SUCCESS;
        }

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $role->givePermissionTo($permissionNames);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $userCount = 0;

        foreach ($role->users as $user) {
            Cache::store(env('USER_PERMISSION_CACHE_STORE', 'database'))
                ->forget('user_permissions_'.$user->id);
            $userCount++;
        }

        $this->info("Users/Roles navigation permissions repaired for role [{$roleName}].");
        $this->line('Permission cache cleared.');
        $this->line("Cleared cached permissions for {$userCount} assigned user(s).");

        return self::SUCCESS;
    }
}
