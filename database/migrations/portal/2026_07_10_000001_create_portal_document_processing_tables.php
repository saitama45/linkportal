<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- OCR templates (per vendor + document type; vendor_id NULL = global fallback) ----
        Schema::create('portal_document_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->nullable(); // null = global fallback for the type
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('document_type', 30); // invoice, purchase_order, quotation
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft, active, archived
            $table->unsignedBigInteger('active_version_id')->nullable(); // resolved in app; no FK (circular)
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'document_type', 'status']);
        });

        Schema::create('portal_document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->index();
            $table->unsignedInteger('version_no');
            $table->json('annotations')->nullable(); // fields + table region; contract shared with ocr-worker /extract
            $table->string('sample_file_path')->nullable(); // private-disk PDF used while annotating
            $table->json('page_meta')->nullable(); // per-page point dimensions from /analyze
            $table->string('status', 20)->default('draft'); // draft, active, superseded
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'version_no']);
        });

        // ---- Email ingestion ----
        Schema::create('portal_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique(); // IMAP Message-ID, dedupe
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('matched_vendor_id')->nullable();
            $table->string('match_method', 10)->nullable(); // exact, domain, none
            $table->string('status', 20)->default('processed'); // processed, unmatched, discarded
            $table->json('meta')->nullable(); // attachment names, skipped files, etc.
            $table->timestamps();
            $table->index(['status', 'received_at']);
        });

        Schema::create('portal_vendor_intake_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('type', 10); // email | domain
            $table->string('value'); // full address or bare domain
            $table->boolean('is_verified')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['type', 'value']);
        });

        // ---- Intake documents (the pipeline hub) ----
        Schema::create('portal_intake_documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique(); // auto: DOC-2026-00001
            $table->unsignedBigInteger('vendor_id')->nullable(); // null until unmatched email is resolved
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('document_type', 30)->nullable(); // invoice, purchase_order, quotation; null until classified
            $table->string('source', 20)->default('portal_upload'); // portal_upload, email
            $table->unsignedBigInteger('inbound_email_id')->nullable();
            $table->string('original_filename');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->index(); // sha256, duplicate-upload warning
            $table->string('file_path'); // private disk, original upload
            $table->string('converted_pdf_path')->nullable(); // same as file_path for native PDFs
            $table->unsignedInteger('page_count')->nullable();
            $table->json('page_meta')->nullable();
            // received, converting, conversion_failed, extracting, extraction_failed,
            // needs_validation, validated, sending, handoff_failed,
            // pending_external_review, approved, returned, rejected, cancelled
            $table->string('status', 30)->default('received')->index();
            $table->unsignedBigInteger('template_version_id')->nullable(); // version used by latest extraction
            // Promoted header fields (from validation) used by exception rules + handoff
            $table->string('invoice_no', 50)->nullable();
            $table->string('po_number', 50)->nullable();
            $table->date('document_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('subtotal', 18, 2)->nullable();
            $table->decimal('tax_amount', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->json('validated_fields')->nullable(); // full corrected header payload
            $table->json('validated_line_items')->nullable(); // full corrected line-item payload
            $table->decimal('overall_confidence', 5, 4)->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('submission_count')->default(0); // feeds idempotency key
            // Mirror of the external (ghelpdesk) review
            $table->string('external_review_id', 50)->nullable();
            $table->string('external_status', 30)->nullable();
            $table->string('external_decision', 20)->nullable(); // approve, return, reject
            $table->text('external_decision_remarks')->nullable();
            $table->string('external_reviewer_name')->nullable();
            $table->timestamp('external_decided_at')->nullable();
            // Future promotion to portal_invoices / portal_purchase_orders / portal_quotations
            $table->string('transaction_type', 40)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->boolean('uploaded_by_vendor_user')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vendor_id', 'document_type']);
            $table->index(['vendor_id', 'invoice_no']); // duplicate detection
        });

        // ---- Extraction run history ----
        Schema::create('portal_document_extractions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intake_document_id')->index();
            $table->unsignedBigInteger('template_version_id')->nullable();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->json('engine_used')->nullable(); // {"1": "embedded", "2": "tesseract"}
            $table->json('header_fields')->nullable();
            $table->json('line_items')->nullable();
            $table->json('totals_check')->nullable();
            $table->decimal('overall_confidence', 5, 4)->nullable();
            $table->string('status', 20)->default('completed'); // completed, failed
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });

        // ---- Exceptions + rule config ----
        Schema::create('portal_document_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intake_document_id')->index();
            $table->string('rule_key', 50);
            $table->string('severity', 10)->default('warning'); // blocker, warning
            $table->string('field_key', 50)->nullable();
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->string('status', 10)->default('open'); // open, resolved, waived
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'rule_key']);
            $table->index(['intake_document_id', 'status']);
        });

        Schema::create('portal_document_exception_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 50)->unique();
            $table->string('label', 100);
            $table->boolean('enabled')->default(true);
            $table->string('severity', 10)->default('warning'); // default severity for raised exceptions
            $table->json('config')->nullable(); // thresholds, required-field lists, tolerance, overdue_days
            $table->timestamps();
        });

        // ---- Vendor-visible timeline / audit trail ----
        Schema::create('portal_document_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intake_document_id')->index();
            $table->string('event', 50); // received, converted, extracted, validated, submitted, approved, ...
            $table->string('actor_type', 20)->nullable(); // user, vendor, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // ---- Integration call audit (both directions) ----
        Schema::create('portal_integration_calls', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10); // outbound, inbound
            $table->string('system', 30)->default('ghelpdesk');
            $table->string('endpoint');
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 10)->default('pending'); // pending, success, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        // ---- AP snapshot (fed by accounting; display-only, never the ledger) ----
        Schema::create('portal_ap_invoice_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('intake_document_id')->nullable();
            $table->string('invoice_no', 50);
            $table->string('status', 30)->default('for_collection'); // for_collection, processing, partially_paid, paid, on_hold, cancelled
            $table->string('mode_of_payment', 50)->nullable();
            $table->decimal('invoice_amount', 18, 2)->nullable();
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('outstanding_amount', 18, 2)->nullable();
            $table->string('payment_reference_no', 100)->nullable();
            $table->date('paid_date')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->string('source', 30)->default('ghelpdesk');
            $table->string('external_ref', 100)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['vendor_id', 'invoice_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_ap_invoice_statuses');
        Schema::dropIfExists('portal_integration_calls');
        Schema::dropIfExists('portal_document_events');
        Schema::dropIfExists('portal_document_exception_rules');
        Schema::dropIfExists('portal_document_exceptions');
        Schema::dropIfExists('portal_document_extractions');
        Schema::dropIfExists('portal_intake_documents');
        Schema::dropIfExists('portal_vendor_intake_emails');
        Schema::dropIfExists('portal_inbound_emails');
        Schema::dropIfExists('portal_document_template_versions');
        Schema::dropIfExists('portal_document_templates');
    }
};
