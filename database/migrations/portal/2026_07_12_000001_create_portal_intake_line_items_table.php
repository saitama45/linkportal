<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flat, queryable projection of a validated document's line items — one row per
 * line. The authoritative snapshot stays in portal_intake_documents.validated_line_items
 * (JSON); this table is rebuilt from it on validate so reporting/joins (run on the
 * ghelpdesk side against the shared DB) don't have to parse JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_intake_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intake_document_id')->index();
            $table->unsignedInteger('line_no')->default(0);
            $table->string('description', 500)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('uom', 50)->nullable();
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->decimal('line_total', 18, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_intake_line_items');
    }
};
