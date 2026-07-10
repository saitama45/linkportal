<?php

namespace App\Console\Commands;

use App\Http\Services\EmailIntakeService;
use Illuminate\Console\Command;

class FetchIntakeEmails extends Command
{
    protected $signature = 'portal:fetch-intake-emails';

    protected $description = 'Poll the intake mailbox and ingest vendor document attachments';

    public function handle(EmailIntakeService $service): int
    {
        $result = $service->fetchAndProcess();

        $this->line("[{$result['status']}] {$result['message']}");

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
