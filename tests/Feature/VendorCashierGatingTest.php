<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Cashier is a store till, not a supplier: it is never accredited, never
 * submits an invoice and is never paid through accounts payable. The nav hides
 * those sections, but hiding a link proves nothing — `vendor.trading` is the
 * real boundary, so it is pinned here.
 */
class VendorCashierGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
    }

    private function vendor(string $type): Vendor
    {
        $vendor = Vendor::create([
            'code' => 'VND-GATE-'.md5($type),
            'name' => 'Gate Test '.$type,
            'email' => str_replace([' ', '/'], '-', strtolower($type)).'@example.com',
            'password' => 'password123',
            'vendor_type' => $type,
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        VendorProfile::create(['vendor_id' => $vendor->id, 'approval_status' => 'approved']);

        return $vendor;
    }

    public function test_a_cashier_cannot_reach_accreditation_documents(): void
    {
        $cashier = $this->vendor(Vendor::TYPE_CASHIER);

        $this->actingAs($cashier, 'vendor')
            ->get(route('vendor.documents.index'))
            ->assertRedirect(route('vendor.dashboard'));

        $this->actingAs($cashier, 'vendor')
            ->post(route('vendor.documents.store'), [])
            ->assertRedirect(route('vendor.dashboard'));
    }

    public function test_a_cashier_cannot_reach_uploads_or_payments(): void
    {
        $cashier = $this->vendor(Vendor::TYPE_CASHIER);

        $this->actingAs($cashier, 'vendor')
            ->get(route('vendor.document-uploads.index'))
            ->assertRedirect(route('vendor.dashboard'));

        $this->actingAs($cashier, 'vendor')
            ->get(route('vendor.accounts-payable.index'))
            ->assertRedirect(route('vendor.dashboard'));
    }

    public function test_a_cashier_without_a_store_is_still_refused_the_trading_side(): void
    {
        // The store assignment gates Campaigns, not this: an unassigned cashier
        // is not a supplier either, so it must not fall through to the uploads.
        $cashier = $this->vendor(Vendor::TYPE_CASHIER);
        $this->assertNull($cashier->store_id);

        $this->actingAs($cashier, 'vendor')
            ->get(route('vendor.documents.index'))
            ->assertRedirect(route('vendor.dashboard'));
    }

    public function test_a_supplier_still_reaches_all_of_it(): void
    {
        $supplier = $this->vendor('Supplier');

        $this->actingAs($supplier, 'vendor')->get(route('vendor.documents.index'))->assertOk();
        $this->actingAs($supplier, 'vendor')->get(route('vendor.document-uploads.index'))->assertOk();
        $this->actingAs($supplier, 'vendor')->get(route('vendor.accounts-payable.index'))->assertOk();
    }

    public function test_the_cashier_profile_drops_the_trading_sections(): void
    {
        $cashier = $this->vendor(Vendor::TYPE_CASHIER);

        $this->actingAs($cashier, 'vendor')
            ->get(route('vendor.profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendor/Profile')
                ->where('isCashier', true)
                ->where('paymentTermsOptions', [])
                ->where('currencyOptions', []));
    }

    public function test_a_supplier_profile_keeps_them(): void
    {
        $supplier = $this->vendor('Supplier');

        $this->actingAs($supplier, 'vendor')
            ->get(route('vendor.profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendor/Profile')
                ->where('isCashier', false));
    }

    public function test_registration_offers_every_managed_vendor_type(): void
    {
        // Hard-coding the list on the Vue page is exactly the bug this pins: a
        // type added in the back office must be selectable without a deploy.
        $this->get(route('vendor.register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendor/Auth/Register')
                ->where('vendorTypes', Vendor::types()));

        $this->assertContains(Vendor::TYPE_CASHIER, Vendor::types());
    }
}
