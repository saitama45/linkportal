<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\DocumentIntakeService;
use App\Models\IntakeDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Vendor-facing OCR intake: upload invoices/POs/quotations as files instead of
 * encoding them. Distinct from DocumentController (accreditation documents).
 */
class DocumentUploadController extends Controller
{
    public function __construct(private DocumentIntakeService $intake)
    {
    }

    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        $query = IntakeDocument::forVendor($vendor->id)
            ->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_no', 'like', "%{$request->search}%")
                    ->orWhere('original_filename', 'like', "%{$request->search}%")
                    ->orWhere('invoice_no', 'like', "%{$request->search}%");
            });
        }

        return Inertia::render('Vendor/DocumentUploads/Index', [
            'documents' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Vendor/DocumentUploads/Create', [
            'documentTypes' => IntakeDocument::DOCUMENT_TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $vendor = $request->user('vendor');

        $validated = $request->validate([
            'document_type' => 'required|in:invoice,purchase_order,quotation',
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx',
        ]);

        $document = $this->intake->createFromUpload($vendor, $request->file('file'), $validated['document_type']);

        AuditLogger::log('intake_document_uploaded', $document);

        return redirect()
            ->route('vendor.document-uploads.show', $document->id)
            ->with('success', "Document {$document->reference_no} received and queued for processing.");
    }

    public function show(Request $request, IntakeDocument $documentUpload)
    {
        abort_unless($documentUpload->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/DocumentUploads/Show', [
            'document' => $documentUpload->load(['events'])
                ->only([
                    'id', 'reference_no', 'document_type', 'source', 'original_filename',
                    'status', 'invoice_no', 'po_number', 'document_date', 'due_date',
                    'currency', 'subtotal', 'tax_amount', 'total_amount',
                    'external_decision', 'external_decision_remarks', 'external_decided_at',
                    'created_at', 'events',
                ]),
        ]);
    }

    public function cancel(Request $request, IntakeDocument $documentUpload)
    {
        $vendor = $request->user('vendor');
        abort_unless($documentUpload->vendor_id === $vendor->id, 403);

        $cancellable = [
            IntakeDocument::STATUS_RECEIVED,
            IntakeDocument::STATUS_CONVERSION_FAILED,
            IntakeDocument::STATUS_EXTRACTION_FAILED,
            IntakeDocument::STATUS_NEEDS_VALIDATION,
            IntakeDocument::STATUS_RETURNED,
        ];
        if (! in_array($documentUpload->status, $cancellable, true)) {
            return redirect()->back()->with('error', 'This document can no longer be cancelled.');
        }

        $documentUpload->transitionTo(IntakeDocument::STATUS_CANCELLED);
        $documentUpload->recordEvent('cancelled', 'Cancelled by vendor', [], 'vendor', $vendor->id);
        AuditLogger::log('intake_document_cancelled', $documentUpload);

        return redirect()->back()->with('success', 'Document cancelled.');
    }
}
