<?php

namespace Database\Seeders;

use App\Http\Services\RoleService;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $defaultCompany = Company::firstOrCreate(
            ['code' => 'APP'],
            [
                'name' => 'Default Company',
                'description' => 'Default company for system access.',
                'is_active' => true,
            ]
        );

        foreach (RoleService::$modules as $moduleKey => $config) {
            foreach ($config['permissions'] as $action) {
                Permission::firstOrCreate(['name' => "{$moduleKey}.{$action}"]);
            }
        }

        $admin = Role::firstOrCreate(['name' => 'Admin'], ['landing_page' => 'dashboard']);
        $admin->syncPermissions(Permission::all());
        $admin->companies()->syncWithoutDetaching([$defaultCompany->id]);

        $user = Role::firstOrCreate(['name' => 'User'], ['landing_page' => 'dashboard']);
        $user->syncPermissions(Permission::whereIn('name', ['dashboard.view'])->get());
        $user->companies()->syncWithoutDetaching([$defaultCompany->id]);

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('admin123'),
                'department' => 'Administration',
                'position' => 'System Administrator',
                'email_verified_at' => now(),
                'company_id' => $defaultCompany->id,
                'is_active' => true,
            ]
        );

        $adminUser->syncRoles(['Admin']);

        if (! $adminUser->company_id) {
            $adminUser->update(['company_id' => $defaultCompany->id]);
        }

        $this->command?->info('Core roles, permissions, default company, and admin user seeded.');
    }
}
