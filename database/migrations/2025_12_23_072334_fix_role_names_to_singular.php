<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix role names to singular
        Role::where('name', 'Admins')->update(['name' => 'Admin']);
        Role::where('name', 'Users')->update(['name' => 'User']);
        Role::where('name', 'Tech Supports')->update(['name' => 'Tech Support']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::where('name', 'Admin')->update(['name' => 'Admins']);
        Role::where('name', 'User')->update(['name' => 'Users']);
        Role::where('name', 'Tech Support')->update(['name' => 'Tech Supports']);
    }
};