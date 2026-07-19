<?php

namespace Database\Seeders;

use App\Models\DocumentExceptionRule;
use Illuminate\Database\Seeder;

class DocumentExceptionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['rule_key' => 'missing_required_field', 'label' => 'Missing required field', 'severity' => 'blocker',
             'config' => ['required_fields' => [
                 'invoice' => ['invoice_no', 'document_date', 'total_amount'],
                 'purchase_order' => ['po_number', 'document_date', 'total_amount'],
                 'quotation' => ['document_date', 'total_amount'],
             ]]],
            ['rule_key' => 'low_confidence', 'label' => 'Low OCR confidence', 'severity' => 'warning',
             'config' => ['min_field_confidence' => 0.75, 'min_overall_confidence' => 0.80]],
            ['rule_key' => 'duplicate_invoice_no', 'label' => 'Duplicate invoice number', 'severity' => 'blocker',
             'config' => []],
            ['rule_key' => 'po_mismatch', 'label' => 'PO number not found for vendor', 'severity' => 'warning',
             'config' => []],
            ['rule_key' => 'po_line_mismatch', 'label' => 'Invoice line items differ from the PO', 'severity' => 'warning',
             'config' => ['price_tolerance' => 0.02]],
            ['rule_key' => 'po_amount_exceeded', 'label' => 'Invoice exceeds the PO amount', 'severity' => 'blocker',
             'config' => ['tolerance' => 0.01]],
            // validity_days null = POs never expire (default). Set a number to enforce a window.
            ['rule_key' => 'po_expired', 'label' => 'Invoice bills an expired PO', 'severity' => 'warning',
             'config' => ['validity_days' => null]],
            ['rule_key' => 'total_mismatch', 'label' => 'Line items do not reconcile with totals', 'severity' => 'warning',
             'config' => ['tolerance' => 0.05]],
            ['rule_key' => 'vendor_inactive', 'label' => 'Vendor is not active', 'severity' => 'blocker',
             'config' => []],
            ['rule_key' => 'unsupported_file', 'label' => 'Unsupported file type', 'severity' => 'blocker',
             'config' => ['allowed_extensions' => ['pdf', 'doc', 'docx']]],
            ['rule_key' => 'failed_conversion', 'label' => 'Document conversion failed', 'severity' => 'blocker',
             'config' => []],
            ['rule_key' => 'unmatched_email', 'label' => 'Email sender not matched to a vendor', 'severity' => 'blocker',
             'config' => []],
            ['rule_key' => 'missing_document_type', 'label' => 'Document type not classified', 'severity' => 'blocker',
             'config' => []],
            ['rule_key' => 'missing_template', 'label' => 'No OCR template for vendor/type', 'severity' => 'warning',
             'config' => []],
            ['rule_key' => 'duplicate_file', 'label' => 'Identical file already uploaded', 'severity' => 'warning',
             'config' => []],
            ['rule_key' => 'failed_handoff', 'label' => 'Handoff to ghelpdesk failed', 'severity' => 'blocker',
             'config' => []],
            ['rule_key' => 'overdue_review', 'label' => 'External review overdue', 'severity' => 'warning',
             'config' => ['overdue_days' => 3]],
            ['rule_key' => 'po_awaiting_invoice_overdue', 'label' => 'Approved PO unbilled past aging threshold', 'severity' => 'warning',
             'config' => ['overdue_days' => 7]],
        ];

        foreach ($rules as $rule) {
            DocumentExceptionRule::firstOrCreate(
                ['rule_key' => $rule['rule_key']],
                ['label' => $rule['label'], 'severity' => $rule['severity'], 'config' => $rule['config'], 'enabled' => true],
            );
        }
    }
}
