<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uploads and Payments are hidden for unapproved vendors. The nav is only a
 * convenience — these tests pin the server-side gate too, so hiding the link can
 * never be mistaken for the actual protection.
 */
class VendorNavGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
    }

    private function vendorWithStatus(string $status): Vendor
    {
        $vendor = Vendor::create([
            'code' => 'VND-NAV-'.$status,
            'name' => 'Nav Test '.$status,
            'email' => $status.'@example.com',
            'password' => 'password123',
            'status' => $status,
            'is_active' => $status === 'active',
            'email_verified_at' => now(),
        ]);

        VendorProfile::create(['vendor_id' => $vendor->id, 'approval_status' => 'draft']);

        return $vendor;
    }

    public function test_a_pending_vendor_is_blocked_from_uploads_and_payments(): void
    {
        $vendor = $this->vendorWithStatus('pending');

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.document-uploads.index'))
            ->assertRedirect(route('vendor.dashboard'));

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.accounts-payable.index'))
            ->assertRedirect(route('vendor.dashboard'));
    }

    public function test_a_pending_vendor_keeps_dashboard_documents_and_profile(): void
    {
        $vendor = $this->vendorWithStatus('pending');

        // Accreditation documents are how a pending vendor gets approved, so
        // that link must stay reachable.
        $this->actingAs($vendor, 'vendor')->get(route('vendor.dashboard'))->assertOk();
        $this->actingAs($vendor, 'vendor')->get(route('vendor.documents.index'))->assertOk();
        $this->actingAs($vendor, 'vendor')->get(route('vendor.profile.edit'))->assertOk();
    }

    public function test_an_active_vendor_reaches_uploads_and_payments(): void
    {
        $vendor = $this->vendorWithStatus('active');

        $this->actingAs($vendor, 'vendor')->get(route('vendor.document-uploads.index'))->assertOk();
        $this->actingAs($vendor, 'vendor')->get(route('vendor.accounts-payable.index'))->assertOk();
    }

    public function test_the_shared_vendor_payload_carries_status_for_the_nav_to_filter_on(): void
    {
        $vendor = $this->vendorWithStatus('pending');

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.vendor.status', 'pending')
                // The nav reads this; if it ever stops being shared the links
                // would silently reappear for everyone.
                ->missing('auth.vendor.password')
            );
    }

    public function test_an_approved_vendor_payload_reports_active(): void
    {
        $vendor = $this->vendorWithStatus('active');

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.vendor.status', 'active'));
    }
}
