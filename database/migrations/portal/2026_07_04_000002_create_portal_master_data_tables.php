<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nestable product categories
        Schema::create('portal_product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('portal_units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // pcs, box, kg, hr, lot
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Flexible product/item master: product_type + JSON attributes for per-type fields
        Schema::create('portal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('code', 50)->unique(); // SKU / item code
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('product_type', 30)->default('good')->index(); // asset, good, service, raw_material, consumable
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('default_price', 18, 2)->nullable();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('tax_rate', 5, 2)->nullable(); // default VAT %
            $table->json('attributes')->nullable(); // flexible per-type fields (brand, model, eol_years, ...)
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Generic lookups: document_type, currency, payment_terms, tax_code, incoterm, ...
        Schema::create('portal_reference_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('value', 100);
            $table->string('label');
            $table->json('meta')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Per company/type/year auto-numbering (INV-2026-00001)
        Schema::create('portal_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('document_type', 30); // invoice, purchase_order, quotation, vendor
            $table->unsignedSmallInteger('year');
            $table->string('prefix', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'document_type', 'year'], 'portal_numseq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_number_sequences');
        Schema::dropIfExists('portal_reference_options');
        Schema::dropIfExists('portal_products');
        Schema::dropIfExists('portal_units_of_measure');
        Schema::dropIfExists('portal_product_categories');
    }
};
