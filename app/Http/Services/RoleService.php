<?php

namespace App\Http\Services;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    public static array $modules = [
        'dashboard' => [
            'label' => 'Dashboard',
            'permissions' => ['view'],
        ],
        'users' => [
            'label' => 'Users',
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ],
        'roles' => [
            'label' => 'Roles & Permissions',
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ],
        'companies' => [
            'label' => 'Companies',
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ],
        'vendors' => [
            'label' => 'Vendors',
            'permissions' => ['view', 'create', 'edit', 'delete', 'approve'],
        ],
        'vendor-documents' => [
            'label' => 'Vendor Documents',
            'permissions' => ['view', 'approve'],
        ],
        'products' => [
            'label' => 'Products',
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ],
        'invoices' => [
            'label' => 'Invoices',
            'permissions' => ['view', 'create', 'approve', 'export'],
        ],
        'purchase-orders' => [
            'label' => 'Purchase Orders',
            'permissions' => ['view', 'create', 'approve', 'export'],
        ],
        'quotations' => [
            'label' => 'Quotations',
            'permissions' => ['view', 'create', 'approve', 'export'],
        ],
        'approvals' => [
            'label' => 'Approvals Inbox',
            'permissions' => ['view'],
        ],
    ];

    public static array $permissionOrder = ['view', 'create', 'edit', 'delete', 'approve', 'share', 'export'];

    public static function getLandingPageOptions(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'dashboard'],
            ['label' => 'Users', 'route' => 'users.index'],
            ['label' => 'Companies', 'route' => 'companies.index'],
            ['label' => 'Roles & Permissions', 'route' => 'roles.index'],
            ['label' => 'Vendors', 'route' => 'vendors.index'],
            ['label' => 'Products', 'route' => 'products.index'],
            ['label' => 'Invoices', 'route' => 'invoices.index'],
            ['label' => 'Purchase Orders', 'route' => 'purchase-orders.index'],
            ['label' => 'Quotations', 'route' => 'quotations.index'],
            ['label' => 'Approvals Inbox', 'route' => 'approvals.index'],
        ];
    }

    public static function getAllRoles()
    {
        return Role::with('permissions')->get();
    }

    public static function getAllPermissions()
    {
        return Permission::all();
    }

    public static function getPermissionsByCategory(): array
    {
        $permissions = Permission::all();
        $grouped = [];

        foreach (self::$modules as $key => $module) {
            $modulePermissions = $permissions->filter(function ($permission) use ($key) {
                return explode('.', $permission->name)[0] === $key;
            });

            if ($modulePermissions->isEmpty()) {
                continue;
            }

            $grouped[$module['label']] = $modulePermissions
                ->sort(function ($a, $b) {
                    $actionA = explode('.', $a->name)[1] ?? '';
                    $actionB = explode('.', $b->name)[1] ?? '';
                    $indexA = array_search($actionA, self::$permissionOrder, true);
                    $indexB = array_search($actionB, self::$permissionOrder, true);

                    if ($indexA === false && $indexB === false) {
                        return strcmp($actionA, $actionB);
                    }

                    if ($indexA === false) {
                        return 1;
                    }

                    if ($indexB === false) {
                        return -1;
                    }

                    return $indexA - $indexB;
                })
                ->values()
                ->toArray();
        }

        return $grouped;
    }

    public static function createRole($name, $permissions = [])
    {
        $role = Role::create(['name' => $name]);

        if (! empty($permissions)) {
            $role->givePermissionTo($permissions);
        }

        return $role;
    }

    public static function updateRolePermissions($roleId, $permissions)
    {
        $role = Role::findById($roleId);
        $role->syncPermissions($permissions);

        return $role;
    }

    public static function deleteRole($roleId)
    {
        $role = Role::findById($roleId);

        return $role->delete();
    }

    public static function userHasPermission($user, $permission)
    {
        return $user->can($permission);
    }

    public static function getUserRoles($user)
    {
        return $user->roles;
    }

    public static function assignRoleToUser($user, $roleName)
    {
        return $user->assignRole($roleName);
    }

    public static function removeRoleFromUser($user, $roleName)
    {
        return $user->removeRole($roleName);
    }
}
