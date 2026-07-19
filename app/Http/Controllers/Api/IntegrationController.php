<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\DocumentExceptionService;
use App\Http\Services\DocumentIntakeService;
use App\Http\Services\PortalNotifier;
use App\Models\ApInvoiceStatus;
use App\Models\IntakeDocument;
use App\Models\IntegrationCall;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Inbound app-to-app endpoints (Sanctum-token auth) plus the signed file
 * route ghelpdesk uses to fetch source PDFs.
 */
class IntegrationController extends Controller
{
    /** GET /integrations/files/{intakeDocument} — `signed` middleware, no session. */
    public function file(IntakeDocument $intakeDocument)
    {
        $path = $intakeDocument->converted_pdf_path ?? $intakeDocument->file_path;
        abort_unless($path && Storage::disk(DocumentIntakeService::DISK)->exists($path), 404);

        return Storage::disk(DocumentIntakeService::DISK)->response(
            $path,
            $intakeDocument->reference_no.'.pdf',
        );
    }

    /** POST /api/integrations/ghelpdesk/document-review-decision */
    public function documentReviewDecision(Request $request, DocumentExceptionService $exceptions)
    {
        $validated = $request->validate([
            'review_id' => 'required',
            'source_document_id' => 'required|integer',
            'decision' => 'required|in:approve,return,reject',
            'remarks' => 'required_unless:decision,approve|nullable|string|max:2000',
            'reviewer' => 'nullable|string|max:255',
            'corrected_fields' => 'nullable|array',
            'decided_at' => 'nullable|date',
        ]);

        $document = IntakeDocument::with('vendor')->findOrFail($validated['source_document_id']);

        IntegrationCall::create([
            'direction' => 'inbound',
            'system' => 'ghelpdesk',
            'endpoint' => '/api/integrations/ghelpdesk/document-review-decision',
            'subject_type' => IntakeDocument::class,
            'subject_id' => $document->id,
            'request_payload' => $validated,
            'status' => 'success',
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);

        // Idempotent replay: same review + same decision already applied
        if ((string) $document->external_review_id === (string) $validated['review_id']
            && $document->external_decision === $validated['decision']) {
            return response()->json(['status' => 'ok', 'message' => 'Decision already applied.']);
        }

        if ($document->status !== IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW) {
            return response()->json([
                'status' => 'conflict',
                'message' => "Document is in status '{$document->status}', not pending external review.",
            ], 409);
        }

        $statusMap = [
            'approve' => IntakeDocument::STATUS_APPROVED,
            'return' => IntakeDocument::STATUS_RETURNED,
            'reject' => IntakeDocument::STATUS_REJECTED,
        ];

        $document->forceFill([
            'status' => $statusMap[$validated['decision']],
            'external_status' => $validated['decision'],
            'external_decision' => $validated['decision'],
            'external_decision_remarks' => $validated['remarks'] ?? null,
            'external_reviewer_name' => $validated['reviewer'] ?? null,
            'external_decided_at' => $validated['decided_at'] ?? now(),
        ])->save();

        $document->recordEvent($statusMap[$validated['decision']], $validated['remarks'] ?? null, [
            'reviewer' => $validated['reviewer'] ?? null,
            'corrected_fields' => $validated['corrected_fields'] ?? null,
        ]);

        // Approved invoices surface in the vendor's AP snapshot as receivable
        if ($validated['decision'] === 'approve' && $document->document_type === 'invoice'
            && $document->vendor_id && $document->invoice_no) {
            ApInvoiceStatus::firstOrCreate(
                ['vendor_id' => $document->vendor_id, 'invoice_no' => $document->invoice_no],
                [
                    'intake_document_id' => $document->id,
                    'status' => 'for_collection',
                    'invoice_amount' => $document->total_amount,
                    'outstanding_amount' => $document->total_amount,
                    'last_synced_at' => now(),
                ],
            );
        }

        if ($validated['decision'] !== 'approve') {
            $exceptions->resolveByRule($document, 'overdue_review');
        }

        PortalNotifier::notifyUsersWithPermission(
            'document-intake.view',
            $document->company_id,
            'document_decision',
            "Accounting {$validated['decision']}d {$document->reference_no}",
            $validated['remarks'] ?? null,
            route('document-intake.show', $document->id, false),
        );
        if ($document->vendor) {
            $vendorMessages = [
                'approve' => 'was approved by accounting.',
                'return' => 'was returned — please review the remarks.',
                'reject' => 'was rejected by accounting.',
            ];

            // An approved PO isn't the end of the line for the vendor — it's their
            // cue to bill against it. Spell that out so they don't have to know
            // the process from memory.
            $vendorMessage = $validated['remarks'] ?? null;
            if ($validated['decision'] === 'approve'
                && $document->document_type === 'purchase_order'
                && $document->po_number) {
                $vendorMessage = "You can now submit your invoice referencing PO {$document->po_number}."
                    .($vendorMessage ? "\n\n".$vendorMessage : '');
            }

            PortalNotifier::notifyVendor(
                $document->vendor,
                'document_decision',
                "Document {$document->reference_no} {$vendorMessages[$validated['decision']]}",
                $vendorMessage,
            );
        }

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/integrations/accounting/invoice-payment-status — AP snapshot upsert. */
    public function invoicePaymentStatus(Request $request)
    {
        $validated = $request->validate([
            'vendor_code' => 'required|string|max:50',
            'invoice_no' => 'required|string|max:50',
            'status' => 'required|in:for_collection,processing,partially_paid,paid,on_hold,cancelled',
            'mode_of_payment' => 'nullable|string|max:50',
            'invoice_amount' => 'nullable|numeric',
            'paid_amount' => 'nullable|numeric',
            'outstanding_amount' => 'nullable|numeric',
            'payment_reference_no' => 'nullable|string|max:100',
            'paid_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:500',
            'source_system' => 'nullable|string|max:30',
            'external_ref' => 'nullable|string|max:100',
        ]);

        $vendor = Vendor::where('code', $validated['vendor_code'])->first();
        if (! $vendor) {
            return response()->json(['status' => 'error', 'message' => "Unknown vendor code {$validated['vendor_code']}."], 422);
        }

        $snapshot = ApInvoiceStatus::updateOrCreate(
            ['vendor_id' => $vendor->id, 'invoice_no' => $validated['invoice_no']],
            [
                'status' => $validated['status'],
                'mode_of_payment' => $validated['mode_of_payment'] ?? null,
                'invoice_amount' => $validated['invoice_amount'] ?? null,
                'paid_amount' => $validated['paid_amount'] ?? 0,
                'outstanding_amount' => $validated['outstanding_amount'] ?? null,
                'payment_reference_no' => $validated['payment_reference_no'] ?? null,
                'paid_date' => $validated['paid_date'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'source' => $validated['source_system'] ?? 'ghelpdesk',
                'external_ref' => $validated['external_ref'] ?? null,
                'last_synced_at' => now(),
            ],
        );

        IntegrationCall::create([
            'direction' => 'inbound',
            'system' => $validated['source_system'] ?? 'ghelpdesk',
            'endpoint' => '/api/integrations/accounting/invoice-payment-status',
            'subject_type' => ApInvoiceStatus::class,
            'subject_id' => $snapshot->id,
            'request_payload' => $validated,
            'status' => 'success',
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);

        return response()->json(['status' => 'ok', 'id' => $snapshot->id]);
    }
}
