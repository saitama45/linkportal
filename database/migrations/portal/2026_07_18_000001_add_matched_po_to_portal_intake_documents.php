<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the PO an invoice was matched to at validation time. Previously the
 * PO↔Invoice relationship was derived on the fly from (vendor_id, po_number);
 * this column caches that match as a stable, indexed reference so it survives a
 * later edit to the PO's number and gives reporting a real foreign key to join.
 *
 * Self-referential (points at another portal_intake_documents row); left as a
 * plain indexed bigint rather than a hard FK constraint to match the loose
 * coupling the rest of the intake schema uses and to keep soft-deletes simple.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portal_intake_documents', 'matched_po_intake_document_id')) {
            Schema::table('portal_intake_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('matched_po_intake_document_id')->nullable()->after('po_number');
                $table->index('matched_po_intake_document_id', 'idx_portal_intake_matched_po');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portal_intake_documents', 'matched_po_intake_document_id')) {
            Schema::table('portal_intake_documents', function (Blueprint $table) {
                $table->dropIndex('idx_portal_intake_matched_po');
                $table->dropColumn('matched_po_intake_document_id');
            });
        }
    }
};
