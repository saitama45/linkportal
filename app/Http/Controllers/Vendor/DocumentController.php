<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\PortalNotifier;
use App\Models\ReferenceOption;
use App\Models\VendorDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        return Inertia::render('Vendor/Documents', [
            'documents' => VendorDocument::with('documentType:id,label')
                ->where('vendor_id', $vendor->id)
                ->latest()
                ->get(),
            'documentTypes' => ReferenceOption::ofType('document_type')->get(['id', 'label']),
        ]);
    }

    public function store(Request $request)
    {
        $vendor = $request->user('vendor');

        $validated = $request->validate([
            'document_type_id' => 'required|exists:portal_reference_options,id',
            'title' => 'required|string|max:255',
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issued_date',
            'supersedes_id' => 'nullable|exists:portal_vendor_documents,id',
        ]);

        $file = $request->file('file');
        $path = $file->store("portal/vendors/{$vendor->id}/documents", 'public');

        $version = 1;
        if (! empty($validated['supersedes_id'])) {
            $previous = VendorDocument::where('vendor_id', $vendor->id)->find($validated['supersedes_id']);
            $version = $previous ? $previous->version + 1 : 1;
        }

        $document = VendorDocument::create([
            'vendor_id' => $vendor->id,
            'document_type_id' => $validated['document_type_id'],
            'title' => $validated['title'],
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'issued_date' => $validated['issued_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'version' => $version,
            'supersedes_id' => $validated['supersedes_id'] ?? null,
            'status' => 'pending',
        ]);

        AuditLogger::log('document_uploaded', $document);

        PortalNotifier::notifyUsersWithPermission(
            'vendor-documents.approve',
            $vendor->company_id,
            'document_pending',
            'Vendor document pending approval',
            "Vendor \"{$vendor->name}\" uploaded \"{$document->title}\" for accreditation review.",
            null
        );

        return redirect()->back()->with('success', 'Document uploaded and submitted for approval.');
    }

    public function destroy(Request $request, VendorDocument $document)
    {
        abort_unless($document->vendor_id === $request->user('vendor')->id, 403);

        if ($document->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending documents can be removed.');
        }

        AuditLogger::log('document_deleted', $document);
        $document->delete();

        return redirect()->back()->with('success', 'Document removed.');
    }
}
