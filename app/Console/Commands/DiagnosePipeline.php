<?php

namespace App\Console\Commands;

use App\Http\Services\DocumentIntakeService;
use App\Models\DocumentTemplate;
use App\Models\IntakeDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-end health check for the OCR intake pipeline.
 *
 * A document that sits in `received` with nothing extracted has failed at one of
 * a handful of links, and this container has no SSH to poke at them: the queued
 * jobs may never be claimed (no worker, or another app sharing the `jobs` table
 * claimed them), the OCR sidecar may be down, or the storage share may not be
 * writable. Each check below reports the state of one link so the failing one is
 * named rather than guessed at.
 *
 * Run in the container via the entrypoint (set RUN_PIPELINE_DIAGNOSTICS=true and
 * restart, then read the Log stream), or locally: `php artisan portal:diagnose`.
 */
class DiagnosePipeline extends Command
{
    protected $signature = 'portal:diagnose {document? : An intake document id to trace}';

    protected $description = 'Check the OCR intake pipeline: queue, sidecar, storage, templates.';

    private array $problems = [];

    public function handle(): int
    {
        $this->line('===== [portal:diagnose] OCR intake pipeline =====');

        $this->checkQueue();
        $this->checkOcrService();
        $this->checkStorage();
        $this->checkTemplates();

        if ($id = $this->argument('document')) {
            $this->traceDocument((int) $id);
        }

        $this->newLine();
        if ($this->problems) {
            $this->error('PROBLEMS FOUND:');
            foreach ($this->problems as $problem) {
                $this->error('  - '.$problem);
            }
        } else {
            $this->info('All checks passed.');
        }
        $this->line('===== [portal:diagnose] end =====');

        return self::SUCCESS;
    }

    private function flag(string $problem): void
    {
        $this->problems[] = $problem;
    }

    /**
     * The pipeline is a queued chain, so a stalled document is most often a queue
     * problem. `sync` runs inline (nothing to wait for); any other driver needs a
     * worker, and a backlog of pending jobs means none is consuming them.
     */
    private function checkQueue(): void
    {
        $this->newLine();
        $this->line('-- Queue --');
        $connection = config('queue.default');
        $queue = config('queue.portal');
        $this->line("connection: {$connection} | portal queue: {$queue}");

        if ($connection === 'sync') {
            $this->line('sync: jobs run inline during the request; no worker needed.');

            return;
        }

        if (! Schema::hasTable('jobs')) {
            $this->flag("The `jobs` table does not exist, so nothing can be queued (connection is `{$connection}`).");

            return;
        }

        $pending = DB::table('jobs')->count();
        $mine = DB::table('jobs')->where('queue', $queue)->count();
        $this->line("pending jobs: {$pending} (on '{$queue}': {$mine})");

        if ($mine > 0) {
            $oldest = DB::table('jobs')->where('queue', $queue)->min('created_at');
            $age = $oldest ? now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($oldest)) : 0;
            if ($age > 5) {
                $this->flag("{$mine} job(s) have waited {$age}m on '{$queue}' — the queue worker is not consuming them.");
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();
            $this->line("failed jobs (all apps sharing this DB): {$failed}");
            $recent = DB::table('failed_jobs')->orderByDesc('id')->limit(3)->get(['id', 'queue', 'payload', 'exception']);
            foreach ($recent as $row) {
                $name = json_decode($row->payload, true)['displayName'] ?? 'unknown';
                $first = strtok((string) $row->exception, "\n");
                $this->line("  #{$row->id} [{$row->queue}] {$name}: ".mb_substr($first, 0, 160));
                if (str_contains((string) $row->exception, 'Class') && str_contains((string) $row->exception, 'not found')) {
                    $this->flag("Failed job #{$row->id} could not resolve its class — another app sharing this `jobs` table claimed a job it cannot run.");
                }
            }
        }
    }

    /** The sidecar is bound to localhost inside the container; if it is down, every extraction fails. */
    private function checkOcrService(): void
    {
        $this->newLine();
        $this->line('-- OCR sidecar --');
        $url = config('services.ocr.base_url');
        $this->line("base_url: ".($url ?: '(empty)'));

        if (! $url) {
            $this->flag('services.ocr.base_url is empty — set OCR_SERVICE_URL.');

            return;
        }

        try {
            $response = Http::timeout(10)->get(rtrim($url, '/').'/health');
            if ($response->successful()) {
                $this->line('health: '.mb_substr($response->body(), 0, 300));
            } else {
                $this->flag("OCR sidecar returned HTTP {$response->status()} from /health.");
            }
        } catch (\Throwable $e) {
            $this->flag('OCR sidecar unreachable at '.$url.' — '.$e->getMessage());
        }
    }

    /** Uploads live on a mounted share; if the worker cannot write there, conversion dies. */
    private function checkStorage(): void
    {
        $this->newLine();
        $this->line('-- Storage --');
        $disk = Storage::disk(DocumentIntakeService::DISK);
        $probe = 'portal/.diagnose-'.uniqid();

        try {
            $disk->put($probe, 'ok');
            $readable = $disk->get($probe) === 'ok';
            $disk->delete($probe);
            $this->line('intake disk: writable'.($readable ? ' and readable' : ' but NOT readable'));
            if (! $readable) {
                $this->flag('The intake disk accepted a write but could not read it back.');
            }
        } catch (\Throwable $e) {
            $this->flag('Intake disk is not writable — '.$e->getMessage());
        }
    }

    private function checkTemplates(): void
    {
        $this->newLine();
        $this->line('-- Templates --');
        $active = DocumentTemplate::where('status', 'active')->whereNotNull('active_version_id')->count();
        $orphan = DocumentTemplate::where('status', 'active')->whereNull('active_version_id')->count();
        $this->line("active with a version: {$active} | active WITHOUT an active version: {$orphan}");

        if ($orphan > 0) {
            $this->flag("{$orphan} active template(s) have no active version — those are skipped when matching.");
        }
    }

    /** Walk one document's own state, which localises the stall to a specific step. */
    private function traceDocument(int $id): void
    {
        $this->newLine();
        $this->line("-- Document {$id} --");
        $document = IntakeDocument::with(['vendor', 'latestExtraction'])->find($id);

        if (! $document) {
            $this->flag("Intake document {$id} not found.");

            return;
        }

        $this->line("ref: {$document->reference_no} | status: {$document->status} | type: ".($document->document_type ?: '(none)'));
        $this->line('vendor_id: '.($document->vendor_id ?: '(none)').' | template_version_id: '.($document->template_version_id ?: '(none)'));
        $this->line('converted_pdf_path: '.($document->converted_pdf_path ?: '(none)'));
        $this->line('extractions: '.$document->extractions()->count());

        if (! $document->vendor_id || ! $document->document_type) {
            $this->flag("Document {$id} is missing a vendor or document type, so the pipeline is intentionally held.");
        }

        if ($document->document_type) {
            $template = DocumentTemplate::resolveFor($document->vendor_id, $document->document_type);
            $this->line('template match: '.($template
                ? "{$template->name} (active version ".($template->active_version_id ?: 'NONE').')'
                : 'none — would extract without a template'));
        }

        $blocking = $document->openExceptions()->where('rule_key', 'unsupported_file')->exists();
        if ($blocking) {
            $this->flag("Document {$id} has an open `unsupported_file` exception, which blocks the pipeline.");
        }

        if ($document->status === IntakeDocument::STATUS_RECEIVED && $document->extractions()->count() === 0) {
            $this->flag("Document {$id} never left `received` — its queued jobs were never executed (see the Queue section).");
        }
    }
}
