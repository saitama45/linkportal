<?php

namespace App\Console\Commands;

use App\Http\Services\DocumentExceptionService;
use App\Http\Services\PortalNotifier;
use App\Models\DocumentExceptionRule;
use App\Models\IntakeDocument;
use Illuminate\Console\Command;

/**
 * Nudge on approved purchase orders that have sat unbilled too long. Raises a
 * po_awaiting_invoice_overdue exception (a signal, not a blocker — the PO is
 * already approved) and resolves it once an invoice finally arrives.
 */
class FlagPoAwaitingInvoice extends Command
{
    protected $signature = 'portal:flag-po-awaiting-invoice';

    protected $description = 'Flag approved POs with no invoice after the configured aging threshold';

    public function handle(DocumentExceptionService $exceptions): int
    {
        $rule = DocumentExceptionRule::where('rule_key', 'po_awaiting_invoice_overdue')->first();
        if (! $rule || ! $rule->enabled) {
            $this->line('po_awaiting_invoice_overdue rule disabled; nothing to do.');

            return self::SUCCESS;
        }

        $days = (int) $rule->configValue('overdue_days', 7);
        $cutoff = now()->subDays($days);

        // Approved POs still awaiting an invoice, approved before the cutoff.
        $overdue = IntakeDocument::approvedPoAwaitingInvoice()
            ->where('external_decided_at', '<=', $cutoff)
            ->get();

        foreach ($overdue as $po) {
            $created = $exceptions->raise(
                $po,
                'po_awaiting_invoice_overdue',
                "PO {$po->po_number} was approved {$po->external_decided_at?->diffForHumans()} with no invoice submitted yet.",
                ['approved_at' => $po->external_decided_at?->toIso8601String(), 'overdue_days' => $days],
                'po_number',
            );

            if ($created && $created->wasRecentlyCreated) {
                PortalNotifier::notifyUsersWithPermission(
                    'document-intake.view',
                    $po->company_id,
                    'po_awaiting_invoice',
                    "PO {$po->po_number} still unbilled",
                    "Approved {$po->external_decided_at?->diffForHumans()} — no invoice received.",
                    route('document-intake.show', $po->id, false),
                );
            }
        }

        // Clear the flag on any PO that has since been invoiced. The aging scope
        // already excludes billed POs, so anything with a lingering open flag no
        // longer qualifies and should be auto-resolved.
        $stillOpen = IntakeDocument::where('document_type', 'purchase_order')
            ->where('status', IntakeDocument::STATUS_APPROVED)
            ->whereHas('openExceptions', fn ($q) => $q->where('rule_key', 'po_awaiting_invoice_overdue'))
            ->get();

        $overdueIds = $overdue->pluck('id')->all();
        foreach ($stillOpen as $po) {
            if (! in_array($po->id, $overdueIds, true)) {
                $exceptions->resolveByRule($po, 'po_awaiting_invoice_overdue', null, 'auto-resolved: invoice received');
            }
        }

        $this->line("Flagged {$overdue->count()} overdue PO(s).");

        return self::SUCCESS;
    }
}
