<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'users_count' => User::count(),
                'companies_count' => Company::count(),
                'roles_count' => Role::count(),
            ],
        ]);
    }
}
