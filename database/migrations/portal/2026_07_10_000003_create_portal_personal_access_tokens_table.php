<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stock Sanctum schema, renamed: the shared DB already holds ghelpdesk's
// personal_access_tokens and cross-app token acceptance must be impossible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_personal_access_tokens');
    }
};
