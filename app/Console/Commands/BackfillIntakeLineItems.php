<?php

namespace App\Console\Commands;

use App\Models\IntakeDocument;
use Illuminate\Console\Command;

class BackfillIntakeLineItems extends Command
{
    protected $signature = 'portal:backfill-line-items';

    protected $description = 'Rebuild the flat portal_intake_line_items table from existing validated documents.';

    public function handle(): int
    {
        $count = 0;

        IntakeDocument::query()
            ->where(fn ($q) => $q->whereNotNull('validated_line_items')->orWhereNotNull('validated_at'))
            ->with('latestExtraction')
            ->chunkById(100, function ($documents) use (&$count) {
                foreach ($documents as $document) {
                    $document->syncLineItems();
                    $count++;
                }
            });

        $this->info("Synced line items for {$count} document(s).");

        return self::SUCCESS;
    }
}
