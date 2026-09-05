<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque payment details for vendors who are paid by cheque rather than transfer.
 *
 * `cheque_payee_name` is the exact name to write on the cheque — it is often not
 * the vendor's legal name (a trade name, or a parent company), and getting it
 * wrong means the vendor cannot encash. It rides the profile's existing
 * maker-checker flow, since changing a payee redirects money just as a bank
 * account change does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_vendor_profiles')) {
            return;
        }

        Schema::table('portal_vendor_profiles', function (Blueprint $table) {
            foreach ([
                // "Pay to the order of ..." exactly as it must appear.
                'cheque_payee_name' => fn () => $table->string('cheque_payee_name')->nullable(),
                // pickup | courier | bank_deposit
                'cheque_delivery_method' => fn () => $table->string('cheque_delivery_method', 30)->nullable(),
                // Crossed / "For Payee's Account Only" cheques cannot be endorsed.
                'cheque_is_crossed' => fn () => $table->boolean('cheque_is_crossed')->default(false),
                'cheque_remarks' => fn () => $table->string('cheque_remarks', 500)->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('portal_vendor_profiles', $column)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Dropping the cheque columns would discard payee details; restore a backup instead.'
        );
    }
};
