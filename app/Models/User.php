<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'department',
        'position',
        'password',
        'company_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function updateLastLogin(): void
    {
        //
    }

    /**
     * Get the landing page route name for the user based on their roles.
     * 
     * @return string
     */
    public function getLandingPageRoute(): string
    {
        // For debugging, we can see roles
        if ($this->roles->isEmpty()) {
            return 'dashboard';
        }

        // Find the first role that has a specific landing page set
        $roleWithLandingPage = $this->roles->first(fn($role) => !empty($role->landing_page) && $role->landing_page !== 'dashboard');

        $route = $roleWithLandingPage ? $roleWithLandingPage->landing_page : 'dashboard';

        // Final safety check to ensure the route exists
        return \Illuminate\Support\Facades\Route::has($route) ? $route : 'dashboard';
    }
}
