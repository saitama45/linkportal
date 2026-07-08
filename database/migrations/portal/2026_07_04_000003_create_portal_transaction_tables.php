<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Invoices (vendor-submitted) ----
        Schema::create('portal_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('reference_no', 30)->unique(); // auto: INV-2026-00001
            $table->string('invoice_no', 50); // vendor's own SI number
            $table->string('po_number', 50)->nullable(); // related PO reference
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('withholding_tax', 18, 2)->default(0); // PH EWT placeholder
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('draft')->index(); // draft, submitted, under_review, approved, rejected, returned, cancelled
            $table->unsignedInteger('current_approval_level')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('erp_ref', 50)->nullable(); // seam: future push to payment_invoices
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vendor_id', 'invoice_no']); // duplicate detection
        });

        Schema::create('portal_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ---- Purchase Orders (vendor submits; also supports vendor acknowledgment) ----
        Schema::create('portal_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('reference_no', 30)->unique(); // auto: PO-2026-00001
            $table->string('po_number', 50); // external/source PO number
            $table->date('po_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('current_approval_level')->default(0);
            $table->timestamp('submitted_at')->nullable();
            // Vendor acknowledgment/confirmation
            $table->string('acknowledgment_status', 20)->nullable(); // pending, acknowledged, declined
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgment_remarks')->nullable();
            $table->text('remarks')->nullable();
            $table->string('erp_ref', 50)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('portal_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ---- Quotations ----
        Schema::create('portal_quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('reference_no', 30)->unique(); // auto: QTN-2026-00001
            $table->string('quotation_no', 50)->nullable(); // vendor's own number
            $table->string('title');
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('payment_terms', 100)->nullable();
            $table->string('delivery_terms', 100)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('current_approval_level')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('erp_ref', 50)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('portal_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ---- Polymorphic attachments for any portal document ----
        Schema::create('portal_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('uploaded_by_type', 20)->default('vendor'); // vendor | user
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id'], 'portal_attachments_morph_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_attachments');
        Schema::dropIfExists('portal_quotation_items');
        Schema::dropIfExists('portal_quotations');
        Schema::dropIfExists('portal_purchase_order_items');
        Schema::dropIfExists('portal_purchase_orders');
        Schema::dropIfExists('portal_invoice_items');
        Schema::dropIfExists('portal_invoices');
    }
};
