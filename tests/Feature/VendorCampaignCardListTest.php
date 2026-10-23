<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Which cards a till sees. A scan reuses the member's active card wherever it
 * was first opened, so "issued at this store" is not the boundary: a card
 * started at another branch and stamped here is this store's business too. The
 * member's app shows that stamp against this store — the counter that took the
 * sale has to show it as well.
 */
class VendorCampaignCardListTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $cashier;

    private Vendor $otherCashier;

    private Store $store;

    private Store $otherStore;

    private StampProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--path' => 'database/migrations/portal'])->assertSuccessful();
        $this->withoutVite();

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
        Schema::create('stamp_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stamp_card_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('stamp_program_id');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('location');
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->string('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('cashier_vendor_id')->nullable();
            $table->timestamps();
        });
        Schema::create('stamp_redemption_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stamp_redemption_id');
            $table->unsignedBigInteger('stock_in_id');
            $table->string('serial_no')->nullable();
            $table->string('barcode')->nullable();
            $table->string('qrcode')->nullable();
            $table->timestamps();
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('item_code')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        // The page also renders the voucher tab; empty tables are all it needs.
        Schema::create('voucher_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('title');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('voucher_batch_id');
            $table->string('code');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('voucher_id');
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        // ...and the Receive tab.
        Schema::create('stock_receivings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_transfer_id')->nullable();
            $table->string('receiving_no')->nullable();
            $table->date('receiving_date');
            $table->string('origin_location')->nullable();
            $table->string('destination_location')->nullable();
            $table->unsignedBigInteger('asset_id');
            $table->integer('transferred_quantity')->default(1);
            $table->integer('received_quantity')->default(1);
            $table->string('condition')->default('Good');
            $table->string('status')->default('For Receiving');
            $table->string('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        $company = Company::create(['name' => 'Entity', 'code' => 'CMP']);
        $this->store = Store::create(['name' => 'Assigned store', 'code' => 'A', 'company_id' => $company->id]);
        $this->otherStore = Store::create(['name' => 'Other store', 'code' => 'B', 'company_id' => $company->id]);
        $this->cashier = $this->cashierAt($this->store, 'A');
        $this->otherCashier = $this->cashierAt($this->otherStore, 'B');
        $this->program = StampProgram::create(['company_id' => $company->id, 'name' => 'Coffee card', 'stamps_required' => 10]);
    }

    private function cashierAt(Store $store, string $suffix): Vendor
    {
        return Vendor::create([
            'code' => 'CASHIER-'.$suffix, 'name' => 'Cashier '.$suffix, 'email' => "cashier-{$suffix}@example.com",
            'password' => 'password123', 'vendor_type' => 'Cashier', 'status' => 'active',
            'is_active' => true, 'store_id' => $store->id,
        ]);
    }

    private function member(string $name): Customer
    {
        return Customer::create(['name' => $name, 'is_active' => true]);
    }

    private function cardAt(Store $store, Customer $customer, string $status = 'active'): StampCard
    {
        return StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $this->program->id,
            'store_id' => $store->id, 'stamps_count' => 0, 'status' => $status,
        ]);
    }

    /** Signed the way LoyaltyQrService expects, so the scan resolves for real. */
    private function scan(Vendor $cashier, Customer $customer, string $receipt)
    {
        return $this->actingAs($cashier, 'vendor')->postJson(route('vendor.campaigns.scan.add-stamp'), [
            'token' => 'LCARD1:'.$customer->id.':'.substr(hash_hmac('sha256', 'LCARD1:'.$customer->id, config('app.key')), 0, 24),
            'stamp_program_id' => $this->program->id,
            'quantity' => 3,
            'purchase_amount' => 150,
            'receipt_number' => $receipt,
        ]);
    }

    private function campaigns(Vendor $cashier)
    {
        return $this->actingAs($cashier, 'vendor')->get(route('vendor.campaigns.index'))->assertOk();
    }

    private function redeemedAt(Store $releasedFrom, StampCard $card): StampRedemption
    {
        $card->update(['status' => 'redeemed', 'redeemed_at' => now()]);

        return StampRedemption::create([
            'stamp_card_id' => $card->id, 'customer_id' => $card->customer_id,
            'stamp_program_id' => $card->stamp_program_id, 'location' => $releasedFrom->code, 'quantity' => 1,
        ]);
    }

    public function test_a_card_issued_elsewhere_is_listed_at_the_store_that_stamps_it(): void
    {
        // The reported case: the card was opened at another branch, and this
        // till's scan reused it instead of starting a new one.
        $member = $this->member('Regular');
        $card = $this->cardAt($this->otherStore, $member);

        $this->scan($this->cashier, $member, 'OR-1001')->assertOk();

        $this->assertSame(1, StampCard::count(), 'The scan must reuse the card, not open a second one.');
        $this->assertSame($this->store->id, StampEntry::sole()->store_id);
        $this->assertSame($this->otherStore->id, $card->fresh()->store_id);

        $this->campaigns($this->cashier)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Vendor/Campaigns/Index')
            ->has('cards', 1)
            ->where('cards.0.id', $card->id)
            ->where('cards.0.stamps_count', 3)
            ->where('cards.0.store.code', 'B')
            ->where('summary.active_cards', 1)
            ->where('summary.total_amount', fn ($amount) => (float) $amount === 150.0));
    }

    public function test_the_issuing_store_keeps_the_card_but_not_the_other_stores_sale(): void
    {
        $member = $this->member('Regular');
        $card = $this->cardAt($this->otherStore, $member);
        $this->scan($this->cashier, $member, 'OR-1001')->assertOk();

        $this->campaigns($this->otherCashier)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('cards', 1)
            ->where('cards.0.id', $card->id)
            ->where('summary.active_cards', 1)
            // Rung up at store A, so it is A's turnover, not the issuer's.
            ->where('summary.total_amount', fn ($amount) => (float) $amount === 0.0));
    }

    public function test_a_card_this_store_neither_issued_nor_stamped_stays_out_of_reach(): void
    {
        $card = $this->cardAt($this->otherStore, $this->member('Stranger'));
        $this->scan($this->otherCashier, $card->customer, 'OR-1001')->assertOk();

        $this->campaigns($this->cashier)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('cards', 0)
            ->where('summary.active_cards', 0)
            ->where('summary.total_amount', fn ($amount) => (float) $amount === 0.0));

        // Not listed must also mean not reachable by typing its id.
        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.cards.entries', $card))
            ->assertNotFound();
        $this->actingAs($this->cashier, 'vendor')
            ->post(route('vendor.campaigns.cards.add-stamps', $card), [
                'quantity' => 1, 'purchase_amount' => 150, 'receipt_number' => 'OR-9001',
            ])
            ->assertNotFound();

        $this->assertSame(3, $card->fresh()->stamps_count);
    }

    public function test_every_listed_card_can_be_opened_and_stamped_from_the_row(): void
    {
        $member = $this->member('Regular');
        $card = $this->cardAt($this->otherStore, $member);
        $this->scan($this->cashier, $member, 'OR-1001')->assertOk();

        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.cards.entries', $card))
            ->assertOk()
            ->assertJsonCount(1, 'entries');

        $this->actingAs($this->cashier, 'vendor')
            ->post(route('vendor.campaigns.cards.add-stamps', $card), [
                'quantity' => 1, 'purchase_amount' => 150, 'receipt_number' => 'OR-1002',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, $card->fresh()->stamps_count);
    }

    public function test_redemptions_list_what_left_this_shelf_and_what_was_claimed_on_its_cards(): void
    {
        // Released here on a card this store never issued or stamped.
        $releasedHere = $this->redeemedAt($this->store, $this->cardAt($this->otherStore, $this->member('Walk-in')));
        // This store's own card, claimed at the other branch.
        $claimedElsewhere = $this->redeemedAt($this->otherStore, $this->cardAt($this->store, $this->member('Regular')));
        // Nothing to do with this store at all.
        $this->redeemedAt($this->otherStore, $this->cardAt($this->otherStore, $this->member('Stranger')));

        $this->campaigns($this->cashier)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('redemptions', 2)
            ->where('redemptions.0.id', $claimedElsewhere->id)
            ->where('redemptions.1.id', $releasedHere->id)
            ->where('summary.redeemed_cards', 1));
    }
}
