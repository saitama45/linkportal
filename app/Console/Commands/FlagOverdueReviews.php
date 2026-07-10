<?php

namespace App\Console\Commands;

use App\Http\Services\DocumentExceptionService;
use App\Http\Services\PortalNotifier;
use App\Models\DocumentExceptionRule;
use App\Models\IntakeDocument;
use Illuminate\Console\Command;

class FlagOverdueReviews extends Command
{
    protected $signature = 'portal:flag-overdue-reviews';

    protected $description = 'Raise overdue_review exceptions for documents stuck in external review';

    public function handle(DocumentExceptionService $exceptions): int
    {
        $rule = DocumentExceptionRule::where('rule_key', 'overdue_review')->first();
        if (! $rule || ! $rule->enabled) {
            $this->line('overdue_review rule disabled; nothing to do.');

            return self::SUCCESS;
        }

        $days = (int) $rule->configValue('overdue_days', 3);
        $cutoff = now()->subDays($days);

        $overdue = IntakeDocument::where('status', IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW)
            ->where('submitted_at', '<=', $cutoff)
            ->get();

        foreach ($overdue as $document) {
            $created = $exceptions->raise(
                $document,
                'overdue_review',
                "External review pending for more than {$days} day(s) (submitted {$document->submitted_at?->diffForHumans()}).",
                ['submitted_at' => $document->submitted_at?->toIso8601String(), 'overdue_days' => $days],
            );

            if ($created && $created->wasRecentlyCreated) {
                PortalNotifier::notifyUsersWithPermission(
                    'document-intake.view',
                    $document->company_id,
                    'document_overdue',
                    "Review overdue for {$document->reference_no}",
                    'The accounting review has exceeded the SLA.',
                    route('document-intake.show', $document->id, false),
                );
            }
        }

        $this->line("Checked {$overdue->count()} overdue document(s).");

        return self::SUCCESS;
    }
}
