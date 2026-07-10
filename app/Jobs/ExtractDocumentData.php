<?php

namespace App\Jobs;

use App\Http\Services\DocumentExceptionService;
use App\Http\Services\DocumentIntakeService;
use App\Http\Services\OcrClient;
use App\Http\Services\PortalNotifier;
use App\Models\DocumentTemplate;
use App\Models\IntakeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ExtractDocumentData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Extraction field keys promoted onto portal_intake_documents columns. */
    private const PROMOTED = [
        'invoice_no', 'po_number', 'document_date', 'due_date',
        'subtotal', 'tax_amount', 'total_amount',
    ];

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $intakeDocumentId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(OcrClient $ocr, DocumentExceptionService $exceptions): void
    {
        $document = IntakeDocument::with('vendor')->findOrFail($this->intakeDocumentId);
        $disk = Storage::disk(DocumentIntakeService::DISK);

        if (! $document->converted_pdf_path) {
            throw new \RuntimeException("Intake document {$document->id} has no converted PDF to extract.");
        }

        $document->transitionTo(IntakeDocument::STATUS_EXTRACTING);
        $pdfPath = $disk->path($document->converted_pdf_path);

        $template = $document->document_type
            ? DocumentTemplate::resolveFor($document->vendor_id, $document->document_type)
            : null;
        $version = $template?->activeVersion;

        $analysis = $ocr->analyze($pdfPath);
        $result = $ocr->extract($pdfPath, $version->annotations ?? []);

        $extraction = $document->extractions()->create([
            'template_version_id' => $version?->id,
            'attempt_no' => ($document->extractions()->max('attempt_no') ?? 0) + 1,
            'engine_used' => $result['engine_used'] ?? null,
            'header_fields' => $result['fields'] ?? [],
            'line_items' => $result['line_items'] ?? [],
            'totals_check' => $result['totals_check'] ?? null,
            'overall_confidence' => $result['overall_confidence'] ?? 0,
            'status' => 'completed',
            'duration_ms' => $result['duration_ms'] ?? null,
        ]);

        // Once an admin has validated, their corrected values own the promoted columns
        $promoted = $document->validated_at ? [] : $this->promotedValues($result['fields'] ?? []);
        $document->forceFill($promoted + [
            'page_count' => $analysis['page_count'] ?? $document->page_count,
            'page_meta' => $analysis['pages'] ?? null,
            'template_version_id' => $version?->id,
            'overall_confidence' => $result['overall_confidence'] ?? 0,
            'status' => IntakeDocument::STATUS_NEEDS_VALIDATION,
        ])->save();

        $document->recordEvent('extracted', $version
            ? "Extracted with template \"{$template->name}\" v{$version->version_no}"
            : 'Processed without a template', [
                'attempt_no' => $extraction->attempt_no,
                'overall_confidence' => $result['overall_confidence'] ?? 0,
            ]);

        $exceptions->evaluate($document->fresh(['vendor', 'latestExtraction']), 'extraction');
    }

    public function failed(?\Throwable $exception): void
    {
        $document = IntakeDocument::find($this->intakeDocumentId);
        if (! $document) {
            return;
        }

        $document->transitionTo(IntakeDocument::STATUS_EXTRACTION_FAILED);
        $document->extractions()->create([
            'attempt_no' => ($document->extractions()->max('attempt_no') ?? 0) + 1,
            'status' => 'failed',
            'error_message' => $exception?->getMessage(),
        ]);
        $document->recordEvent('extraction_failed', $exception?->getMessage());

        PortalNotifier::notifyUsersWithPermission(
            'document-exceptions.view',
            $document->company_id,
            'document_exception',
            "OCR extraction failed for {$document->reference_no}",
            $exception?->getMessage(),
            route('document-intake.show', $document->id, false),
        );
    }

    /**
     * Map extraction fields onto the promoted columns, guarding types so a bad
     * OCR value can't break the save.
     */
    private function promotedValues(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (! in_array($key, self::PROMOTED, true)) {
                continue;
            }
            $value = $field['value'] ?? null;

            if (in_array($key, ['document_date', 'due_date'], true)) {
                $values[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : null;
            } elseif (in_array($key, ['subtotal', 'tax_amount', 'total_amount'], true)) {
                $values[$key] = is_numeric($value) ? round((float) $value, 2) : null;
            } else {
                $values[$key] = $value !== null ? mb_substr(trim((string) $value), 0, 50) : null;
            }
        }

        return $values;
    }
}
