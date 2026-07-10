<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentException;
use App\Models\IntakeDocument;
use App\Models\Vendor;
use Database\Seeders\DocumentExceptionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentIntakePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
        $this->seed(DocumentExceptionRuleSeeder::class);

        Storage::fake('local');
    }

    /**
     * Log in on the vendor guard without switching the app's default guard —
     * actingAs() calls shouldUse($guard), which HandleInertiaRequests doesn't
     * expect (in production the default guard is always web).
     */
    private function actingAsVendor(Vendor $vendor): static
    {
        $this->actingAs($vendor, 'vendor');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    private function makeVendor(array $attributes = []): Vendor
    {
        $company = Company::create(['name' => 'Acme Company', 'code' => 'ACME', 'is_active' => true]);

        return Vendor::create($attributes + [
            'code' => 'VND-001',
            'name' => 'Test Vendor',
            'email' => 'vendor@example.com',
            'password' => bcrypt('secret'),
            'status' => 'active',
            'company_id' => $company->id,
            'email_verified_at' => now(),
        ]);
    }

    private function fakeOcr(array $fields = [], array $lineItems = []): void
    {
        Http::fake([
            '127.0.0.1:8077/analyze' => Http::response([
                'page_count' => 1,
                'pages' => [['page' => 1, 'width_pt' => 612, 'height_pt' => 792, 'has_text_layer' => true]],
            ]),
            '127.0.0.1:8077/extract' => Http::response([
                'engine_used' => ['1' => 'embedded'],
                'fields' => $fields,
                'line_items' => $lineItems,
                'totals_check' => ['line_sum' => 0, 'extracted_total' => null, 'delta' => null],
                'overall_confidence' => 0.95,
                'page_meta' => [],
                'duration_ms' => 10,
            ]),
        ]);
    }

    public function test_vendor_upload_runs_pipeline_and_reaches_needs_validation(): void
    {
        $vendor = $this->makeVendor();
        $this->fakeOcr([
            ['key' => 'invoice_no', 'value' => 'SI-100', 'raw_text' => 'SI-100', 'confidence' => 0.99, 'page' => 1, 'bbox' => [0.1, 0.1, 0.2, 0.12]],
            ['key' => 'document_date', 'value' => '2026-06-15', 'raw_text' => '06/15/2026', 'confidence' => 0.98, 'page' => 1, 'bbox' => [0.1, 0.2, 0.2, 0.22]],
            ['key' => 'total_amount', 'value' => 1456.00, 'raw_text' => '1,456.00', 'confidence' => 0.97, 'page' => 1, 'bbox' => [0.7, 0.6, 0.9, 0.62]],
        ]);

        $response = $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice',
            'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();

        $document = IntakeDocument::firstOrFail();
        $this->assertSame(IntakeDocument::STATUS_NEEDS_VALIDATION, $document->status);
        $this->assertSame('SI-100', $document->invoice_no);
        $this->assertEquals(1456.00, (float) $document->total_amount);
        $this->assertStringStartsWith('DOC-', $document->reference_no);
        // no template configured -> warning, but required fields present -> no blockers
        $this->assertTrue($document->exceptions()->where('rule_key', 'missing_template')->where('status', 'open')->exists());
        $this->assertFalse($document->hasOpenBlockers());
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_missing_required_fields_raise_blockers(): void
    {
        $vendor = $this->makeVendor();
        $this->fakeOcr([]); // extraction finds nothing

        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice',
            'file' => UploadedFile::fake()->create('empty.pdf', 50, 'application/pdf'),
        ]);

        $document = IntakeDocument::firstOrFail();
        $blockers = $document->openExceptions()->where('rule_key', 'missing_required_field')->pluck('field_key');
        $this->assertEqualsCanonicalizing(['invoice_no', 'document_date', 'total_amount'], $blockers->all());
        $this->assertTrue($document->hasOpenBlockers());
    }

    public function test_duplicate_file_upload_raises_warning(): void
    {
        $vendor = $this->makeVendor();
        $this->fakeOcr([]);
        $file = UploadedFile::fake()->create('same.pdf', 80, 'application/pdf');

        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice', 'file' => $file,
        ]);
        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice', 'file' => UploadedFile::fake()->create('same.pdf', 80, 'application/pdf'),
        ]);

        $second = IntakeDocument::orderByDesc('id')->firstOrFail();
        $this->assertTrue(
            $second->exceptions()->where('rule_key', 'duplicate_file')->where('status', 'open')->exists(),
        );
    }

    public function test_duplicate_invoice_no_blocks_across_intake_documents(): void
    {
        $vendor = $this->makeVendor();
        $fields = [
            ['key' => 'invoice_no', 'value' => 'SI-DUP', 'raw_text' => 'SI-DUP', 'confidence' => 0.99, 'page' => 1, 'bbox' => [0.1, 0.1, 0.2, 0.12]],
            ['key' => 'document_date', 'value' => '2026-06-15', 'raw_text' => '', 'confidence' => 0.99, 'page' => 1, 'bbox' => [0.1, 0.2, 0.2, 0.22]],
            ['key' => 'total_amount', 'value' => 100.00, 'raw_text' => '100.00', 'confidence' => 0.99, 'page' => 1, 'bbox' => [0.7, 0.6, 0.9, 0.62]],
        ];

        $this->fakeOcr($fields);
        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice',
            'file' => UploadedFile::fake()->create('a.pdf', 60, 'application/pdf'),
        ]);

        $this->fakeOcr($fields);
        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice',
            'file' => UploadedFile::fake()->create('b.pdf', 61, 'application/pdf'),
        ]);

        $second = IntakeDocument::orderByDesc('id')->firstOrFail();
        $this->assertTrue(
            $second->openExceptions()->where('rule_key', 'duplicate_invoice_no')->where('severity', 'blocker')->exists(),
        );
    }

    public function test_vendor_can_cancel_pre_submission_document(): void
    {
        $vendor = $this->makeVendor();
        $this->fakeOcr([]);

        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'quotation',
            'file' => UploadedFile::fake()->create('q.pdf', 40, 'application/pdf'),
        ]);

        $document = IntakeDocument::firstOrFail();
        $this->actingAsVendor($vendor)
            ->put(route('vendor.document-uploads.cancel', $document->id))
            ->assertRedirect();

        $this->assertSame(IntakeDocument::STATUS_CANCELLED, $document->fresh()->status);
    }

    public function test_extraction_failure_marks_document_and_raises_no_fatal(): void
    {
        $vendor = $this->makeVendor();
        Http::fake(['127.0.0.1:8077/*' => Http::response(['detail' => 'boom'], 500)]);

        $this->actingAsVendor($vendor)->post(route('vendor.document-uploads.store'), [
            'document_type' => 'invoice',
            'file' => UploadedFile::fake()->create('bad.pdf', 30, 'application/pdf'),
        ]);

        $document = IntakeDocument::firstOrFail();
        // On the sync driver a chained-job failure is attributed to the outer
        // job (conversion_failed); on the real DB queue Extract fails on its own.
        $this->assertContains($document->status, [
            IntakeDocument::STATUS_EXTRACTION_FAILED,
            IntakeDocument::STATUS_CONVERSION_FAILED,
        ]);
        $this->assertTrue(
            $document->openExceptions()->whereIn('rule_key', ['failed_conversion', 'unsupported_file'])->exists()
            || $document->extractions()->where('status', 'failed')->exists(),
        );
    }
}
