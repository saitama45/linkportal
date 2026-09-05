<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VendorPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
        Notification::fake();
    }

    private function portalVendor(array $overrides = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'Reset Test Vendor',
            'email' => 'reset@example.com',
            'password' => 'password123',
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $overrides));
    }

    /** Pull the emailed token out of the notification that was sent. */
    private function sentToken(Vendor $vendor): string
    {
        $token = null;

        Notification::assertSentTo($vendor, VendorResetPassword::class, function ($notification) use (&$token) {
            $token = (fn () => $this->token)->call($notification);

            return true;
        });

        $this->assertNotNull($token, 'no reset token was emailed');

        return $token;
    }

    public function test_a_portal_vendor_can_reset_their_password(): void
    {
        $vendor = $this->portalVendor();

        $this->post('/forgot-password', ['email' => 'reset@example.com'])
            ->assertRedirect();

        $token = $this->sentToken($vendor);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect(route('vendor.login'));

        $this->assertTrue(Hash::check('newpassword123', $vendor->fresh()->password));

        // And the new password actually signs them in.
        $this->post('/login', ['email' => 'reset@example.com', 'password' => 'newpassword123'])
            ->assertRedirect(route('vendor.dashboard'));
    }

    public function test_a_back_office_reference_vendor_is_never_sent_a_reset_link(): void
    {
        // No password: this row is a reference vendor, not a login account, and
        // a reset must not be able to turn it into one.
        Vendor::create(['name' => 'Reference Only', 'email' => 'reference@example.com']);

        $this->post('/forgot-password', ['email' => 'reference@example.com'])
            ->assertRedirect();

        Notification::assertNothingSent();
        $this->assertNull(Vendor::where('email', 'reference@example.com')->first()->password);
    }

    public function test_the_response_does_not_reveal_whether_the_address_is_known(): void
    {
        $this->portalVendor();

        $known = $this->post('/forgot-password', ['email' => 'reset@example.com']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'a different message for a known address would make this screen an email oracle'
        );
    }

    public function test_vendor_tokens_do_not_share_the_staff_reset_table(): void
    {
        $vendor = $this->portalVendor();

        $this->post('/forgot-password', ['email' => 'reset@example.com']);

        $this->assertDatabaseCount('vendor_password_reset_tokens', 1);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(
            'reset@example.com',
            DB::table('vendor_password_reset_tokens')->value('email')
        );
        $this->assertNotNull($vendor->fresh()->email_verified_at);
    }

    public function test_a_rejected_vendor_cannot_sign_in(): void
    {
        $this->portalVendor([
            'email' => 'rejected@example.com',
            'status' => 'rejected',
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => 'rejected@example.com', 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('vendor');
    }

    public function test_a_pending_vendor_may_still_sign_in_to_finish_onboarding(): void
    {
        $this->portalVendor([
            'email' => 'pending@example.com',
            'status' => 'pending',
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => 'pending@example.com', 'password' => 'password123'])
            ->assertRedirect(route('vendor.dashboard'));

        $this->assertAuthenticated('vendor');
    }
}
