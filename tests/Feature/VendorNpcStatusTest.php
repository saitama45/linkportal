<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpcSealReceipt;
use App\Models\NpcStatus;
use App\Models\NpcStatusAttachment;
use App\Models\NpcStoreProof;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VendorNpcStatusTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $cashier;

    private Store $store;

    private Store $otherStore;

    private NpcStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
        $this->withoutVite();
        Storage::fake('npc');
        Notification::fake();

        // Minimal shared hub schema, created only in SQLite memory. Production
        // migrations remain owned by ghelpdesk and are never run by the portal.
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->unsignedBigInteger('company_id');
            $table->timestamps();
        });
        Schema::table('vendors', fn (Blueprint $table) => $table->unsignedBigInteger('store_id')->nullable());
        Schema::create('npc_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->integer('year');
            $table->date('validity_from');
            $table->date('validity_to');
            $table->string('register_password')->nullable();
            $table->timestamps();
        });
        Schema::create('npc_status_store', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('npc_status_id');
            $table->unsignedBigInteger('store_id');
            $table->integer('year');
            $table->timestamps();
            $table->unique(['npc_status_id', 'store_id']);
        });
        Schema::create('npc_status_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('npc_status_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('type');
            $table->date('validity_from');
            $table->string('file_path');
            $table->string('file_name');
            $table->timestamps();
        });
        Schema::create('npc_seal_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('npc_status_id');
            $table->unsignedBigInteger('store_id');
            $table->string('seal_type');
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedBigInteger('downloaded_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamps();
            $table->unique(['npc_status_id', 'store_id', 'seal_type']);
        });
        Schema::create('npc_store_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('npc_status_id');
            $table->unsignedBigInteger('store_id');
            $table->string('seal_type');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->unique(['npc_status_id', 'store_id', 'seal_type']);
        });

        $company = Company::create(['name' => 'Entity', 'code' => 'NPC']);
        $this->store = Store::create(['name' => 'Assigned store', 'code' => 'A', 'company_id' => $company->id]);
        $this->otherStore = Store::create(['name' => 'Other store', 'code' => 'B', 'company_id' => $company->id]);
        $this->cashier = Vendor::create([
            'code' => 'CASHIER', 'name' => 'Cashier', 'email' => 'cashier@example.com',
            'password' => 'password123', 'vendor_type' => 'Cashier', 'status' => 'active',
            'is_active' => true, 'store_id' => $this->store->id,
        ]);
        $this->status = NpcStatus::forceCreate([
            'company_id' => $company->id, 'year' => 2026, 'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31', 'register_password' => 'private-hub-secret',
        ]);
        $this->status->stores()->attach([$this->store->id, $this->otherStore->id], ['year' => 2026]);
    }

    private function attachment(string $type = 'dpo_seal', ?int $storeId = null, string $date = '2026-01-01'): NpcStatusAttachment
    {
        $path = 'npc-attachments/'.uniqid().'.pdf';
        Storage::disk('npc')->put($path, '%PDF-1.4 test');

        return $this->status->attachments()->create([
            'store_id' => $storeId, 'type' => $type, 'validity_from' => $date,
            'file_path' => $path, 'file_name' => basename($path),
        ]);
    }

    private function url(string $action, string $type = 'dpo_seal', ?Store $store = null): string
    {
        return route('vendor.npc-statuses.stores.'.$action, [$this->status, $store ?? $this->store, $type]);
    }

    public function test_guests_and_non_cashiers_cannot_access_the_module(): void
    {
        $this->get('/npc-statuses')->assertRedirect(route('vendor.login'));
        $this->get(route('vendor.npc-statuses.index'))->assertRedirect(route('vendor.login'));
        $this->cashier->update(['vendor_type' => 'Supplier']);
        $this->actingAs($this->cashier, 'vendor')->getJson(route('vendor.npc-statuses.index'))->assertForbidden();
        $this->getJson($this->url('seal.download'))->assertForbidden();
        $this->postJson($this->url('proof.upload'))->assertForbidden();
        $this->getJson($this->url('proof.download'))->assertForbidden();
        $this->cashier->update(['vendor_type' => 'Cashier', 'store_id' => null]);
        $this->getJson(route('vendor.npc-statuses.index'))->assertForbidden();
        $this->cashier->update(['store_id' => $this->store->id, 'status' => 'pending']);
        $this->get(route('vendor.npc-statuses.index'))->assertRedirect(route('vendor.dashboard'));
    }

    public function test_page_only_exposes_assigned_store_documents_and_calendar_dates(): void
    {
        $this->attachment();
        $this->attachment('cctv_seal', $this->otherStore->id);
        $this->actingAs($this->cashier, 'vendor')->get(route('vendor.npc-statuses.index'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Vendor/NpcStatus/Index')
            ->has('storeSeals', 1)->where('storeSeals.0.store_id', $this->store->id)
            ->where('storeSeals.0.years.0.validity_from', '2026-01-01')
            ->where('storeSeals.0.years.0.seals.0.available', true)
            ->where('storeSeals.0.years.0.seals.2.available', false)
            ->missing('storeSeals.0.years.0.register_password'));
        $this->getJson($this->url('seal.download', 'cctv_seal'))->assertNotFound();
    }

    public function test_download_records_once_notifies_hub_and_never_confirms(): void
    {
        $file = $this->attachment();
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::create(['name' => 'npc_status.view', 'guard_name' => 'web']));
        $this->actingAs($this->cashier, 'vendor')->getJson($this->url('seal.download'))->assertOk();
        $this->get($this->url('seal.download'))->assertDownload($file->file_name);
        $this->assertDatabaseCount('npc_seal_receipts', 1);
        $receipt = NpcSealReceipt::first();
        $this->assertNotNull($receipt->downloaded_at);
        $this->assertNull($receipt->downloaded_by);
        $this->assertNull($receipt->confirmed_at);
        Notification::assertSentTo($admin, ActivityNotification::class, fn ($notification) => $notification->payload['actor_type'] === 'vendor'
            && $notification->payload['actor_vendor_id'] === $this->cashier->id
            && $notification->payload['url'] === '/npc-statuses');
        Notification::assertSentTimes(ActivityNotification::class, 1);
        $this->assertDatabaseHas('portal_audit_logs', ['action' => 'npc_seal_downloaded', 'actor_type' => 'vendor', 'actor_id' => $this->cashier->id]);
    }

    public function test_cross_store_and_unassigned_renewal_requests_are_refused(): void
    {
        $this->attachment();
        $this->actingAs($this->cashier, 'vendor');
        $this->getJson($this->url('seal.download', store: $this->otherStore))->assertForbidden();
        $this->postJson($this->url('proof.upload', store: $this->otherStore))->assertForbidden();
        $this->getJson($this->url('proof.download', store: $this->otherStore))->assertForbidden();
        $this->status->stores()->detach($this->store);
        $this->getJson($this->url('seal.download'))->assertNotFound();
        $this->postJson($this->url('proof.upload'))->assertNotFound();
        $this->getJson($this->url('proof.download'))->assertNotFound();
    }

    public function test_missing_files_and_wrong_year_attachments_do_not_record_downloads(): void
    {
        $this->attachment(date: '2025-01-01');
        $this->actingAs($this->cashier, 'vendor')->getJson($this->url('seal.download'))->assertNotFound();
        $file = $this->attachment();
        Storage::disk('npc')->delete($file->file_path);
        $this->getJson($this->url('seal.download'))->assertNotFound();
        $this->assertDatabaseCount('npc_seal_receipts', 0);
        Notification::assertNothingSent();
    }

    public function test_proof_upload_replacement_is_per_document_and_visible_in_shared_tables(): void
    {
        foreach (NpcStatusAttachment::SEAL_TYPES as $type) {
            $this->attachment($type, $type === 'cctv_seal' ? $this->store->id : null);
            $this->actingAs($this->cashier, 'vendor')->postJson($this->url('proof.upload', $type), [
                'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            ])->assertOk();
        }
        $oldPath = NpcStoreProof::where('seal_type', 'dpo_seal')->first()->file_path;
        $this->postJson($this->url('proof.upload'), [
            'file' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
        ])->assertOk();
        $this->assertDatabaseCount('npc_store_proofs', 3);
        Storage::disk('npc')->assertMissing($oldPath);
        $proof = NpcStoreProof::where('seal_type', 'dpo_seal')->first();
        Storage::disk('npc')->assertExists($proof->file_path);
        $this->assertNull($proof->uploaded_by);
        $this->get($this->url('proof.download'))->assertDownload('replacement.pdf');
        $this->get(route('vendor.npc-statuses.index'))->assertInertia(fn ($page) => $page
            ->where('storeSeals.0.years.0.seals.0.proof.name', 'replacement.pdf'));
        $this->assertDatabaseCount('npc_seal_receipts', 0);
    }

    public function test_invalid_upload_leaves_existing_proof_intact(): void
    {
        $this->attachment();
        $this->actingAs($this->cashier, 'vendor')->postJson($this->url('proof.upload'), [
            'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ])->assertOk();
        $oldPath = NpcStoreProof::first()->file_path;
        $this->postJson($this->url('proof.upload'), [
            'file' => UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame($oldPath, NpcStoreProof::first()->file_path);
        Storage::disk('npc')->assertExists($oldPath);
    }
}
