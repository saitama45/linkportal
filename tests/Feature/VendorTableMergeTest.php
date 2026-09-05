<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The portal and the back office now share the `vendors` table, so that table
 * holds two kinds of row: portal login accounts (password set) and back-office
 * reference vendors (password null). Nothing passwordless may authenticate.
 */
class VendorTableMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Portal migrations live outside the default path so a bare `migrate`
        // never touches the shared helpdesk database.
        $this->artisan('migrate', [
            '--path' => 'database/migrations/portal',
        ])->assertSuccessful();
    }

    public function test_vendor_guard_authenticates_against_the_shared_vendors_table(): void
    {
        $this->assertSame('vendors', (new Vendor)->getTable());
        $this->assertSame(
            'vendors',
            auth('vendor')->getProvider()->createModel()->getTable()
        );
    }

    public function test_portal_vendor_can_log_in(): void
    {
        Vendor::create([
            'code' => 'VND-TEST-1',
            'name' => 'Merge Test Vendor',
            'email' => 'merge-test@example.com',
            'password' => 'password123',
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => 'merge-test@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('vendor.dashboard'));
        $this->assertTrue(auth('vendor')->check());
    }

    public function test_back_office_reference_vendor_without_password_cannot_log_in(): void
    {
        // Exactly the shape of the 20 legacy rows: no password, no portal status.
        $legacy = Vendor::create([
            'code' => 'Telco',
            'name' => 'Telco - PLDT',
            'email' => 'pldt@example.com',
            'is_active' => true,
        ]);

        $this->assertFalse($legacy->hasPortalAccess());

        $this->post('/login', [
            'email' => 'pldt@example.com',
            'password' => 'anything-at-all',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(auth('vendor')->check());
    }

    public function test_blank_password_is_never_accepted_for_a_passwordless_vendor(): void
    {
        Vendor::create([
            'code' => 'Telco',
            'name' => 'Telco - Globe',
            'email' => 'globe@example.com',
            'is_active' => true,
        ]);

        // Hash::check(x, null) can behave surprisingly, so probe the shapes most
        // likely to slip past a null password rather than only a wrong guess.
        foreach (['null', '0', Hash::make(''), bin2hex(random_bytes(8))] as $attempt) {
            $this->post('/login', [
                'email' => 'globe@example.com',
                'password' => $attempt,
            ])->assertSessionHasErrors('email');

            $this->assertFalse(auth('vendor')->check(), "authenticated with [{$attempt}]");
        }
    }

    public function test_admin_vendor_list_shows_only_portal_accounts(): void
    {
        Vendor::create(['code' => 'X', 'name' => 'Reference Only', 'is_active' => true]);
        Vendor::create([
            'code' => 'VND-TEST-2',
            'name' => 'Portal Account',
            'email' => 'portal@example.com',
            'password' => 'password123',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->assertSame(2, Vendor::count());
        $this->assertSame(1, Vendor::withPortalAccess()->count());
        $this->assertSame('Portal Account', Vendor::withPortalAccess()->first()->name);
    }

    public function test_registration_lands_in_the_shared_vendors_table_immediately(): void
    {
        $this->post('/vendor/register', [
            'name' => 'Brand New Trading',
            'email' => 'brandnew@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => '+63 900 000 0000',
        ])->assertRedirect(route('vendor.otp.show')); // gated on email OTP

        // Exactly the query the back office (ghelpdesk) runs on its /vendors page.
        $row = \DB::table('vendors')->where('name', 'Brand New Trading')->first();

        $this->assertNotNull($row, 'registrant is not visible to the back office');
        $this->assertSame('brandnew@example.com', $row->email);
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->is_active, 'must not be active before approval');
        $this->assertNull($row->email_verified_at, 'must not be verified before the OTP is entered');
        $this->assertMatchesRegularExpression('/^VND-\d{4}-\d{5}$/', $row->code);
    }

    public function test_registering_as_an_existing_back_office_vendor_claims_that_row(): void
    {
        // A reference-only vendor the back office already knows, with history
        // hanging off its id.
        $existing = Vendor::create([
            'code' => 'Hardware/Software',
            'name' => 'PC Express',
            'vendor_type' => 'Supplier',
            'is_active' => true,
        ]);

        $this->post('/vendor/register', [
            'name' => '  pc express  ', // different case and padding
            'email' => 'pcexpress@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('vendor.otp.show'));

        $this->assertSame(1, Vendor::where('name', 'PC Express')->count(), 'duplicated the vendor');
        $this->assertSame(1, Vendor::count());

        $existing->refresh();
        $this->assertTrue($existing->hasPortalAccess());
        $this->assertSame('pcexpress@example.com', $existing->email);
        $this->assertSame('pending', $existing->status);
        $this->assertFalse($existing->is_active, 'claiming must not auto-approve');
        // The back office's own code and type win over portal defaults.
        $this->assertSame('Hardware/Software', $existing->code);
        $this->assertSame('Supplier', $existing->vendor_type);
    }

    public function test_a_name_already_claimed_by_a_portal_account_is_not_hijacked(): void
    {
        $incumbent = Vendor::create([
            'code' => 'VND-2026-00001',
            'name' => 'Contested Corp',
            'email' => 'incumbent@example.com',
            'password' => 'password123',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->post('/vendor/register', [
            'name' => 'Contested Corp',
            'email' => 'attacker@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $incumbent->refresh();
        $this->assertSame('incumbent@example.com', $incumbent->email, 'existing login was overwritten');
        $this->assertSame(2, Vendor::count(), 'should create a separate row, not claim the login');
    }

    public function test_portal_vendor_type_slug_is_stored_as_the_back_office_label(): void
    {
        $this->post('/vendor/register', [
            'name' => 'Slug Type Co',
            'email' => 'slug@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'vendor_type' => 'service_provider',
        ])->assertRedirect(route('vendor.otp.show'));

        // Must match ghelpdesk's `in:` rule, or staff can never save an edit.
        $this->assertSame(
            'Service Provider',
            \DB::table('vendors')->where('name', 'Slug Type Co')->value('vendor_type')
        );
    }

    public function test_every_portal_vendor_type_maps_to_a_back_office_accepted_label(): void
    {
        // Mirrors reference_options (type = vendor_type) in the back office.
        $backOfficeAccepts = [
            'Supplier', 'Service Provider', 'Contractor', 'Consultant', 'Logistics / Forwarder', 'Cashier',
        ];

        foreach (Vendor::VENDOR_TYPE_LABELS as $slug => $label) {
            $vendor = Vendor::create([
                'code' => 'VND-'.$slug,
                'name' => 'Type Probe '.$slug,
                'vendor_type' => $slug,
                'is_active' => true,
            ]);

            $this->assertContains(
                $vendor->fresh()->vendor_type,
                $backOfficeAccepts,
                "portal type [{$slug}] is not editable in the back office"
            );
        }
    }

    public function test_a_session_holding_a_reference_only_vendor_id_resolves_to_nobody(): void
    {
        // The exact hazard the merge created: sessions issued before it carry an
        // old portal_vendors id that now names a different, passwordless vendor.
        $stranded = Vendor::create([
            'code' => 'Telco',
            'name' => 'New Datche',
            'is_active' => true,
        ]);

        $this->assertNull(
            auth('vendor')->getProvider()->retrieveById($stranded->id),
            'a stale session resolved to a back-office reference vendor'
        );

        // Drive the real session path: actingAs() injects the object straight
        // onto the guard and would never exercise the provider.
        $this->withSession([auth('vendor')->getName() => $stranded->id])
            ->get('/vendor/dashboard')
            ->assertRedirect(route('vendor.login'));

        $this->assertFalse(auth('vendor')->check());
    }

    public function test_a_real_portal_account_still_resolves_from_its_session(): void
    {
        $vendor = Vendor::create([
            'code' => 'VND-2026-00009',
            'name' => 'Session Probe',
            'email' => 'session@example.com',
            'password' => 'password123',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->assertNotNull(auth('vendor')->getProvider()->retrieveById($vendor->id));

        $this->withSession([auth('vendor')->getName() => $vendor->id])
            ->get('/vendor/dashboard')
            ->assertOk();
    }
}
