<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable approval matrix per document type / company (amount thresholds optional)
        Schema::create('portal_approval_flows', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40)->index(); // vendor_profile, vendor_document, vendor_bank_account, invoice, purchase_order, quotation
            $table->unsignedBigInteger('company_id')->nullable()->index(); // null = default flow for all companies
            $table->decimal('min_amount', 18, 2)->nullable(); // threshold routing (null = any)
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->unsignedInteger('total_levels')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('portal_approval_flow_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_flow_id')->index();
            $table->unsignedInteger('level');
            $table->string('name', 100); // e.g. "Procurement Review", "Finance Approval"
            $table->string('required_permission', 100)->nullable(); // e.g. invoices.approve
            $table->unsignedBigInteger('role_id')->nullable(); // alternative: specific role
            $table->unsignedInteger('sla_hours')->nullable(); // escalation timer
            $table->timestamps();
        });

        // Polymorphic approval audit trail (mirrors ERP form_record_approvals pattern)
        Schema::create('portal_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->unsignedInteger('level');
            $table->unsignedBigInteger('user_id')->index(); // internal approver
            $table->string('action', 20); // approved, rejected, returned
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->index(['approvable_type', 'approvable_id'], 'portal_approvals_morph_idx');
        });

        // Full audit log (every state change, both actor types)
        Schema::create('portal_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20)->default('user'); // user | vendor | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 60); // created, updated, submitted, approved, ...
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id'], 'portal_audit_morph_idx');
        });

        // In-app notifications for both internal users and vendors
        // ('notifications' already exists in the shared ERP DB — hence the prefix)
        Schema::create('portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type', 20); // user | vendor
            $table->unsignedBigInteger('notifiable_id')->index();
            $table->string('type', 60); // document_approved, invoice_submitted, document_expiring, ...
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'portal_notif_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_notifications');
        Schema::dropIfExists('portal_audit_logs');
        Schema::dropIfExists('portal_approvals');
        Schema::dropIfExists('portal_approval_flow_levels');
        Schema::dropIfExists('portal_approval_flows');
    }
};
