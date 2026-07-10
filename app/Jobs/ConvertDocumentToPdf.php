<?php

namespace App\Jobs;

use App\Http\Services\DocumentExceptionService;
use App\Http\Services\DocumentIntakeService;
use App\Http\Services\OcrClient;
use App\Http\Services\PortalNotifier;
use App\Models\IntakeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ConvertDocumentToPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $intakeDocumentId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(OcrClient $ocr): void
    {
        $document = IntakeDocument::findOrFail($this->intakeDocumentId);
        $disk = Storage::disk(DocumentIntakeService::DISK);

        if ($document->isPdf()) {
            $document->forceFill(['converted_pdf_path' => $document->file_path])->save();

            return;
        }

        $document->transitionTo(IntakeDocument::STATUS_CONVERTING);

        $outputDir = dirname($document->file_path).'/converted';
        $result = $ocr->convert($disk->path($document->file_path), $disk->path($outputDir));

        // Sidecar returns an absolute path inside the disk; store it relative
        $relative = $outputDir.'/'.basename($result['pdf_path']);

        $document->forceFill([
            'converted_pdf_path' => $relative,
            'page_count' => $result['page_count'] ?? null,
        ])->save();

        $document->recordEvent('converted', 'Converted to PDF', ['page_count' => $result['page_count'] ?? null]);
    }

    public function failed(?\Throwable $exception): void
    {
        $document = IntakeDocument::find($this->intakeDocumentId);
        if (! $document) {
            return;
        }

        $document->transitionTo(IntakeDocument::STATUS_CONVERSION_FAILED);
        app(DocumentExceptionService::class)->raise(
            $document,
            'failed_conversion',
            'PDF conversion failed: '.($exception?->getMessage() ?? 'unknown error'),
        );
        $document->recordEvent('conversion_failed', $exception?->getMessage());

        PortalNotifier::notifyUsersWithPermission(
            'document-exceptions.view',
            $document->company_id,
            'document_exception',
            "Conversion failed for {$document->reference_no}",
            $exception?->getMessage(),
            route('document-intake.show', $document->id, false),
        );
    }
}
