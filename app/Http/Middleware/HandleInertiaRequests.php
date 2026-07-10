<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request): array
    {
        // Explicit guard: the default guard can be switched (e.g. actingAs in
        // tests), and this block only makes sense for internal users
        $user = $request->user('web');
        $permissions = [];

        if ($user) {
            $permissions = $this->permissionsFor($user);

            // Ensure necessary relations are loaded for the user object in the frontend
            $user->loadMissing(['roles.companies']);
        }

        $vendor = auth('vendor')->check() ? auth('vendor')->user() : null;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user,
                'vendor' => $vendor,
                'permissions' => array_values($permissions),
                'notifications' => [],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
            ],
        ]);
    }

    private function permissionsFor($user): array
    {
        $resolver = function () use ($user) {
            $user->loadMissing(['roles.companies']);
            $perms = [];

            // Get permissions through roles to avoid Spatie issues
            foreach ($user->roles as $role) {
                $rolePermissions = $role->permissions()->pluck('name')->toArray();
                $perms = array_merge($perms, $rolePermissions);
            }

            return array_unique($perms);
        };

        try {
            return Cache::store(env('USER_PERMISSION_CACHE_STORE', 'database'))
                ->remember('user_permissions_' . $user->id, 60, $resolver);
        } catch (Throwable) {
            return $resolver();
        }
    }
}
