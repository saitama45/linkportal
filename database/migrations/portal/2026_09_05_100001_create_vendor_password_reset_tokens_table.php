<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors get their own reset-token table rather than sharing
 * `password_reset_tokens` with internal staff: that table is keyed on the email
 * alone, so one address present in both populations would let a staff reset
 * consume a vendor's token (and the reverse).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_password_reset_tokens')) {
            return;
        }

        Schema::create('vendor_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_password_reset_tokens');
    }
};
