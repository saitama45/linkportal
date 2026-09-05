<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived 6-digit email verification codes for vendor registration.
 * Codes are stored hashed — a leaked row must not be replayable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portal_email_otps')) {
            return;
        }

        Schema::create('portal_email_otps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('email');               // captured at issue time, so a
                                                   // later email change can't be
                                                   // verified by an older code
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Dropping portal_email_otps would discard data; restore a backup instead.');
    }
};
