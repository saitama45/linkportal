<?php

namespace Tests\Feature;

use App\Models\ApInvoiceStatus;
use App\Models\Company;
use App\Models\IntakeDocument;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DocumentExceptionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
        $this->seed(DocumentExceptionRuleSeeder::class);
        Storage::fake('local');
    }

    private function makeVendor(): Vendor
    {
        $company = Company::create(['name' => 'Acme Company', 'code' => 'ACME', 'is_active' => true]);

        return Vendor::create([
            'code' => 'VND-001',
            'name' => 'Test Vendor',
            'email' => 'vendor@example.com',
            'password' => bcrypt('secret'),
            'status' => 'active',
            'company_id' => $company->id,
        ]);
    }

    private function makeIntakeDocument(Vendor $vendor, array $attributes = []): IntakeDocument
    {
        return IntakeDocument::create($attributes + [
            'reference_no' => 'DOC-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'vendor_id' => $vendor->id,
            'company_id' => $vendor->company_id,
            'document_type' => 'invoice',
            'source' => 'portal_upload',
            'original_filename' => 'invoice.pdf',
            'file_hash' => hash('sha256', uniqid()),
            'file_path' => 'portal/intake/test/original.pdf',
            'invoice_no' => 'SI-500',
            'total_amount' => 1000,
            'status' => IntakeDocument::STATUS_PENDING_EXTERNAL_REVIEW,
            'external_review_id' => '77',
            'submission_count' => 1,
        ]);
    }

    public function test_decision_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/integrations/ghelpdesk/document-review-decision', [])
            ->assertUnauthorized();
    }

    public function test_approve_decision_updates_document_and_seeds_ap_snapshot(): void
    {
        $vendor = $this->makeVendor();
        $document = $this->makeIntakeDocument($vendor);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/integrations/ghelpdesk/document-review-decision', [
            'review_id' => '77',
            'source_document_id' => $document->id,
            'decision' => 'approve',
            'reviewer' => 'Jane Accountant',
            'decided_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $document->refresh();
        $this->assertSame(IntakeDocument::STATUS_APPROVED, $document->status);
        $this->assertSame('approve', $document->external_decision);

        $snapshot = ApInvoiceStatus::where('vendor_id', $vendor->id)->where('invoice_no', 'SI-500')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('for_collection', $snapshot->status);
    }

    public function test_decision_replay_is_idempotent(): void
    {
        $vendor = $this->makeVendor();
        $document = $this->makeIntakeDocument($vendor);
        Sanctum::actingAs(User::factory()->create());

        $payload = [
            'review_id' => '77',
            'source_document_id' => $document->id,
            'decision' => 'approve',
        ];

        $this->postJson('/api/integrations/ghelpdesk/document-review-decision', $payload)->assertOk();
        $this->postJson('/api/integrations/ghelpdesk/document-review-decision', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Decision already applied.');

        $this->assertSame(1, ApInvoiceStatus::count());
    }

    public function test_return_requires_remarks(): void
    {
        $vendor = $this->makeVendor();
        $document = $this->makeIntakeDocument($vendor);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/integrations/ghelpdesk/document-review-decision', [
            'review_id' => '78',
            'source_document_id' => $document->id,
            'decision' => 'return',
        ])->assertUnprocessable();

        $this->postJson('/api/integrations/ghelpdesk/document-review-decision', [
            'review_id' => '78',
            'source_document_id' => $document->id,
            'decision' => 'return',
            'remarks' => 'Wrong PO reference.',
        ])->assertOk();

        $this->assertSame(IntakeDocument::STATUS_RETURNED, $document->fresh()->status);
    }

    public function test_payment_status_endpoint_upserts_snapshot(): void
    {
        $vendor = $this->makeVendor();
        Sanctum::actingAs(User::factory()->create());

        $payload = [
            'vendor_code' => $vendor->code,
            'invoice_no' => 'SI-900',
            'status' => 'partially_paid',
            'mode_of_payment' => 'Bank Transfer',
            'invoice_amount' => 5000,
            'paid_amount' => 2000,
            'outstanding_amount' => 3000,
            'payment_reference_no' => 'PAY-123',
        ];

        $this->postJson('/api/integrations/accounting/invoice-payment-status', $payload)->assertOk();
        $this->postJson('/api/integrations/accounting/invoice-payment-status',
            ['status' => 'paid', 'paid_amount' => 5000, 'outstanding_amount' => 0] + $payload)->assertOk();

        $this->assertSame(1, ApInvoiceStatus::count());
        $snapshot = ApInvoiceStatus::firstOrFail();
        $this->assertSame('paid', $snapshot->status);
        $this->assertEquals(5000.0, (float) $snapshot->paid_amount);
    }

    public function test_payment_status_rejects_unknown_vendor(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/integrations/accounting/invoice-payment-status', [
            'vendor_code' => 'NOPE',
            'invoice_no' => 'SI-1',
            'status' => 'paid',
        ])->assertStatus(422);
    }

    public function test_signed_file_route_streams_pdf_and_rejects_tampering(): void
    {
        $vendor = $this->makeVendor();
        Storage::disk('local')->put('portal/intake/test/original.pdf', '%PDF-1.4 test');
        $document = $this->makeIntakeDocument($vendor, ['converted_pdf_path' => 'portal/intake/test/original.pdf']);

        $signed = URL::temporarySignedRoute('integrations.files.show', now()->addHour(), ['intakeDocument' => $document->id]);
        $this->get($signed)->assertOk();

        $this->get(route('integrations.files.show', ['intakeDocument' => $document->id]))->assertForbidden();
        $this->get($signed.'tampered')->assertForbidden();
    }
}
