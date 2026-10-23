<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Deduct Stamps" — a cashier takes back stamps added by mistake. The card's
 * count drops, but nothing is erased: the correction is its own negative entry
 * with the cashier's reason, and it is audited.
 */
class VendorCampaignDeductTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $cashier;

    private Store $store;

    private StampProgram $program;

    private Customer $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();

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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('stamp_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->integer('stamps_required');
            $table->integer('holiday_stamps')->default(0);
            $table->integer('max_cards_per_customer')->nullable();
            $table->decimal('auto_stamp_amount', 12, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('stamp_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('stamp_program_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->integer('stamps_count')->default(0);
            $table->integer('holiday_count')->default(0);
            $table->string('status');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->unsignedBigInteger('cashier_vendor_id')->nullable();
            $table->timestamps();
        });
        Schema::create('stamp_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stamp_card_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->integer('quantity');
            $table->string('stamp_type', 20)->default('regular');
            $table->string('source');
            $table->decimal('purchase_amount', 12, 2)->nullable();
            $table->string('receipt_number', 100)->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('cashier_vendor_id')->nullable();
            $table->timestamps();
        });

        $company = Company::create(['name' => 'Entity', 'code' => 'CMP']);
        $this->store = Store::create(['name' => 'Assigned store', 'code' => 'A', 'company_id' => $company->id]);
        $this->cashier = Vendor::create([
            'code' => 'CASHIER-A', 'name' => 'Cashier A', 'email' => 'cashier-a@example.com',
            'password' => 'password123', 'vendor_type' => 'Cashier', 'status' => 'active',
            'is_active' => true, 'store_id' => $this->store->id,
        ]);
        $this->program = StampProgram::create([
            'company_id' => $company->id, 'name' => 'Holiday card',
            'stamps_required' => 20, 'holiday_stamps' => 4,
        ]);
        $this->member = Customer::create(['name' => 'Gen', 'is_active' => true]);
    }

    private function card(int $stamps, int $holiday = 0, string $status = 'active'): StampCard
    {
        return StampCard::create([
            'customer_id' => $this->member->id, 'stamp_program_id' => $this->program->id,
            'store_id' => $this->store->id, 'stamps_count' => $stamps, 'holiday_count' => $holiday,
            'status' => $status,
        ]);
    }

    private function deduct(StampCard $card, array $data)
    {
        return $this->actingAs($this->cashier, 'vendor')
            ->post(route('vendor.campaigns.cards.deduct-stamps', $card), $data);
    }

    public function test_a_deduction_lowers_the_card_and_is_recorded_with_its_reason(): void
    {
        $card = $this->card(6, 2);

        $this->deduct($card, ['quantity' => 2, 'stamp_type' => 'regular', 'reason' => 'Typed 3 stamps instead of 1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $card->refresh();
        $this->assertSame(4, $card->stamps_count);
        $this->assertSame(2, $card->holiday_count, 'regular deduction leaves holiday slots alone');

        $entry = StampEntry::sole();
        $this->assertSame(-2, $entry->quantity);
        $this->assertSame('deduction', $entry->source);
        $this->assertSame('regular', $entry->stamp_type);
        $this->assertSame('Typed 3 stamps instead of 1', $entry->note);
        $this->assertSame($this->cashier->id, (int) $entry->cashier_vendor_id);
        $this->assertNull($entry->purchase_amount);

        $this->assertTrue(DB::table('portal_audit_logs')->where('action', 'stamp_deducted')->exists());
    }

    public function test_a_holiday_deduction_empties_holiday_slots(): void
    {
        $card = $this->card(5, 4);

        $this->deduct($card, ['quantity' => 1, 'stamp_type' => 'holiday', 'reason' => 'Should have been Regular'])
            ->assertSessionHasNoErrors();

        $card->refresh();
        $this->assertSame(4, $card->stamps_count);
        $this->assertSame(3, $card->holiday_count);
    }

    public function test_a_reason_is_required(): void
    {
        $card = $this->card(3);

        $this->deduct($card, ['quantity' => 1, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->deduct($card, ['quantity' => 1, 'reason' => 'oops'])->assertSessionHasErrors('reason');

        $this->assertSame(3, $card->refresh()->stamps_count);
        $this->assertSame(0, StampEntry::count());
    }

    public function test_never_more_than_the_card_holds_of_that_kind(): void
    {
        $card = $this->card(5, 4); // 4 holiday + 1 regular

        $this->deduct($card, ['quantity' => 2, 'stamp_type' => 'regular', 'reason' => 'Scanned the wrong member'])
            ->assertSessionHasErrors('quantity');
        $this->deduct($card, ['quantity' => 5, 'stamp_type' => 'holiday', 'reason' => 'Scanned the wrong member'])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(5, $card->refresh()->stamps_count);
    }

    public function test_a_full_card_reopens_and_a_redeemed_one_is_refused(): void
    {
        $full = $this->card(20, 4, 'completed');
        $this->deduct($full, ['quantity' => 1, 'reason' => 'Duplicate scan at the counter'])->assertSessionHasNoErrors();
        $full->refresh();
        $this->assertSame('active', $full->status);
        $this->assertNull($full->completed_at);

        $redeemed = $this->card(20, 4, 'redeemed');
        $this->deduct($redeemed, ['quantity' => 1, 'reason' => 'Duplicate scan at the counter'])->assertSessionHasErrors('quantity');
        $this->assertSame(20, $redeemed->refresh()->stamps_count);
    }

    public function test_a_card_outside_this_store_is_out_of_reach(): void
    {
        $other = Store::create(['name' => 'Other', 'code' => 'B', 'company_id' => $this->store->company_id]);
        $card = StampCard::create([
            'customer_id' => $this->member->id, 'stamp_program_id' => $this->program->id,
            'store_id' => $other->id, 'stamps_count' => 3, 'status' => 'active',
        ]);

        $this->deduct($card, ['quantity' => 1, 'reason' => 'Scanned the wrong member'])->assertNotFound();
        $this->assertSame(3, $card->refresh()->stamps_count);
    }
}
