<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\DocumentIntakeService;
use App\Models\IntakeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
                    ->orWhere('invoice_no', 'like', "%{$request->search}%")
                    ->orWhere('po_number', 'like', "%{$request->search}%");
            });
        }
        if (in_array($request->document_type, IntakeDocument::DOCUMENT_TYPES, true)) {
            $query->where('document_type', $request->document_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $documents = $query->paginate(20)->withQueryString();
        $this->attachPoFulfillment($documents->getCollection(), $vendor->id);

        return Inertia::render('Vendor/DocumentUploads/Index', [
            'documents' => Inertia::scroll($documents),
            'filters' => $request->only(['search', 'document_type', 'status']),
            'documentTypes' => IntakeDocument::DOCUMENT_TYPES,
        ]);
    }

    /**
     * Tag each approved purchase-order row on this page with its fulfillment
     * ('open' | 'partially_invoiced' | 'fully_invoiced'). One grouped query
     * covers the whole page rather than a per-row lookup.
     */
    private function attachPoFulfillment($documents, int $vendorId): void
    {
        $poNumbers = $documents
            ->filter(fn ($d) => $d->document_type === 'purchase_order'
                && $d->status === IntakeDocument::STATUS_APPROVED
                && $d->po_number)
            ->pluck('po_number')
            ->unique();

        if ($poNumbers->isEmpty()) {
            return;
        }

        // Sum billed (non-cancelled/rejected invoices) per PO number, this vendor.
        // No ->with() here: the primary key isn't selected under group-by, so an
        // eager load would silently null out relations — aggregate only.
        $billed = IntakeDocument::query()
            ->where('vendor_id', $vendorId)
            ->where('document_type', 'invoice')
            ->whereIn('po_number', $poNumbers)
            ->whereNotIn('status', [IntakeDocument::STATUS_CANCELLED, IntakeDocument::STATUS_REJECTED])
            ->groupBy('po_number')
            ->select('po_number', DB::raw('SUM(total_amount) as billed'))
            ->pluck('billed', 'po_number');

        $validityDays = IntakeDocument::poValidityDays();

        foreach ($documents as $doc) {
            if ($doc->document_type !== 'purchase_order'
                || $doc->status !== IntakeDocument::STATUS_APPROVED
                || ! $doc->po_number) {
                continue;
            }

            $billedForPo = (float) ($billed[$doc->po_number] ?? 0);
            $poTotal = (float) ($doc->total_amount ?? 0);
            $fullyInvoiced = $poTotal > 0 && $billedForPo >= $poTotal * 0.99;

            // Expiry only matters while there is still something to bill.
            $expired = ! $fullyInvoiced
                && $validityDays
                && $doc->external_decided_at
                && $doc->external_decided_at->lt(now()->subDays($validityDays));

            $doc->fulfillment = match (true) {
                $expired => 'expired',
                $billedForPo <= 0 => 'open',
                $fullyInvoiced => 'fully_invoiced',
                default => 'partially_invoiced',
            };
        }
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

        $documentUpload->load(['events', 'templateVersion', 'latestExtraction']);

        return Inertia::render('Vendor/DocumentUploads/Show', [
            'document' => $documentUpload->only([
                'id', 'reference_no', 'document_type', 'source', 'original_filename',
                'status', 'invoice_no', 'po_number', 'document_date', 'due_date',
                'currency', 'subtotal', 'tax_amount', 'total_amount',
                'external_decision', 'external_decision_remarks', 'external_decided_at',
                'created_at', 'events',
            ]),
            // The same resolved set the accounting handoff carries — corrected
            // lines when staff have validated them, the raw extraction until
            // then — so a vendor sees exactly what goes forward for review.
            'lineItems' => $documentUpload->resolvedLineItems(),
            'lineItemColumns' => $documentUpload->lineItemColumns(),
        ]);
    }

    /**
     * Stream the vendor's own uploaded document for the in-page viewer. Serves
     * the converted PDF when the original was an office file, so the viewer
     * always receives something it can render.
     */
    public function file(Request $request, IntakeDocument $documentUpload)
    {
        abort_unless($documentUpload->vendor_id === $request->user('vendor')->id, 403);

        $path = $documentUpload->converted_pdf_path ?? $documentUpload->file_path;
        abort_unless($path && Storage::disk(DocumentIntakeService::DISK)->exists($path), 404);

        return Storage::disk(DocumentIntakeService::DISK)->response($path, $documentUpload->original_filename);
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
