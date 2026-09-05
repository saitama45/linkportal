<?php

namespace Tests\Feature;

use App\Http\Services\EmailOtpService;
use App\Models\EmailOtp;
use App\Models\Vendor;
use App\Notifications\VendorEmailOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VendorEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
        Notification::fake();
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/vendor/register', array_merge([
            'name' => 'Otp Test Vendor',
            'email' => 'otp@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides));
    }

    /** Pull the plaintext code out of the notification that was sent. */
    private function sentCode(Vendor $vendor): string
    {
        $code = null;

        Notification::assertSentTo($vendor, VendorEmailOtp::class, function ($notification) use (&$code, $vendor) {
            $body = implode(' ', $notification->toMail($vendor)->introLines);
            preg_match('/\*\*(\d{6})\*\*/', $body, $m);
            $code = $m[1] ?? null;

            return true;
        });

        $this->assertNotNull($code, 'no 6-digit code was emailed');

        return $code;
    }

    public function test_registration_does_not_log_the_vendor_in_and_sends_a_code(): void
    {
        $this->register()->assertRedirect(route('vendor.otp.show'));

        $this->assertFalse(auth('vendor')->check(), 'registrant was logged in before verifying');

        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();
        $this->assertNull($vendor->email_verified_at);

        Notification::assertSentTo($vendor, VendorEmailOtp::class);
        $this->assertSame(1, EmailOtp::where('vendor_id', $vendor->id)->count());
    }

    public function test_the_code_is_six_digits_and_stored_only_as_a_hash(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();

        $code = $this->sentCode($vendor);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $row = EmailOtp::where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertNotSame($code, $row->code_hash, 'code was stored in plaintext');
        $this->assertTrue(Hash::check($code, $row->code_hash));
    }

    public function test_correct_code_verifies_and_logs_in(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();

        $this->post(route('vendor.otp.verify'), ['code' => $this->sentCode($vendor)])
            ->assertRedirect(route('vendor.dashboard'));

        $this->assertTrue(auth('vendor')->check());
        $this->assertNotNull($vendor->fresh()->email_verified_at);
    }

    public function test_wrong_code_does_not_log_in(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();

        $wrong = str_pad((string) ((((int) $this->sentCode($vendor)) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->post(route('vendor.otp.verify'), ['code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth('vendor')->check());
        $this->assertNull($vendor->fresh()->email_verified_at);
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();
        $code = $this->sentCode($vendor);

        $this->post(route('vendor.otp.verify'), ['code' => $code]);
        $this->post('/vendor/logout');
        $this->assertFalse(auth('vendor')->check());

        // Same code, fresh session: must be spent.
        $this->withSession(['otp_vendor_id' => $vendor->id])
            ->post(route('vendor.otp.verify'), ['code' => $code])
            ->assertRedirect(route('vendor.login'));

        $this->assertFalse(auth('vendor')->check());
    }

    public function test_code_is_rejected_after_five_wrong_attempts(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();
        $code = $this->sentCode($vendor);

        for ($i = 0; $i < EmailOtp::MAX_ATTEMPTS; $i++) {
            $this->post(route('vendor.otp.verify'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        // Even the real code is now dead - a new one must be requested.
        $this->post(route('vendor.otp.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth('vendor')->check());
        $this->assertNull($vendor->fresh()->email_verified_at);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();
        $code = $this->sentCode($vendor);

        $this->travel(EmailOtpService::TTL_MINUTES + 1)->minutes();

        $this->post(route('vendor.otp.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth('vendor')->check());
    }

    public function test_unverified_vendor_cannot_log_in_and_is_sent_to_the_otp_screen(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();

        $this->post('/login', ['email' => 'otp@example.com', 'password' => 'password123'])
            ->assertRedirect(route('vendor.otp.show'));

        $this->assertFalse(auth('vendor')->check(), 'unverified vendor got a session');
    }

    public function test_verified_vendor_logs_in_normally(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();
        $this->post(route('vendor.otp.verify'), ['code' => $this->sentCode($vendor)]);
        $this->post('/vendor/logout');

        $this->post('/login', ['email' => 'otp@example.com', 'password' => 'password123'])
            ->assertRedirect(route('vendor.dashboard'));

        $this->assertTrue(auth('vendor')->check());
    }

    public function test_the_otp_screen_cannot_be_pointed_at_another_vendor(): void
    {
        $this->register();
        $victim = Vendor::where('email', 'otp@example.com')->firstOrFail();

        // A different browser: the pending vendor is held server-side in the
        // session, never in the URL, so there is nothing to point at.
        $this->flushSession();

        $this->get(route('vendor.otp.show'))->assertRedirect(route('vendor.login'));
        $this->post(route('vendor.otp.verify'), ['code' => '123456'])
            ->assertRedirect(route('vendor.login'));

        $this->assertFalse(auth('vendor')->check());
        $this->assertNull($victim->fresh()->email_verified_at);
    }

    public function test_resend_is_throttled_and_invalidates_the_previous_code(): void
    {
        $this->register();
        $vendor = Vendor::where('email', 'otp@example.com')->firstOrFail();
        $first = $this->sentCode($vendor);

        // Within the cooldown: refused, and the original code still stands.
        $this->post(route('vendor.otp.resend'))->assertSessionHas('error');
        $this->assertSame(1, EmailOtp::where('vendor_id', $vendor->id)->whereNull('consumed_at')->count());

        $this->travel(EmailOtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('vendor.otp.resend'))->assertSessionHas('status');

        // The superseded code must no longer work.
        $this->post(route('vendor.otp.verify'), ['code' => $first])
            ->assertSessionHasErrors('code');
        $this->assertFalse(auth('vendor')->check());
    }

    public function test_a_cashier_registration_skips_the_code_and_signs_straight_in(): void
    {
        // A till account's address is typically a shared store mailbox nobody
        // watches, so a code would lock the account out of itself.
        $this->register([
            'name' => 'Till Number Four',
            'email' => 'till4@example.com',
            'vendor_type' => Vendor::TYPE_CASHIER,
        ])->assertRedirect(route('vendor.dashboard'));

        $vendor = Vendor::where('email', 'till4@example.com')->firstOrFail();

        $this->assertTrue($vendor->isCashier());
        $this->assertNotNull($vendor->email_verified_at, 'cashier was left unverified');
        $this->assertTrue(auth('vendor')->check(), 'cashier was not signed in');
        $this->assertSame($vendor->id, auth('vendor')->id());

        Notification::assertNothingSentTo($vendor);
        $this->assertSame(0, EmailOtp::where('vendor_id', $vendor->id)->count());

        // The back office still gates what the account can actually do.
        $this->assertSame('pending', $vendor->status);
        $this->assertFalse((bool) $vendor->is_active);
    }

    public function test_every_other_vendor_type_still_has_to_clear_a_code(): void
    {
        foreach (['Supplier', 'Service Provider', 'Contractor', 'Consultant'] as $i => $type) {
            $this->flushSession();
            auth('vendor')->logout();

            $email = 'typed'.$i.'@example.com';

            $this->register([
                'name' => 'Typed Vendor '.$i,
                'email' => $email,
                'vendor_type' => $type,
            ])->assertRedirect(route('vendor.otp.show'));

            $vendor = Vendor::where('email', $email)->firstOrFail();

            $this->assertNull($vendor->email_verified_at, "[{$type}] skipped verification");
            $this->assertFalse(auth('vendor')->check(), "[{$type}] was signed in unverified");
            $this->assertSame(1, EmailOtp::where('vendor_id', $vendor->id)->count());
        }
    }

    public function test_a_cashier_signing_in_is_never_sent_to_the_code_screen(): void
    {
        // Predates the exemption: created unverified, as registration used to.
        $vendor = Vendor::create([
            'code' => 'VND-TILL-OLD',
            'name' => 'Legacy Till',
            'email' => 'legacy-till@example.com',
            'password' => 'password123',
            'vendor_type' => Vendor::TYPE_CASHIER,
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $this->post('/login', [
            'email' => 'legacy-till@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('vendor.dashboard'));

        $this->assertTrue(auth('vendor')->check());
        $this->assertNotNull($vendor->fresh()->email_verified_at);
        Notification::assertNothingSentTo($vendor);
    }

    public function test_an_unverified_supplier_signing_in_still_gets_a_code(): void
    {
        $vendor = Vendor::create([
            'code' => 'VND-SUP-OLD',
            'name' => 'Legacy Supplier',
            'email' => 'legacy-supplier@example.com',
            'password' => 'password123',
            'vendor_type' => 'Supplier',
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $this->post('/login', [
            'email' => 'legacy-supplier@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('vendor.otp.show'));

        $this->assertFalse(auth('vendor')->check());
        $this->assertNull($vendor->fresh()->email_verified_at);
    }
}
