<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransactionCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/portal',
        ])->assertSuccessful();
    }

    public function test_user_with_invoice_create_permission_can_create_and_submit_an_invoice(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('invoices.create'));

        $company = Company::create([
            'name' => 'Acme Company',
            'code' => 'ACME',
            'is_active' => true,
        ]);

        $vendor = Vendor::create([
            'code' => 'VND-TEST-001',
            'name' => 'Test Vendor',
            'email' => 'vendor@example.com',
            'password' => 'Vendor1234',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'vendor_id' => $vendor->id,
            'company_id' => $company->id,
            'invoice_no' => 'SI-001',
            'invoice_date' => '2026-07-05',
            'items' => [
                [
                    'product_id' => null,
                    'description' => 'Consulting services',
                    'quantity' => 2,
                    'uom_id' => null,
                    'unit_price' => 1000,
                    'tax_rate' => 12,
                ],
            ],
        ]);

        $invoiceId = (int) DB::table('portal_invoices')
            ->where('invoice_no', 'SI-001')
            ->value('id');

        $response->assertRedirect(route('invoices.show', $invoiceId));
        $this->assertDatabaseHas('portal_invoices', [
            'id' => $invoiceId,
            'vendor_id' => $vendor->id,
            'company_id' => $company->id,
            'status' => 'submitted',
            'created_by' => $user->id,
        ]);
        $this->assertDatabaseHas('portal_invoice_items', [
            'invoice_id' => $invoiceId,
            'description' => 'Consulting services',
            'line_total' => 2240,
        ]);
    }
}
