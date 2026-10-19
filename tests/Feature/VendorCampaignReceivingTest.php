<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\Company;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockPack;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\CampaignReceivingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Campaigns › Receive: a store confirms the reward stock a hub transfer sent
 * it. Scanning is optional — every line can be counted by hand and marked
 * Good, Damaged or Unserved — and posting has the hub's own ledger effect.
 *
 * Unserved is not the end of a line: it stays in transit, on the trail, until
 * it arrives or the SOURCE returns it to stock or writes it off.
 */
class VendorCampaignReceivingTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $cashier;

    private Vendor $otherCashier;

    private Store $store;

    private Asset $reward;

    private Asset $consumable;

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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('item_code')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('description')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('barcode');
            $table->text('qrcode')->nullable();
            $table->string('bulk_uom');
            $table->unsignedInteger('units_per_pack');
            $table->timestamps();
        });
        Schema::create('stock_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('source_stock_in_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('serial_no')->nullable();
            $table->string('barcode')->nullable();
            $table->text('qrcode')->nullable();
            $table->string('origin_location')->nullable();
            $table->string('destination_location')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no')->nullable();
            $table->string('origin_location');
            $table->string('destination_location');
            $table->string('status');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('source_stock_in_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_receivings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_transfer_id')->nullable();
            $table->string('receiving_no')->nullable();
            $table->date('receiving_date');
            $table->string('origin_location')->nullable();
            $table->string('destination_location')->nullable();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('stock_pack_id')->nullable();
            $table->unsignedBigInteger('source_stock_in_id')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('barcode')->nullable();
            $table->text('qrcode')->nullable();
            $table->integer('transferred_quantity')->default(1);
            $table->integer('received_quantity')->default(1);
            $table->string('condition')->default('Good');
            $table->text('damage_notes')->nullable();
            $table->string('status')->default('For Receiving');
            $table->string('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('location');
            $table->string('transaction_type');
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('stamp_redemption_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stamp_redemption_id');
            $table->unsignedBigInteger('stock_in_id');
            $table->timestamps();
        });

        $company = Company::create(['name' => 'Entity', 'code' => 'CMP']);
        $this->store = Store::create(['name' => 'Assigned store', 'code' => 'A', 'company_id' => $company->id]);
        $otherStore = Store::create(['name' => 'Other store', 'code' => 'B', 'company_id' => $company->id]);
        $this->cashier = $this->cashierAt($this->store, 'A');
        $this->otherCashier = $this->cashierAt($otherStore, 'B');
        $this->reward = Asset::create(['item_code' => 'TUMBLER', 'brand' => 'CBTL', 'model' => '12oz', 'type' => 'Fixed']);
        $this->consumable = Asset::create(['item_code' => 'STICKER', 'type' => 'Consumables']);
    }

    private function cashierAt(Store $store, string $suffix): Vendor
    {
        return Vendor::create([
            'code' => 'CASHIER-'.$suffix, 'name' => 'Cashier '.$suffix, 'email' => "cashier-{$suffix}@example.com",
            'password' => 'password123', 'vendor_type' => 'Cashier', 'status' => 'active',
            'is_active' => true, 'store_id' => $store->id,
        ]);
    }

    /**
     * What the hub leaves behind for each piece: a posted stock-in at the
     * origin with its Stock In, a posted transfer with its Transfer Out, and a
     * pending receiving row at the destination.
     *
     * @param  array<int, array{asset: Asset, qty?: int, serial?: string, barcode?: string, pack?: StockPack}>  $pieces
     * @return array<int, StockReceiving>
     */
    private function ship(string $destination, array $pieces, string $receivingNo = 'RCV-20261019-ABC123', string $origin = 'WH'): array
    {
        return array_map(function (array $piece) use ($destination, $receivingNo, $origin) {
            $qty = $piece['qty'] ?? 1;
            $stockIn = StockIn::create([
                'asset_id' => $piece['asset']->id, 'quantity' => $qty, 'status' => 'Posted',
                'serial_no' => $piece['serial'] ?? null, 'barcode' => $piece['barcode'] ?? null,
                'origin_location' => 'Supplier', 'destination_location' => $origin,
            ]);
            InventoryTransaction::create([
                'asset_id' => $piece['asset']->id, 'location' => $origin, 'transaction_type' => 'Stock In',
                'quantity' => $qty, 'reference_type' => StockIn::class, 'reference_id' => $stockIn->id,
            ]);
            $transfer = StockTransfer::forceCreate([
                'transfer_no' => 'TR-1', 'origin_location' => $origin, 'destination_location' => $destination,
                'status' => 'Posted', 'asset_id' => $piece['asset']->id, 'source_stock_in_id' => $stockIn->id, 'quantity' => $qty,
            ]);
            InventoryTransaction::create([
                'asset_id' => $piece['asset']->id, 'location' => $origin, 'transaction_type' => 'Transfer Out',
                'quantity' => -$qty, 'reference_type' => StockTransfer::class, 'reference_id' => $transfer->id,
            ]);

            return StockReceiving::create([
                'stock_transfer_id' => $transfer->id, 'receiving_no' => $receivingNo, 'receiving_date' => '2026-10-19',
                'origin_location' => $origin, 'destination_location' => $destination,
                'asset_id' => $piece['asset']->id, 'source_stock_in_id' => $stockIn->id,
                'stock_pack_id' => ($piece['pack'] ?? null)?->id,
                'serial_no' => $piece['serial'] ?? null, 'barcode' => $piece['barcode'] ?? null,
                'transferred_quantity' => $qty, 'received_quantity' => $qty,
                'condition' => 'Good', 'status' => 'For Receiving', 'remarks' => 'From the transfer memo',
            ]);
        }, $pieces);
    }

    /** Three tumblers (one box of two plus a loose one) and five stickers. */
    private function standardShipment(): array
    {
        $box = StockPack::create(['asset_id' => $this->reward->id, 'barcode' => 'BOX-1', 'bulk_uom' => 'BOX', 'units_per_pack' => 2]);

        return $this->ship('A', [
            ['asset' => $this->reward, 'serial' => 'SN-1', 'barcode' => 'BC-1', 'pack' => $box],
            ['asset' => $this->reward, 'serial' => 'SN-2', 'barcode' => 'BC-2', 'pack' => $box],
            ['asset' => $this->reward, 'serial' => 'SN-3', 'barcode' => 'BC-3'],
            ['asset' => $this->consumable, 'qty' => 5],
        ]);
    }

    /** One tumbler sent from store B to store A, which A reports unserved. */
    private function unservedFromOtherStore(): StockReceiving
    {
        [$line] = $this->ship('A', [['asset' => $this->reward, 'barcode' => 'BC-9']], 'RCV-20261019-FROMB', 'B');

        $this->receive($this->cashier, $line, [$this->line($line, 'Unserved', 0, 'Not on the truck')])
            ->assertSessionHasNoErrors();

        return $line->fresh();
    }

    private function receive(Vendor $cashier, StockReceiving $anchor, array $entries, array $extra = [])
    {
        return $this->actingAs($cashier, 'vendor')->post(
            route('vendor.campaigns.receivings.receive', $anchor),
            array_merge(['entries' => $entries], $extra),
        );
    }

    private function settle(Vendor $cashier, string $action, array $ids, string $reason = 'Found in the stockroom')
    {
        return $this->actingAs($cashier, 'vendor')->post(route('vendor.campaigns.outgoing.resolve'), [
            'action' => $action, 'ids' => $ids, 'reason' => $reason,
        ]);
    }

    private function line(StockReceiving $row, string $condition = 'Good', ?int $qty = null, ?string $notes = null): array
    {
        return [
            'id' => $row->id,
            'received_quantity' => $qty ?? $row->transferred_quantity,
            'condition' => $condition,
            'damage_notes' => $notes,
        ];
    }

    private function stockAt(string $location, Asset $asset): int
    {
        return (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'test_valid')
            ->where('inventory_transactions.asset_id', $asset->id)
            ->where('inventory_transactions.location', $location)
            ->sum('inventory_transactions.quantity');
    }

    /** What the hub's Transaction Trail lists for a location: the valid ledger. */
    private function trailAt(string $location): array
    {
        return InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'test_trail')
            ->where('inventory_transactions.location', $location)
            ->orderBy('inventory_transactions.id')
            ->pluck('inventory_transactions.transaction_type')
            ->all();
    }

    private function unitsAt(array $variants): array
    {
        $locator = new class
        {
            use LocatesInventoryUnits;

            public function at(array $variants)
            {
                return $this->fixedUnitsCurrentlyAt($variants);
            }
        };

        return $locator->at($variants)->pluck('barcode')->sort()->values()->all();
    }

    public function test_a_store_receives_by_hand_without_scanning_and_tags_every_line(): void
    {
        [$good, $damaged, $unserved, $stickers] = $this->standardShipment();

        $this->receive($this->cashier, $stickers, [
            $this->line($good),
            $this->line($damaged, 'Damaged', 1, 'Cracked lid'),
            // A quantity sent with Unserved is ignored: unserved means none arrived.
            $this->line($unserved, 'Unserved', 1, 'Not on the truck'),
            $this->line($stickers),
        ], ['remarks' => 'Checked at the back door'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        foreach ([$good, $damaged, $stickers] as $row) {
            $this->assertSame('Received', $row->fresh()->status);
            $this->assertSame('Cashier A', $row->fresh()->received_by);
        }
        $this->assertSame('Damaged', $damaged->fresh()->condition);
        $this->assertSame('Cracked lid', $damaged->fresh()->damage_notes);
        $this->assertNull($good->fresh()->damage_notes);
        $this->assertSame('Checked at the back door', $good->fresh()->remarks);

        // The unserved line is NOT closed: still pending, its transfer still in
        // transit, stored in the hub's vocabulary, and reported by the cashier.
        $open = $unserved->fresh();
        $this->assertSame('For Receiving', $open->status);
        $this->assertSame(0, $open->received_quantity);
        $this->assertSame('Missing', $open->condition);
        $this->assertSame('Not on the truck', $open->damage_notes);
        $this->assertSame('Cashier A', $open->received_by);
        $this->assertNull($open->received_at);
        $this->assertSame('Posted', StockTransfer::find($unserved->stock_transfer_id)->status);
        $this->assertSame(
            ['Received'],
            StockTransfer::whereKeyNot($unserved->stock_transfer_id)->pluck('status')->unique()->values()->all(),
        );

        // The hub's ledger effect, plus the unserved report on the trail.
        $this->assertSame(
            [[$good->id, 1], [$damaged->id, 1], [$stickers->id, 5]],
            InventoryTransaction::where('transaction_type', 'Transfer In')->orderBy('id')
                ->get()->map(fn ($tx) => [$tx->reference_id, $tx->quantity])->all(),
        );
        $entry = InventoryTransaction::where('transaction_type', 'Receiving Unserved')->sole();
        $this->assertSame([$unserved->id, 'A', 0], [$entry->reference_id, $entry->location, $entry->quantity]);
        $this->assertContains('Receiving Unserved', $this->trailAt('A'));

        $this->assertSame(2, $this->stockAt('A', $this->reward));
        $this->assertSame(5, $this->stockAt('A', $this->consumable));
        // The source stays deducted while the piece is in transit.
        $this->assertSame(0, $this->stockAt('WH', $this->reward));
    }

    public function test_every_line_must_be_accounted_for_and_quantities_must_fit(): void
    {
        [$a, $b, $c, $stickers] = $this->standardShipment();

        // A line left out is refused, not assumed.
        $this->receive($this->cashier, $a, [$this->line($a), $this->line($b), $this->line($c)])
            ->assertSessionHasErrors('entries');
        // More than was sent.
        $this->receive($this->cashier, $a, [$this->line($a), $this->line($b), $this->line($c), $this->line($stickers, 'Good', 6)])
            ->assertSessionHasErrors('entries');
        // Part of a line: the rest would vanish with no trail.
        $this->receive($this->cashier, $a, [$this->line($a), $this->line($b), $this->line($c), $this->line($stickers, 'Good', 3)])
            ->assertSessionHasErrors('entries');
        // Good with nothing received: that is Unserved, and it must be said.
        $this->receive($this->cashier, $a, [$this->line($a, 'Good', 0), $this->line($b), $this->line($c), $this->line($stickers)])
            ->assertSessionHasErrors('entries');
        // A condition the hub would not understand.
        $this->receive($this->cashier, $a, [$this->line($a, 'Lost'), $this->line($b), $this->line($c), $this->line($stickers)])
            ->assertSessionHasErrors('entries.0.condition');

        $this->assertSame(4, StockReceiving::where('status', 'For Receiving')->where('condition', 'Good')->count());
        $this->assertSame(0, InventoryTransaction::whereIn('transaction_type', ['Transfer In', 'Receiving Unserved'])->count());
        $this->assertSame(['Posted'], StockTransfer::pluck('status')->unique()->values()->all());
    }

    public function test_a_receiving_is_posted_only_once(): void
    {
        $rows = $this->standardShipment();
        $entries = array_map(fn ($row) => $this->line($row), $rows);

        $this->receive($this->cashier, $rows[0], $entries)->assertSessionHasNoErrors();
        $this->receive($this->cashier, $rows[0], $entries)->assertSessionHasErrors('entries');

        $this->assertSame(4, InventoryTransaction::where('transaction_type', 'Transfer In')->count());
    }

    public function test_the_unserved_leftover_can_be_received_late_without_repeating_its_trail_entry(): void
    {
        [$first, $late] = $this->ship('A', [
            ['asset' => $this->reward, 'barcode' => 'BC-1'],
            ['asset' => $this->reward, 'barcode' => 'BC-2'],
        ]);

        $this->receive($this->cashier, $first, [$this->line($first), $this->line($late, 'Unserved', 0)])
            ->assertSessionHasNoErrors();
        // Opened again and still not there: posting it again changes nothing.
        $this->receive($this->cashier, $late, [$this->line($late, 'Unserved', 0)])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'Receiving Unserved')->count());

        // Then it turns up.
        $this->receive($this->cashier, $late, [$this->line($late->fresh(), 'Good', 1)])
            ->assertSessionHasNoErrors();

        $late->refresh();
        $this->assertSame(['Received', 1, 'Good'], [$late->status, $late->received_quantity, $late->condition]);
        $this->assertSame('Received', StockTransfer::find($late->stock_transfer_id)->status);
        $this->assertSame(2, $this->stockAt('A', $this->reward));
        // The story stays told: reported unserved, then received.
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'Receiving Unserved')->count());
        $this->assertSame(['BC-1', 'BC-2'], $this->unitsAt(['A', 'Assigned store']));
    }

    public function test_the_source_returns_an_unserved_item_to_its_own_stock(): void
    {
        $line = $this->unservedFromOtherStore();
        $this->assertSame(0, $this->stockAt('B', $this->reward));

        $service = app(CampaignReceivingService::class);
        $this->assertCount(1, $service->outgoingInTransit(['B', 'Other store']));
        // In transit, the piece is not on A's shelf (and the pickers reject it
        // at B while its transfer is still Posted).
        $this->assertSame([], $this->unitsAt(['A', 'Assigned store']));

        $this->settle($this->otherCashier, 'return', [$line->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $line->refresh();
        $this->assertSame(['Declined', 0, 'Cashier B', 'Found in the stockroom'], [$line->status, $line->received_quantity, $line->received_by, $line->remarks]);
        $this->assertSame('Declined', StockTransfer::find($line->stock_transfer_id)->status);
        $this->assertSame(1, $this->stockAt('B', $this->reward));
        $this->assertSame(0, $this->stockAt('A', $this->reward));
        $this->assertSame(['Stock In', 'Transfer Out', 'Receiving Declined'], $this->trailAt('B'));
        $this->assertSame(['Receiving Unserved'], $this->trailAt('A'));
        // Back on B's shelf, so it can be sent again or redeemed there.
        $this->assertSame(['BC-9'], $this->unitsAt(['B', 'Other store']));
        $this->assertCount(0, $service->outgoingInTransit(['B', 'Other store']));
    }

    public function test_the_source_writes_off_an_item_lost_in_transit(): void
    {
        $line = $this->unservedFromOtherStore();

        $this->settle($this->otherCashier, 'write_off', [$line->id], 'Lost by the courier')
            ->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame(['Received', 0, 'Missing', 'Lost by the courier'], [$line->status, $line->received_quantity, $line->condition, $line->remarks]);
        $this->assertSame('Received', StockTransfer::find($line->stock_transfer_id)->status);
        // B stays deducted, A was never credited, and the loss is on B's trail.
        $this->assertSame(0, $this->stockAt('B', $this->reward));
        $this->assertSame(0, $this->stockAt('A', $this->reward));
        $this->assertSame(['Stock In', 'Transfer Out', 'Unserved Written Off'], $this->trailAt('B'));
        $written = InventoryTransaction::where('transaction_type', 'Unserved Written Off')->sole();
        $this->assertSame(0, $written->quantity);
        // Gone: not at A even though its transfer reads Received, nor back at B.
        $this->assertSame([], $this->unitsAt(['A', 'Assigned store']));
        $this->assertSame([], $this->unitsAt(['B', 'Other store']));
    }

    public function test_only_the_source_settles_and_only_lines_still_in_transit(): void
    {
        $line = $this->unservedFromOtherStore();
        [$pending] = $this->ship('A', [['asset' => $this->reward, 'barcode' => 'BC-5']], 'RCV-20261019-NEWONE', 'B');

        // The destination cannot settle what it reported.
        $this->settle($this->cashier, 'return', [$line->id])->assertSessionHasErrors('ids');
        // A line nobody reported unserved is still the destination's to count.
        $this->settle($this->otherCashier, 'return', [$pending->id])->assertSessionHasErrors('ids');
        // A reason is required.
        $this->settle($this->otherCashier, 'write_off', [$line->id], '')->assertSessionHasErrors('reason');

        $this->assertSame('For Receiving', $line->fresh()->status);
        $this->assertSame(0, InventoryTransaction::whereIn('transaction_type', ['Receiving Declined', 'Unserved Written Off'])->count());

        // Settled once, it cannot be settled again.
        $this->settle($this->otherCashier, 'return', [$line->id])->assertSessionHasNoErrors();
        $this->settle($this->otherCashier, 'write_off', [$line->id])->assertSessionHasErrors('ids');
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'Receiving Declined')->count());
    }

    public function test_another_stores_receiving_is_out_of_reach(): void
    {
        $rows = $this->standardShipment();
        $entries = array_map(fn ($row) => $this->line($row), $rows);

        $this->actingAs($this->otherCashier, 'vendor')
            ->getJson(route('vendor.campaigns.receivings.show', $rows[0]))
            ->assertNotFound();
        $this->receive($this->otherCashier, $rows[0], $entries)->assertNotFound();

        $this->assertSame(4, StockReceiving::where('status', 'For Receiving')->count());
        $this->assertSame(0, InventoryTransaction::where('transaction_type', 'Transfer In')->count());
    }

    public function test_a_receiving_addressed_by_store_name_belongs_to_that_store(): void
    {
        [$row] = $this->ship('Assigned store', [['asset' => $this->reward, 'barcode' => 'BC-9']]);

        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.receivings.show', $row))
            ->assertOk()
            ->assertJsonCount(1, 'rows');
    }

    public function test_the_modal_gets_every_line_with_its_box_and_the_counter_vocabulary(): void
    {
        $rows = $this->standardShipment();

        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.receivings.show', $rows[3]))
            ->assertOk()
            ->assertJsonPath('header.receiving_no', 'RCV-20261019-ABC123')
            ->assertJsonPath('header.status', 'For Receiving')
            ->assertJsonPath('header.remarks', 'From the transfer memo')
            ->assertJsonCount(4, 'rows')
            ->assertJsonPath('rows.0.pack.barcode', 'BOX-1')
            ->assertJsonPath('rows.0.asset.item_code', 'TUMBLER')
            ->assertJsonPath('rows.0.in_transit', false)
            ->assertJsonPath('rows.2.pack', null);

        $this->receive($this->cashier, $rows[0], [
            $this->line($rows[0]), $this->line($rows[1]), $this->line($rows[2], 'Unserved', 0), $this->line($rows[3]),
        ])->assertSessionHasNoErrors();

        // The posted part and the in-transit leftover are now separate.
        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.receivings.show', $rows[0]))
            ->assertOk()
            ->assertJsonPath('header.status', 'Received')
            ->assertJsonPath('header.received_by', 'Cashier A')
            ->assertJsonCount(3, 'rows');
        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.receivings.show', $rows[2]))
            ->assertOk()
            ->assertJsonPath('header.status', 'For Receiving')
            ->assertJsonCount(1, 'rows')
            // Missing is shown as the counter said it.
            ->assertJsonPath('rows.0.condition', 'Unserved')
            ->assertJsonPath('rows.0.in_transit', true);
    }

    public function test_the_list_groups_pieces_into_receivings_and_totals_them(): void
    {
        $rows = $this->standardShipment();
        $this->ship('A', [['asset' => $this->reward, 'barcode' => 'BC-7']], 'RCV-20261019-PENDNG');
        $this->ship('B', [['asset' => $this->reward, 'barcode' => 'BC-8']], 'RCV-20261019-OTHERS');

        $this->receive($this->cashier, $rows[0], [
            $this->line($rows[0]), $this->line($rows[1], 'Damaged', 1), $this->line($rows[2], 'Unserved', 0), $this->line($rows[3]),
        ])->assertSessionHasNoErrors();

        $service = app(CampaignReceivingService::class);
        $list = $service->receivingsAt(['A', 'Assigned store']);

        // The other store's delivery is not listed; everything pending sorts first.
        $this->assertSame(
            [['RCV-20261019-PENDNG', 'For Receiving'], ['RCV-20261019-ABC123', 'For Receiving'], ['RCV-20261019-ABC123', 'Received']],
            $list->map(fn ($g) => [$g->receiving_no, $g->status])->all(),
        );
        $this->assertSame([0, 1, 0], $list->pluck('in_transit_quantity')->all());
        $posted = $list[2];
        $this->assertSame([7, 7, 1, 0, 2], [
            $posted->transferred_quantity, $posted->received_quantity, $posted->damaged_quantity,
            $posted->written_off_quantity, $posted->asset_count,
        ]);

        $this->assertSame([
            'pending' => 1,
            'units_pending' => 1,
            'units_received' => 7,
            'units_damaged' => 1,
            'units_in_transit' => 1,
        ], $service->summarize($list));
    }

    public function test_damaged_and_unserved_pieces_are_not_offered_as_rewards(): void
    {
        [$good, $damaged, $unserved, $stickers] = $this->standardShipment();

        $this->receive($this->cashier, $good, [
            $this->line($good), $this->line($damaged, 'Damaged', 1), $this->line($unserved, 'Unserved', 0), $this->line($stickers),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->cashier, 'vendor')
            ->getJson(route('vendor.campaigns.assets.units-at-location', $this->reward))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.stock_in_id', $good->source_stock_in_id);
    }
}
