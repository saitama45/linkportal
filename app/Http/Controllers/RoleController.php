<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Role;
use App\Models\Company;
use Spatie\Permission\Models\Permission;
use App\Http\Services\RoleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

class RoleController extends Controller
{
    /**
     * Clear permission cache for all users assigned to this role
     */
    private function clearUserPermissionsCache($role)
    {
        foreach ($role->users as $user) {
            try {
                Cache::store(env('USER_PERMISSION_CACHE_STORE', 'database'))
                    ->forget('user_permissions_' . $user->id);
            } catch (Throwable) {
                //
            }
        }
    }

    public function index(Request $request)
    {
        $query = Role::with(['permissions:id,name', 'companies:id,name']);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $roles = $query->paginate($request->get('per_page', 20))->withQueryString();
        $permissions = RoleService::getPermissionsByCategory();
        $companies = Company::where('is_active', true)->select('id', 'name')->orderBy('name')->get();
        $landingPageOptions = RoleService::getLandingPageOptions();

        return Inertia::render('Roles/Index', [
            'roles' => Inertia::scroll($roles),
            'permissions' => $permissions,
            'companies' => $companies,
            'landing_page_options' => $landingPageOptions,
        ]);
    }

    public function store(Request $request)
    {
        $landingPageOptions = collect(RoleService::getLandingPageOptions())->pluck('route')->toArray();

        $request->validate([
            'name' => 'required|string|max:255|unique:roles',
            'permissions' => 'array',
            'company_ids' => 'array',
            'company_ids.*' => 'exists:companies,id',
            'landing_page' => ['nullable', 'string', 'max:255', Rule::in($landingPageOptions)],
        ]);

        $role = Role::create([
            'name' => $request->name,
            'landing_page' => $request->landing_page,
        ]);

        if ($request->permissions) {
            $role->syncPermissions($request->permissions);
        }

        if ($request->has('company_ids')) {
            $role->companies()->sync($request->company_ids);
        }

        return redirect()->back()->with('success', 'Role created successfully');
    }

    public function update(Request $request, Role $role)
    {
        $landingPageOptions = collect(RoleService::getLandingPageOptions())->pluck('route')->toArray();

        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'array',
            'company_ids' => 'array',
            'company_ids.*' => 'exists:companies,id',
            'landing_page' => ['nullable', 'string', 'max:255', Rule::in($landingPageOptions)],
        ]);

        $role->name = $request->name;
        $role->landing_page = $request->landing_page;
        $role->save();
        $role->syncPermissions($request->permissions ?? []);

        if ($request->has('company_ids')) {
            $role->companies()->sync($request->company_ids);
        } else {
            $role->companies()->detach();
        }

        $this->clearUserPermissionsCache($role);

        return redirect()->back()->with('success', 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete role with assigned users');
        }

        $this->clearUserPermissionsCache($role);
        $role->companies()->detach(); // Clean up pivot table
        $role->delete();
        return redirect()->back()->with('success', 'Role deleted successfully');
    }
}
