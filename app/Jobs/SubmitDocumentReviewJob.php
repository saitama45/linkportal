<?php

namespace App\Jobs;

use App\Http\Services\DocumentExceptionService;
use App\Http\Services\GhelpdeskClient;
use App\Http\Services\PortalNotifier;
use App\Models\IntakeDocument;
use App\Models\IntegrationCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Hands a validated intake document off to ghelpdesk's Accounting Document
 * Reviews inbox. Idempotent via "lp-doc-{id}-s{submission_count}"; ghelpdesk
 * replays the same review for a repeated key.
 */
class SubmitDocumentReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $intakeDocumentId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(GhelpdeskClient $client): void
    {
        $document = IntakeDocument::with(['vendor', 'company:id,name', 'latestExtraction', 'openExceptions', 'templateVersion'])
            ->findOrFail($this->intakeDocumentId);

        if (! in_array($document->status, [IntakeDocument::STATUS_SENDING, IntakeDocument::STATUS_HANDOFF_FAILED], true)) {
            return; // already handed off or withdrawn while queued
        }

        $payload = $this->buildPayload($document);

        $call = IntegrationCall::create([
            'direction' => 'outbound',
            'system' => 'ghelpdesk',
            'endpoint' => '/api/accounting/document-reviews',
            'idempotency_key' => $payload['idempotency_key'],
            'subject_type' => IntakeDocument::class,
            'subject_id' => $document->id,
            'request_payload' => $payload,
            'status' => 'pending',
            'attempts' => $this->attempts(),
            'last_attempt_at' => now(),
        ]);

        $response = $client->submitDocumentReview($payload);

        $call->update([
            'response_payload' => $response->json(),
            'http_status' => $response->status(),
            'status' => $response->successful() ? 'success' : 'failed',
            'error' => $response->successful() ? null : $response->body(),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("ghelpdesk handoff failed with HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500));
        }

        $document->forceFill([
            'external_review_id' => (string) $response->json('review.id', $response->json('id')),
            'external_status' => 'pending',
            'status' => IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW,
        ])->save();

        // A prior failed attempt leaves an open failed_handoff exception; clear it
        // so it doesn't ride along as a stale warning on the next payload.
        app(DocumentExceptionService::class)->resolveByRule($document, 'failed_handoff', $document->submitted_by, 'handoff_succeeded');

        $document->recordEvent('submitted', 'Sent to Accounting for review', [
            'external_review_id' => $document->external_review_id,
        ]);

        if ($document->vendor) {
            PortalNotifier::notifyVendor(
                $document->vendor,
                'document_submitted',
                "Document {$document->reference_no} sent for accounting review",
            );
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $document = IntakeDocument::find($this->intakeDocumentId);
        if (! $document) {
            return;
        }

        $document->transitionTo(IntakeDocument::STATUS_HANDOFF_FAILED);
        app(DocumentExceptionService::class)->raise(
            $document,
            'failed_handoff',
            'Handoff to ghelpdesk failed: '.($exception?->getMessage() ?? 'unknown error'),
        );
        $document->recordEvent('handoff_failed', $exception?->getMessage());

        PortalNotifier::notifyUsersWithPermission(
            'document-intake.approve',
            $document->company_id,
            'document_exception',
            "Handoff failed for {$document->reference_no}",
            $exception?->getMessage(),
            route('document-intake.show', $document->id, false),
        );
    }

    private function buildPayload(IntakeDocument $document): array
    {
        $extraction = $document->latestExtraction;

        return [
            'idempotency_key' => "lp-doc-{$document->id}-s{$document->submission_count}",
            'source_document_id' => $document->id,
            'reference_no' => $document->reference_no,
            'document_type' => $document->document_type,
            'transaction_type' => $document->transaction_type,
            'transaction_id' => $document->transaction_id,
            'vendor' => [
                'code' => $document->vendor?->code,
                'name' => $document->vendor?->name,
                'erp_vendor_id' => $document->vendor?->erp_vendor_id,
                'company' => $document->company?->name,
            ],
            'fields' => [
                'invoice_no' => $document->invoice_no,
                'po_number' => $document->po_number,
                'document_date' => $document->document_date?->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'currency' => $document->currency,
                'subtotal' => $document->subtotal,
                'tax_amount' => $document->tax_amount,
                'total_amount' => $document->total_amount,
            ] + $document->resolvedFields(),
            'line_items' => $document->resolvedLineItems(),
            'line_item_columns' => $document->lineItemColumns(),
            'confidence' => [
                'overall' => $document->overall_confidence,
                'fields' => collect($extraction?->header_fields ?? [])
                    ->mapWithKeys(fn ($f) => [$f['key'] => $f['confidence'] ?? null])
                    ->all(),
            ],
            // `failed_handoff` is an internal linkportal mechanism exception about
            // a prior send attempt — never a document-data concern. Exclude it so a
            // now-successful handoff doesn't ship its own obsolete failure as a
            // warning to accounting.
            'exceptions' => $document->openExceptions
                ->reject(fn ($e) => $e->rule_key === 'failed_handoff')
                ->map(fn ($e) => ['rule_key' => $e->rule_key, 'severity' => $e->severity, 'message' => $e->message])
                ->values()
                ->all(),
            'file_url' => URL::temporarySignedRoute('integrations.files.show', now()->addDays(7), ['intakeDocument' => $document->id]),
            'file_url_expires_at' => now()->addDays(7)->toIso8601String(),
            'callback_url' => route('api.integrations.document-review-decision'),
            'submitted_at' => now()->toIso8601String(),
            'submitted_by' => $document->submitted_by,
        ];
    }
}
