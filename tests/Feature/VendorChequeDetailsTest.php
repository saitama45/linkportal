<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorChequeDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
    }

    private function vendor(): Vendor
    {
        $vendor = Vendor::create([
            'code' => 'VND-CHQ-1',
            'name' => 'Gen Supplier',
            'email' => 'cheque@example.com',
            'password' => 'password123',
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        VendorProfile::create(['vendor_id' => $vendor->id, 'approval_status' => 'draft']);

        return $vendor;
    }

    public function test_cheque_details_are_staged_for_approval_not_applied_immediately(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($vendor, 'vendor')->put(route('vendor.profile.update'), [
            'legal_name' => 'Gen Supplier Trading Corporation',
            'cheque_payee_name' => 'Gen Supplier Trading Corporation',
            'cheque_delivery_method' => 'pickup',
            'cheque_is_crossed' => true,
            'cheque_remarks' => 'Release to the accounting head only.',
        ])->assertRedirect();

        $profile = $vendor->profile->fresh();

        // Payment-sensitive: must not go live on the vendor's say-so.
        $this->assertNull($profile->cheque_payee_name);
        $this->assertSame('pending', $profile->approval_status);
        $this->assertSame('Gen Supplier Trading Corporation', $profile->pending_changes['cheque_payee_name']);
        $this->assertTrue($profile->pending_changes['cheque_is_crossed']);
    }

    public function test_approval_applies_the_cheque_details(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($vendor, 'vendor')->put(route('vendor.profile.update'), [
            'cheque_payee_name' => 'Gen Supplier Trading Corporation',
            'cheque_delivery_method' => 'bank_deposit',
            'cheque_is_crossed' => true,
            'cheque_remarks' => 'Crossed cheque only.',
        ]);

        $profile = $vendor->profile->fresh();
        $profile->fill($profile->pending_changes);
        $profile->save();

        // fill() honours $fillable — the cheque columns must be listed there or
        // approval silently drops them.
        $profile->refresh();
        $this->assertSame('Gen Supplier Trading Corporation', $profile->cheque_payee_name);
        $this->assertSame('bank_deposit', $profile->cheque_delivery_method);
        $this->assertTrue($profile->cheque_is_crossed);
        $this->assertSame('Crossed cheque only.', $profile->cheque_remarks);
    }

    public function test_an_unknown_cheque_release_method_is_rejected(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($vendor, 'vendor')
            ->put(route('vendor.profile.update'), ['cheque_delivery_method' => 'hand_to_a_stranger'])
            ->assertSessionHasErrors('cheque_delivery_method');
    }

    public function test_a_vendor_can_add_several_contacts_and_bank_accounts(): void
    {
        $vendor = $this->vendor();

        foreach ([['Ana Cruz', true], ['Ben Reyes', false], ['Cara Lim', false]] as [$name, $primary]) {
            $this->actingAs($vendor, 'vendor')->post(route('vendor.profile.contacts.store'), [
                'name' => $name,
                'email' => str($name)->slug().'@example.com',
                'is_primary' => $primary,
            ])->assertRedirect();
        }

        foreach ([['BDO', '0011'], ['BPI', '0022'], ['Metrobank', '0033']] as [$bank, $number]) {
            $this->actingAs($vendor, 'vendor')->post(route('vendor.bank-accounts.store'), [
                'bank_name' => $bank,
                'account_name' => 'Gen Supplier Trading Corporation',
                'account_number' => $number,
            ])->assertRedirect();
        }

        $this->assertSame(3, $vendor->contacts()->count());
        $this->assertSame(3, $vendor->bankAccounts()->count());

        // Exactly one primary contact, and every bank account awaits verification.
        $this->assertSame(1, $vendor->contacts()->where('is_primary', true)->count());
        $this->assertSame(3, $vendor->bankAccounts()->where('approval_status', 'pending')->count());
    }

    public function test_marking_a_new_contact_primary_demotes_the_previous_one(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($vendor, 'vendor')->post(route('vendor.profile.contacts.store'), [
            'name' => 'First Primary', 'is_primary' => true,
        ]);
        $this->actingAs($vendor, 'vendor')->post(route('vendor.profile.contacts.store'), [
            'name' => 'Second Primary', 'is_primary' => true,
        ]);

        $this->assertSame(1, $vendor->contacts()->where('is_primary', true)->count());
        $this->assertSame('Second Primary', $vendor->contacts()->where('is_primary', true)->first()->name);
    }
}
