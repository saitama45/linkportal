<?php

namespace App\Http\Services;

use App\Jobs\ConvertDocumentToPdf;
use App\Jobs\ExtractDocumentData;
use App\Models\InboundEmail;
use App\Models\IntakeDocument;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentIntakeService
{
    public const DISK = 'local'; // private: storage/app/private

    public function __construct(private DocumentExceptionService $exceptions)
    {
    }

    public function createFromUpload(Vendor $vendor, UploadedFile $file, ?string $documentType): IntakeDocument
    {
        $document = $this->create([
            'vendor_id' => $vendor->id,
            'company_id' => $vendor->company_id,
            'document_type' => $documentType,
            'source' => 'portal_upload',
            'uploaded_by_vendor_user' => true,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ], fn (string $dir) => $file->storeAs($dir, 'original.'.strtolower($file->getClientOriginalExtension()), self::DISK));

        $document->recordEvent('received', 'Uploaded via vendor portal', [], 'vendor', $vendor->id);
        $this->exceptions->evaluate($document, 'intake');
        $this->dispatchPipelineIfReady($document);

        return $document;
    }

    /**
     * Admin uploads a document on a vendor's behalf (walk-in/scanned docs or
     * testing). Same pipeline as the vendor flow, but attributed to the admin.
     */
    public function createFromAdminUpload(Vendor $vendor, UploadedFile $file, ?string $documentType, int $userId): IntakeDocument
    {
        $document = $this->create([
            'vendor_id' => $vendor->id,
            'company_id' => $vendor->company_id,
            'document_type' => $documentType,
            'source' => 'portal_upload',
            'uploaded_by_vendor_user' => false,
            'created_by' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ], fn (string $dir) => $file->storeAs($dir, 'original.'.strtolower($file->getClientOriginalExtension()), self::DISK));

        $document->recordEvent('received', 'Uploaded by admin on behalf of vendor', [], 'user', $userId);
        $this->exceptions->evaluate($document, 'intake');
        $this->dispatchPipelineIfReady($document);

        return $document;
    }

    public function createFromEmail(InboundEmail $email, string $contents, string $filename, ?Vendor $vendor): IntakeDocument
    {
        $document = $this->create([
            'vendor_id' => $vendor?->id,
            'company_id' => $vendor?->company_id,
            'document_type' => null, // classified manually in Phase 1
            'source' => 'email',
            'inbound_email_id' => $email->id,
            'original_filename' => $filename,
            'file_size' => strlen($contents),
        ], function (string $dir) use ($contents, $filename) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'bin';
            $path = $dir.'/original.'.$ext;
            Storage::disk(self::DISK)->put($path, $contents);

            return $path;
        });

        $document->recordEvent('received', "Received via email from {$email->from_email}");
        $this->exceptions->evaluate($document, 'intake');
        $this->dispatchPipelineIfReady($document);

        return $document;
    }

    /**
     * Resolve an unmatched/unclassified document (from the exception queue)
     * and kick the pipeline once vendor + type are known.
     */
    public function assignVendorAndType(IntakeDocument $document, ?int $vendorId, ?string $documentType, ?int $userId = null): IntakeDocument
    {
        // Set on first classification, and allow an admin to correct it later
        // (changing vendor/type re-resolves which OCR template is used).
        if ($vendorId) {
            $vendor = Vendor::findOrFail($vendorId);
            $document->fill(['vendor_id' => $vendor->id, 'company_id' => $vendor->company_id]);
        }
        if ($documentType) {
            $document->document_type = $documentType;
        }
        $document->save();
        $document->recordEvent('classified', 'Vendor/type assigned by admin', [
            'vendor_id' => $document->vendor_id,
            'document_type' => $document->document_type,
        ], 'user', $userId);

        $this->exceptions->evaluate($document->fresh(['vendor', 'inboundEmail']), 'intake');
        $this->dispatchPipelineIfReady($document);

        return $document;
    }

    /**
     * Re-run conversion (if needed) + extraction, e.g. after a template change
     * or a transient failure.
     */
    public function reprocess(IntakeDocument $document, ?int $userId = null): void
    {
        $document->recordEvent('reprocessed', 'Pipeline re-run requested', [], $userId ? 'user' : 'system', $userId);
        $this->dispatchPipeline($document);
    }

    public function dispatchPipelineIfReady(IntakeDocument $document): void
    {
        if (! $document->vendor_id || ! $document->document_type) {
            return; // held in `received` until classified; blockers explain why
        }
        if ($document->openExceptions()->where('rule_key', 'unsupported_file')->exists()) {
            return;
        }
        $this->dispatchPipeline($document);
    }

    private function dispatchPipeline(IntakeDocument $document): void
    {
        Bus::chain([
            new ConvertDocumentToPdf($document->id),
            new ExtractDocumentData($document->id),
        ])->dispatch();
    }

    private function create(array $attributes, callable $storeFile): IntakeDocument
    {
        $directory = sprintf(
            'portal/intake/%s/%s',
            $attributes['vendor_id'] ?? 'unmatched',
            Str::uuid()->toString(),
        );

        $path = $storeFile($directory);
        $absolute = Storage::disk(self::DISK)->path($path);

        $document = IntakeDocument::create($attributes + [
            'reference_no' => NumberingService::next('intake_document', $attributes['company_id'] ?? null),
            'file_path' => $path,
            'file_hash' => hash_file('sha256', $absolute),
            'status' => IntakeDocument::STATUS_RECEIVED,
        ]);

        return $document->load(['vendor', 'inboundEmail']);
    }
}
