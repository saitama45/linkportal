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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A POS receipt earns stamps once per store, whichever way the stamps are
 * entered. The boundary is the store, not the whole table: each outlet runs its
 * own receipt series, so the same number turning up at another branch is a
 * different sale and must be accepted.
 */
class VendorCampaignReceiptTest extends TestCase
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
            $table->decimal('auto_stamp_amount', 12, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('stamp_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('stamp_program_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->integer('stamps_count')->default(0);
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

    /** Signed the way LoyaltyQrService expects, so the scan resolves for real. */
    private function tokenFor(Customer $customer): string
    {
        return 'LCARD1:'.$customer->id.':'.substr(hash_hmac('sha256', 'LCARD1:'.$customer->id, config('app.key')), 0, 24);
    }

    private function scan(Vendor $cashier, Customer $customer, string $receipt)
    {
        return $this->actingAs($cashier, 'vendor')->postJson(route('vendor.campaigns.scan.add-stamp'), [
            'token' => $this->tokenFor($customer),
            'stamp_program_id' => $this->program->id,
            'quantity' => 1,
            'purchase_amount' => 150,
            'receipt_number' => $receipt,
        ]);
    }

    private function cardAt(Store $store, Customer $customer): StampCard
    {
        return StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $this->program->id,
            'store_id' => $store->id, 'stamps_count' => 0, 'status' => 'active',
        ]);
    }

    private function addManually(Vendor $cashier, StampCard $card, array $overrides = [])
    {
        return $this->actingAs($cashier, 'vendor')->post(
            route('vendor.campaigns.cards.add-stamps', $card),
            array_merge(['quantity' => 1, 'purchase_amount' => 150, 'receipt_number' => 'OR-2001'], $overrides),
        );
    }

    /** Record Purchase only exists for a program with an amount-per-stamp rule. */
    private function recordPurchase(Vendor $cashier, StampCard $card, array $overrides = [])
    {
        $this->program->update(['auto_stamp_amount' => 100]);

        return $this->actingAs($cashier, 'vendor')->post(
            route('vendor.campaigns.cards.record-purchase', $card),
            array_merge(['purchase_amount' => 250, 'receipt_number' => 'OR-3001'], $overrides),
        );
    }

    public function test_a_scanned_receipt_cannot_earn_stamps_twice_at_the_same_store(): void
    {
        $this->scan($this->cashier, $this->member('First'), 'OR-1001')->assertOk();

        // A different member, and a different case: still the same receipt.
        $this->scan($this->cashier, $this->member('Second'), 'or-1001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_number');

        $this->assertSame(1, StampEntry::count());
        // The refused scan must not leave behind the card it would have opened.
        $this->assertSame(1, StampCard::count());
    }

    public function test_the_same_receipt_number_is_accepted_at_another_store(): void
    {
        $this->scan($this->cashier, $this->member('First'), 'OR-1001')->assertOk();
        $this->scan($this->otherCashier, $this->member('Second'), 'OR-1001')->assertOk();

        $this->assertSame(
            [$this->store->id, $this->otherStore->id],
            StampEntry::where('receipt_number', 'OR-1001')->orderBy('id')->pluck('store_id')->all(),
        );
    }

    public function test_adding_stamps_manually_requires_a_receipt_number(): void
    {
        $card = $this->cardAt($this->store, $this->member('First'));

        $this->addManually($this->cashier, $card, ['receipt_number' => ''])
            ->assertSessionHasErrors('receipt_number');

        $this->assertSame(0, StampEntry::count());
        $this->assertSame(0, $card->fresh()->stamps_count);
    }

    public function test_adding_stamps_manually_records_the_receipt_and_refuses_a_repeat(): void
    {
        $card = $this->cardAt($this->store, $this->member('First'));

        $this->addManually($this->cashier, $card)->assertSessionHasNoErrors();
        $this->assertSame('OR-2001', StampEntry::sole()->receipt_number);

        $this->addManually($this->cashier, $card)->assertSessionHasErrors('receipt_number');

        $this->assertSame(1, StampEntry::count());
        $this->assertSame(1, $card->fresh()->stamps_count);
    }

    public function test_a_receipt_already_scanned_cannot_be_reused_manually_but_another_store_may_use_it(): void
    {
        $this->scan($this->cashier, $this->member('First'), 'OR-2001')->assertOk();

        $this->addManually($this->cashier, $this->cardAt($this->store, $this->member('Second')))
            ->assertSessionHasErrors('receipt_number');

        $this->addManually($this->otherCashier, $this->cardAt($this->otherStore, $this->member('Third')))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StampEntry::count());
    }

    public function test_recording_a_purchase_requires_a_receipt_number(): void
    {
        $card = $this->cardAt($this->store, $this->member('First'));

        $this->recordPurchase($this->cashier, $card, ['receipt_number' => ''])
            ->assertSessionHasErrors('receipt_number');

        $this->assertSame(0, StampEntry::count());
        $this->assertSame(0, $card->fresh()->stamps_count);
    }

    public function test_recording_a_purchase_records_the_receipt_and_refuses_a_repeat(): void
    {
        $card = $this->cardAt($this->store, $this->member('First'));

        $this->recordPurchase($this->cashier, $card)->assertSessionHasNoErrors();
        $entry = StampEntry::sole();
        $this->assertSame('OR-3001', $entry->receipt_number);
        $this->assertSame(2, $entry->quantity);

        $this->recordPurchase($this->cashier, $card)->assertSessionHasErrors('receipt_number');

        $this->assertSame(1, StampEntry::count());
        $this->assertSame(2, $card->fresh()->stamps_count);
    }

    public function test_a_receipt_used_for_a_recorded_purchase_is_shared_with_the_other_paths_but_not_other_stores(): void
    {
        $this->recordPurchase($this->cashier, $this->cardAt($this->store, $this->member('First')))
            ->assertSessionHasNoErrors();

        $this->addManually($this->cashier, $this->cardAt($this->store, $this->member('Second')), ['receipt_number' => 'OR-3001'])
            ->assertSessionHasErrors('receipt_number');
        $this->scan($this->cashier, $this->member('Third'), 'OR-3001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_number');

        $this->recordPurchase($this->otherCashier, $this->cardAt($this->otherStore, $this->member('Fourth')))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StampEntry::count());
    }
}
