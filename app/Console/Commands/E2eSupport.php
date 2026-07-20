<?php

namespace App\Console\Commands;

use App\Http\Services\DocumentIntakeService;
use App\Models\ApInvoiceStatus;
use App\Models\Company;
use App\Models\DocumentEvent;
use App\Models\DocumentException;
use App\Models\InboundEmail;
use App\Models\IntakeDocument;
use App\Models\IntakeLineItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Test-support for the browser QA suite (e2e/). Every row it creates carries the
 * `E2E-` marker on reference_no / original_filename / invoice_no, and `purge`
 * only ever deletes rows with that marker — so it can run against the local dev
 * database without disturbing real data. Refuses to run in production.
 *
 * Output is JSON on stdout so the Playwright helper can parse ids/tokens.
 */
class E2eSupport extends Command
{
    protected $signature = 'portal:e2e {action : seed-demo|seed-po|seed-overbill|seed-partial|seed-full|seed-pomismatch|seed-expired|inject-email|seed-pending|token|purge|purge-demo} {type=invoice : document type for seed-pending (invoice|purchase_order|quotation)}';

    protected $description = 'Create / clean up marked fixtures for the browser QA suite (non-production only)';

    private const MARK = 'E2E-';

    public function handle(DocumentIntakeService $intake): int
    {
        if (app()->environment('production')) {
            $this->error('portal:e2e is disabled in production.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed-demo' => $this->seedDemo($intake),
            'seed-po' => $this->seedPo(),
            'seed-overbill' => $this->seedOverbill(),
            'seed-partial' => $this->seedPartial(),
            'seed-full' => $this->seedFull(),
            'seed-pomismatch' => $this->seedPoMismatch(),
            'seed-expired' => $this->seedExpired(),
            'inject-email' => $this->injectEmail($intake),
            'seed-pending' => $this->seedPending(),
            'token' => $this->issueToken(),
            'purge' => $this->purge(),
            'purge-demo' => $this->purgeDemo(),
            default => $this->errorOut('Unknown action.'),
        };
    }

    private function testVendor(): Vendor
    {
        $email = env('E2E_VENDOR_EMAIL', 'pcworx@test.com');

        return Vendor::where('email', $email)->firstOrFail();
    }

    private function companyId(Vendor $vendor): int
    {
        return $vendor->company_id ?? Company::query()->value('id');
    }

    // ---- reusable makers ----

    private function makePo(float $total, ?string $poNumber = null, int $approvedDaysAgo = 0): IntakeDocument
    {
        $vendor = $this->testVendor();

        return IntakeDocument::create([
            'reference_no' => self::MARK.'PO-'.strtoupper(Str::random(6)), 'vendor_id' => $vendor->id,
            'company_id' => $this->companyId($vendor), 'document_type' => 'purchase_order', 'source' => 'portal_upload',
            'status' => IntakeDocument::STATUS_APPROVED, 'po_number' => $poNumber ?: self::MARK.'PONUM-'.strtoupper(Str::random(5)),
            'total_amount' => $total, 'external_decided_at' => now()->subDays($approvedDaysAgo), 'external_decision' => 'approve',
            'original_filename' => self::MARK.'po.pdf', 'file_hash' => self::MARK.Str::random(12), 'file_path' => 'e2e/po.pdf',
        ]);
    }

    private function makeInvoice(string $poNumber, float $total, string $status = IntakeDocument::STATUS_NEEDS_VALIDATION): IntakeDocument
    {
        $vendor = $this->testVendor();
        $inv = IntakeDocument::create([
            'reference_no' => self::MARK.'INV-'.strtoupper(Str::random(6)), 'vendor_id' => $vendor->id,
            'company_id' => $this->companyId($vendor), 'document_type' => 'invoice', 'source' => 'portal_upload',
            'status' => $status, 'invoice_no' => self::MARK.'INV-'.strtoupper(Str::random(4)),
            'po_number' => $poNumber, 'total_amount' => $total, 'original_filename' => self::MARK.'invoice.pdf',
            'file_hash' => self::MARK.Str::random(12), 'file_path' => 'e2e/invoice.pdf',
        ]);
        $inv->resolveMatchedPo();

        return $inv;
    }

    private function evaluate(IntakeDocument $inv): void
    {
        app(\App\Http\Services\DocumentExceptionService::class)->evaluate($inv->fresh(['vendor']), 'extraction');
    }

    /** A PO 40% billed — shows as "Partially Invoiced" with a remaining balance. */
    private function seedPartial(): int
    {
        $po = $this->makePo(10000);
        $inv = $this->makeInvoice($po->po_number, 4000, IntakeDocument::STATUS_APPROVED);

        return $this->emit(['po_id' => $po->id, 'po_number' => $po->po_number, 'invoice_id' => $inv->id]);
    }

    /** A PO billed in full across two invoices — shows as "Fully Invoiced". */
    private function seedFull(): int
    {
        $po = $this->makePo(10000);
        $this->makeInvoice($po->po_number, 6000, IntakeDocument::STATUS_APPROVED);
        $this->makeInvoice($po->po_number, 4000, IntakeDocument::STATUS_APPROVED);

        return $this->emit(['po_id' => $po->id, 'po_number' => $po->po_number]);
    }

    /** An invoice whose PO number matches no PO — raises po_mismatch. */
    private function seedPoMismatch(): int
    {
        $realPo = $this->makePo(5000); // so the vendor has a PO to reconcile against
        $inv = $this->makeInvoice(self::MARK.'WRONG-'.strtoupper(Str::random(4)), 1000);
        $this->evaluate($inv);

        return $this->emit(['invoice_id' => $inv->id, 'real_po_number' => $realPo->po_number]);
    }

    /**
     * Temporarily switches on the PO-expiration policy (30 days) and bills a PO
     * approved 40 days ago — raises po_expired. `purge` resets the policy to the
     * default (never). Assumes the policy was at its default beforehand.
     */
    private function seedExpired(): int
    {
        // Update through a model instance so the array→json cast applies (a
        // query-builder update would write the config column unparsed).
        $rule = \App\Models\DocumentExceptionRule::where('rule_key', 'po_expired')->firstOrFail();
        $rule->update(['config' => ['validity_days' => 30], 'enabled' => true]);

        $po = $this->makePo(5000, null, 40);
        $inv = $this->makeInvoice($po->po_number, 1000);
        $this->evaluate($inv);

        return $this->emit(['invoice_id' => $inv->id, 'po_id' => $po->id, 'validity_days' => 30]);
    }

    /**
     * Populate EDITABLE demo documents from REAL sample files (e2e/fixtures),
     * run through the actual OCR pipeline — so each has a viewable PDF and
     * extracted fields — and keep them for a manual walkthrough. They land in
     * needs_validation (NOT approved). Marked via an `E2E-DEMO ` filename prefix
     * so automated-test `purge` leaves them and `purge-demo` clears them.
     */
    private function seedDemo(DocumentIntakeService $intake): int
    {
        $vendor = $this->testVendor();
        $userId = User::query()->value('id');
        $this->ensureInvoiceTemplate($vendor, $userId);

        // The quotation matches the existing PO template; the invoice matches the
        // template ensured above. Both are real PC Worx documents.
        $samples = [
            ['file' => 'demo-quotation.pdf', 'name' => self::MARK.'DEMO Monitor Signed Quotation.pdf', 'type' => 'purchase_order'],
            ['file' => 'demo-invoice.pdf', 'name' => self::MARK.'DEMO Invoice 11612.pdf', 'type' => 'invoice'],
        ];

        $created = [];
        foreach ($samples as $s) {
            $abs = base_path('e2e/fixtures/'.$s['file']);
            if (! is_file($abs)) {
                $this->warn("Missing sample file: {$abs}");

                continue;
            }
            $upload = new \Illuminate\Http\UploadedFile($abs, $s['name'], 'application/pdf', null, true);
            $doc = $intake->createFromAdminUpload($vendor, $upload, $s['type'], $userId);
            $created[] = ['id' => $doc->id, 'reference_no' => $doc->reference_no, 'type' => $s['type'], 'status' => $doc->fresh()->status];
        }

        return $this->emit([
            'seeded' => $created,
            'note' => 'real files via the OCR pipeline; editable (needs_validation); kept until `php artisan portal:e2e purge-demo`',
        ]);
    }

    /**
     * Create the vendor's invoice OCR template (idempotent) so invoice uploads
     * have a template to extract against. Annotations are estimated from the
     * sample; refine them in the OCR Templates editor if extraction is off.
     */
    private function ensureInvoiceTemplate(Vendor $vendor, ?int $userId): void
    {
        if (\App\Models\DocumentTemplate::where('vendor_id', $vendor->id)->where('document_type', 'invoice')->exists()) {
            return;
        }
        $abs = base_path('e2e/fixtures/demo-invoice.pdf');
        if (! is_file($abs)) {
            return;
        }

        $tpl = \App\Models\DocumentTemplate::create([
            'vendor_id' => $vendor->id, 'company_id' => $vendor->company_id, 'document_type' => 'invoice',
            'name' => $vendor->name.' Invoice', 'status' => 'active', 'created_by' => $userId,
        ]);
        $samplePath = "portal/templates/{$tpl->id}/sample.pdf";
        \Illuminate\Support\Facades\Storage::disk('local')->put($samplePath, file_get_contents($abs));

        $annotations = [
            'schema' => 1,
            'fields' => [
                ['key' => 'invoice_no', 'label' => 'Invoice No.', 'type' => 'text', 'required' => true, 'page' => 1, 'bbox' => [0.70, 0.145, 0.87, 0.185]],
                ['key' => 'document_date', 'label' => 'Invoice Date', 'type' => 'date', 'required' => true, 'page' => 1, 'bbox' => [0.70, 0.175, 0.87, 0.205]],
                ['key' => 'total_amount', 'label' => 'Total', 'type' => 'amount', 'required' => true, 'page' => 1, 'bbox' => [0.78, 0.74, 0.93, 0.785]],
            ],
            'table' => [
                'page' => 1, 'repeat_on_following_pages' => false, 'bbox' => [0.09, 0.298, 0.89, 0.335],
                'columns' => [
                    ['key' => 'description', 'label' => 'Description', 'x0' => 0.103, 'x1' => 0.58],
                    ['key' => 'quantity', 'label' => 'Quantity', 'x0' => 0.58, 'x1' => 0.64],
                    ['key' => 'unit_price', 'label' => 'Unit Price', 'x0' => 0.64, 'x1' => 0.753],
                    ['key' => 'line_total', 'label' => 'Line Total', 'x0' => 0.753, 'x1' => 0.88],
                ],
            ],
        ];

        $ver = $tpl->versions()->create([
            'version_no' => 1, 'annotations' => $annotations, 'sample_file_path' => $samplePath,
            'status' => 'active', 'activated_at' => now(), 'created_by' => $userId,
        ]);
        $tpl->update(['active_version_id' => $ver->id]);
    }

    /** An approved PO with a known total and line items to reconcile against. */
    private function seedPo(): int
    {
        $vendor = $this->testVendor();
        $ref = self::MARK.'PO-'.strtoupper(Str::random(6));
        $poNumber = self::MARK.'PONUM-'.strtoupper(Str::random(5));

        $po = IntakeDocument::create([
            'reference_no' => $ref, 'vendor_id' => $vendor->id, 'company_id' => $this->companyId($vendor),
            'document_type' => 'purchase_order', 'source' => 'portal_upload', 'status' => IntakeDocument::STATUS_APPROVED,
            'po_number' => $poNumber, 'total_amount' => 10000, 'external_decided_at' => now(),
            'external_decision' => 'approve', 'original_filename' => self::MARK.'po.pdf',
            'file_hash' => self::MARK.Str::random(12), 'file_path' => 'e2e/po.pdf',
        ]);
        $po->lineItems()->create(['line_no' => 1, 'description' => 'E2E Widget A', 'quantity' => 10, 'unit_price' => 500, 'line_total' => 5000]);
        $po->lineItems()->create(['line_no' => 2, 'description' => 'E2E Widget B', 'quantity' => 5, 'unit_price' => 1000, 'line_total' => 5000]);

        return $this->emit(['id' => $po->id, 'reference_no' => $po->reference_no, 'po_number' => $po->po_number, 'total' => 10000]);
    }

    /**
     * A PO plus an invoice that over-bills it, with the exception already
     * evaluated — so a browser test can assert the blocker shows on the doc.
     */
    private function seedOverbill(): int
    {
        $vendor = $this->testVendor();
        $poNumber = self::MARK.'PONUM-'.strtoupper(Str::random(5));

        $po = IntakeDocument::create([
            'reference_no' => self::MARK.'PO-'.strtoupper(Str::random(6)), 'vendor_id' => $vendor->id,
            'company_id' => $this->companyId($vendor), 'document_type' => 'purchase_order', 'source' => 'portal_upload',
            'status' => IntakeDocument::STATUS_APPROVED, 'po_number' => $poNumber, 'total_amount' => 10000,
            'external_decided_at' => now(), 'external_decision' => 'approve', 'original_filename' => self::MARK.'po.pdf',
            'file_hash' => self::MARK.Str::random(12), 'file_path' => 'e2e/po.pdf',
        ]);

        $inv = IntakeDocument::create([
            'reference_no' => self::MARK.'INV-'.strtoupper(Str::random(6)), 'vendor_id' => $vendor->id,
            'company_id' => $this->companyId($vendor), 'document_type' => 'invoice', 'source' => 'portal_upload',
            'status' => IntakeDocument::STATUS_NEEDS_VALIDATION, 'invoice_no' => self::MARK.'INV-'.strtoupper(Str::random(4)),
            'po_number' => $poNumber, 'total_amount' => 15000, 'original_filename' => self::MARK.'invoice.pdf',
            'file_hash' => self::MARK.Str::random(12), 'file_path' => 'e2e/invoice.pdf',
        ]);
        $inv->resolveMatchedPo();

        // Raise the real exceptions so the browser can assert the blocker in the UI.
        app(\App\Http\Services\DocumentExceptionService::class)->evaluate($inv->fresh(['vendor']), 'extraction');

        return $this->emit([
            'po_id' => $po->id, 'po_number' => $poNumber,
            'invoice_id' => $inv->id, 'invoice_reference' => $inv->reference_no,
        ]);
    }

    /** Exercise the real email-intake code path (source=email), held in 'received'. */
    private function injectEmail(DocumentIntakeService $intake): int
    {
        $vendor = $this->testVendor();

        $email = InboundEmail::create([
            'message_id' => self::MARK.Str::uuid(),
            'from_email' => $vendor->email,
            'from_name' => 'E2E Vendor',
            'subject' => self::MARK.'emailed attachment',
            'received_at' => now(),
            'matched_vendor_id' => $vendor->id,
            'match_method' => 'exact',
            'status' => 'processed',
            'meta' => ['attachments' => [self::MARK.'email.pdf'], 'skipped' => 0],
        ]);

        $doc = $intake->createFromEmail($email, $this->minimalPdf(), self::MARK.'email.pdf', $vendor);

        return $this->emit(['id' => $doc->id, 'reference_no' => $doc->reference_no, 'source' => $doc->source, 'inbound_email_id' => $email->id]);
    }

    /** A document (invoice by default) in external review, ready for the decision webhook. */
    private function seedPending(): int
    {
        $vendor = $this->testVendor();
        $type = in_array($this->argument('type'), IntakeDocument::DOCUMENT_TYPES, true) ? $this->argument('type') : 'invoice';
        $isInvoice = $type === 'invoice';
        $ref = self::MARK.strtoupper(substr($type, 0, 3)).'-'.strtoupper(Str::random(6));

        $doc = IntakeDocument::create([
            'reference_no' => $ref, 'vendor_id' => $vendor->id, 'company_id' => $this->companyId($vendor),
            'document_type' => $type, 'source' => 'portal_upload', 'status' => IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW,
            'invoice_no' => $isInvoice ? $ref : null, 'total_amount' => 2500, 'external_review_id' => self::MARK.Str::random(8),
            'submitted_at' => now(), 'original_filename' => self::MARK.$type.'.pdf',
            'file_hash' => self::MARK.Str::random(12), 'file_path' => 'e2e/'.$type.'.pdf',
        ]);

        return $this->emit(['id' => $doc->id, 'reference_no' => $doc->reference_no, 'document_type' => $type, 'external_review_id' => $doc->external_review_id]);
    }

    /** Issue a Sanctum token under a DEDICATED e2e system so ghelpdesk's token is untouched. */
    private function issueToken(): int
    {
        $user = User::firstOrCreate(
            ['email' => 'integration+e2e@linkportal.local'],
            ['name' => 'Integration: E2E', 'password' => Str::random(64), 'is_active' => true],
        );
        $user->tokens()->where('name', 'e2e-integration')->delete();
        $token = $user->createToken('e2e-integration');

        return $this->emit(['token' => $token->plainTextToken]);
    }

    /**
     * Delete automated-test fixtures. Deliberately LEAVES the kept demo data
     * (reference_no `E2E-DEMO-…`) alone, so a test run's teardown never wipes the
     * documents someone is manually walking through. Use `purge-demo` for those.
     */
    private function purge(): int
    {
        $ids = $this->markedDocIds(demoOnly: false);
        $counts = $this->deleteDocsAndChildren($ids);
        $counts['inbound_emails'] = InboundEmail::where('subject', 'like', self::MARK.'%')
            ->orWhere('message_id', 'like', self::MARK.'%')->delete();

        // Reset the PO-expiration policy the expired-scenario switches on (default =
        // never). Model instance so the array→json cast applies on write.
        \App\Models\DocumentExceptionRule::where('rule_key', 'po_expired')->first()
            ?->update(['config' => ['validity_days' => null]]);

        return $this->emit(['purged' => $counts]);
    }

    /** Delete the kept demo documents (run when done with the manual walkthrough). */
    private function purgeDemo(): int
    {
        $ids = $this->markedDocIds(demoOnly: true);
        $counts = $this->deleteDocsAndChildren($ids);
        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('portal/intake/e2e-demo');

        return $this->emit(['purged_demo' => $counts]);
    }

    /**
     * IDs of E2E-marked documents. demoOnly:true = only the kept demo set;
     * demoOnly:false = everything else (automated-test fixtures).
     */
    private function markedDocIds(bool $demoOnly)
    {
        // Kept demo docs are tagged by an `E2E-DEMO ` filename prefix (real files
        // keep their natural DOC- reference), so match demo on either column.
        $isDemo = fn ($q) => $q->where('reference_no', 'like', self::MARK.'DEMO%')
            ->orWhere('original_filename', 'like', self::MARK.'DEMO%');

        return IntakeDocument::withTrashed()
            ->where(fn ($q) => $q->where('original_filename', 'like', self::MARK.'%')
                ->orWhere('reference_no', 'like', self::MARK.'%'))
            ->when($demoOnly, fn ($q) => $q->where($isDemo), fn ($q) => $q->whereNot($isDemo))
            ->pluck('id');
    }

    /** Cascade-delete a set of intake documents and all their children. */
    private function deleteDocsAndChildren($ids): array
    {
        return [
            'ap_statuses' => ApInvoiceStatus::whereIn('intake_document_id', $ids)->delete(),
            'exceptions' => DocumentException::whereIn('intake_document_id', $ids)->delete(),
            'events' => DocumentEvent::whereIn('intake_document_id', $ids)->delete(),
            'line_items' => IntakeLineItem::whereIn('intake_document_id', $ids)->delete(),
            'integration_calls' => \App\Models\IntegrationCall::where('subject_type', IntakeDocument::class)
                ->whereIn('subject_id', $ids)->delete(),
            'documents' => IntakeDocument::withTrashed()->whereIn('id', $ids)->forceDelete(),
        ];
    }

    private function emit(array $data): int
    {
        $this->line(json_encode($data));

        return self::SUCCESS;
    }

    private function errorOut(string $msg): int
    {
        $this->error($msg);

        return self::FAILURE;
    }

    /** Smallest valid single-page PDF, so the stored attachment is a real file. */
    private function minimalPdf(): string
    {
        return "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF";
    }
}
