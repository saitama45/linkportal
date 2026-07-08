<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vendor auth accounts (separate 'vendor' guard — isolated from internal users)
        Schema::create('portal_vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone', 30)->nullable();
            $table->string('vendor_type', 50)->nullable(); // supplier, service provider, contractor, etc.
            $table->string('status', 20)->default('pending'); // pending, active, suspended, deactivated
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->unsignedBigInteger('erp_vendor_id')->nullable()->index(); // seam: link to ERP vendors.id
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Vendor legal/business profile (maker-checker: pending changes need internal approval)
        Schema::create('portal_vendor_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->unique();
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('rdo_code', 10)->nullable();
            $table->string('business_type', 50)->nullable(); // sole prop, partnership, corporation, cooperative
            $table->string('vat_type', 30)->nullable(); // vat_registered, non_vat, vat_exempt
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('zip_code', 10)->nullable();
            $table->string('country', 100)->nullable()->default('Philippines');
            $table->string('website')->nullable();
            $table->string('payment_terms', 50)->nullable();
            $table->string('currency', 3)->nullable()->default('PHP');
            $table->json('pending_changes')->nullable(); // maker-checker: staged edits awaiting approval
            $table->string('approval_status', 20)->default('draft'); // draft, pending, approved, rejected
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('name');
            $table->string('position', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Change-controlled, fraud-sensitive bank/remittance details
        Schema::create('portal_vendor_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('bank_name');
            $table->string('branch')->nullable();
            $table->string('account_name');
            $table->string('account_number', 50);
            $table->string('currency', 3)->default('PHP');
            $table->boolean('is_default')->default(false);
            $table->string('approval_status', 20)->default('pending'); // pending, approved, rejected
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Accreditation / compliance documents with expiry + versioning + approval
        Schema::create('portal_vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('document_type_id')->nullable()->index(); // -> portal_reference_options
            $table->string('title');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('supersedes_id')->nullable(); // previous version
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, expired
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_vendor_documents');
        Schema::dropIfExists('portal_vendor_bank_accounts');
        Schema::dropIfExists('portal_vendor_contacts');
        Schema::dropIfExists('portal_vendor_profiles');
        Schema::dropIfExists('portal_vendors');
    }
};
