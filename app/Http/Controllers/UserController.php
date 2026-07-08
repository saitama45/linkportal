<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Company;
use App\Http\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('roles:id,name');

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('department', 'like', "%{$request->search}%")
                  ->orWhere('position', 'like', "%{$request->search}%");
            });
        }

        $users = $query->paginate($request->get('per_page', 20))->withQueryString();
        $roles = Role::with(['permissions:id,name', 'companies:id,name'])->get();
        $permissions = RoleService::getPermissionsByCategory();
        $companies = Company::where('is_active', true)->select('id', 'name')->orderBy('name')->get();
        $landingPageOptions = RoleService::getLandingPageOptions();
        
        return Inertia::render('Users/Index', [
            'users' => Inertia::scroll($users),
            'roles' => $roles,
            'permissions' => $permissions,
            'companies' => $companies,
            'landing_page_options' => $landingPageOptions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $role = Role::where('name', $validated['role'])->with('companies:id')->firstOrFail();
        $companyId = $role->companies->pluck('id')->first();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'company_id' => $companyId,
            'department' => $validated['department'] ?? null,
            'position' => $validated['position'] ?? null,
            'is_active' => true,
        ]);

        $user->assignRole($role->name);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|exists:roles,name',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $role = Role::where('name', $validated['role'])->with('companies:id')->firstOrFail();

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->company_id = $role->companies->pluck('id')->first();
        $user->department = $validated['department'] ?? null;
        $user->position = $validated['position'] ?? null;
        $user->save();

        $user->syncRoles([$role->name]);
        $this->clearUserPermissionsCache($user);

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        $this->clearUserPermissionsCache($user);
        $user->delete();
        return redirect()->back()->with('success', 'User deleted successfully.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return redirect()->back()->with('success', 'Password reset successfully.');
    }

    private function clearUserPermissionsCache(User $user): void
    {
        try {
            Cache::store(env('USER_PERMISSION_CACHE_STORE', 'database'))
                ->forget('user_permissions_' . $user->id);
        } catch (Throwable) {
            //
        }
    }
}
