<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\StampRedemptionUnit;
use App\Models\StockIn;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherRedemption;
use App\Services\LoyaltyQrService;
use App\Services\LoyaltyRedeemQrService;
use App\Support\CashierContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Campaigns — the vendor portal's counterpart to the LINK HUB's Loyalty Stamps
 * module (/stamps). Same five tabs, same rules, one deliberate difference in
 * reach: a cashier works one till, so everything is scoped to the store bound
 * to their account rather than to a switchable active entity.
 *
 * Read-only by design here: Customers, Programs and Vouchers. Those are set up
 * in the hub; the portal only earns and spends against them. Cards can be
 * stamped and redeemed but never created from scratch — a card comes into
 * existence by scanning a member, which is the counter's actual workflow.
 */
class CampaignController extends Controller
{
    use LocatesInventoryUnits;

    public function index()
    {
        $store = CashierContext::store();
        $storeId = (int) $store->id;
        $companyId = CashierContext::companyId();

        return Inertia::render('Vendor/Campaigns/Index', [
            'store' => $store->only(['id', 'code', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'email', 'phone', 'is_active']),
            // Programs of the store's entity, plus the unassigned ones — the same
            // "shared until explicitly assigned" allowance the hub makes.
            'programs' => StampProgram::with('company:id,code,name')
                ->when($companyId, fn ($q) => $q->where(
                    fn ($q2) => $q2->where('company_id', $companyId)->orWhereNull('company_id')
                ))
                ->orderBy('name')
                ->get(),
            'cards' => StampCard::with([
                'customer:id,name,email',
                'program:id,name,stamps_required,auto_stamp_amount',
                'store:id,code,name',
            ])
                ->where('store_id', $storeId)
                ->orderByDesc('id')
                ->get(),
            'redemptions' => StampRedemption::with([
                'customer:id,name',
                'program:id,name',
                'asset:id,item_code,brand,model,description',
                'creator:id,name',
                'cashierVendor:id,name',
                'units:id,stamp_redemption_id,stock_in_id,serial_no,barcode,qrcode',
            ])
                ->select('stamp_redemptions.*')
                ->addSelect([
                    'total_purchase_amount' => StampEntry::query()
                        ->selectRaw('COALESCE(SUM(purchase_amount), 0)')
                        ->whereColumn('stamp_entries.stamp_card_id', 'stamp_redemptions.stamp_card_id'),
                ])
                ->whereHas('card', fn ($q) => $q->where('store_id', $storeId))
                ->orderByDesc('id')
                ->get(),
            'summary' => [
                'customers' => Customer::count(),
                'active_cards' => StampCard::where('store_id', $storeId)->where('status', 'active')->count(),
                'completed_cards' => StampCard::where('store_id', $storeId)->where('status', 'completed')->count(),
                'redeemed_cards' => StampCard::where('store_id', $storeId)->where('status', 'redeemed')->count(),
                'total_amount' => StampEntry::whereHas('card', fn ($q) => $q->where('store_id', $storeId))->sum('purchase_amount'),
            ],
            ...$this->voucherProps($companyId),
        ]);
    }

    /** Read-only voucher listings; the cashier's only write is verify/redeem. */
    private function voucherProps(?int $companyId): array
    {
        if (! $companyId) {
            return ['voucherBatches' => [], 'voucherRedemptions' => [], 'voucherSummary' => []];
        }

        $batches = VoucherBatch::withCount([
            'vouchers',
            'vouchers as used_count' => fn ($q) => $q->where('status', 'used'),
            'vouchers as void_count' => fn ($q) => $q->where('status', 'void'),
        ])->where('company_id', $companyId)->latest()->get();

        // What the counter lists: the batches it can accept today, plus the
        // expired ones, which are what a customer turns up holding when a
        // voucher is refused — the cashier needs to be able to point at the
        // batch and its claim period. Draft, not-yet-valid, suspended and
        // cancelled batches never reached a customer, so they stay in the hub.
        //
        // Filtered here rather than in the query: `effective_status` folds the
        // claim window into the stored status, so it is only knowable per row.
        $listedBatches = $batches
            ->filter(fn ($b) => in_array($b->effectiveStatus(), ['active', 'expired'], true))
            ->values();

        $redemptions = VoucherRedemption::with([
            'voucher:id,voucher_batch_id,code',
            'voucher.batch:id,title,partner_name,company_id,status,claim_starts_on,claim_ends_on',
            'customer:id,name,phone',
            'store:id,code,name',
            'cashier:id,name',
            'cashierVendor:id,name',
        ])
            ->whereHas('voucher.batch', fn ($q) => $q->where('company_id', $companyId))
            ->latest('redeemed_at')
            ->limit(100)
            ->get();

        return [
            'voucherBatches' => $listedBatches,
            'voucherRedemptions' => $redemptions,
            'voucherSummary' => [
                // Counts the batches actually listed, so the stat and the table
                // can never disagree. Used/void/recognized stay over every
                // batch: those are history, and history does not expire.
                'batches' => $listedBatches->count(),
                'issued' => $batches->sum(fn ($b) => $b->vouchers_count - $b->used_count - $b->void_count),
                'used' => $batches->sum('used_count'),
                'void' => $batches->sum('void_count'),
                'recognized' => VoucherRedemption::whereNull('voided_at')
                    ->whereHas('voucher.batch', fn ($q) => $q->where('company_id', $companyId))
                    ->sum('applied_amount'),
            ],
        ];
    }

    /* ----------------------------------------------------------------------
     | Scan Customer QR — step 1 resolves the member, step 2 adds the stamps
     * ------------------------------------------------------------------- */

    public function resolveScan(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:255']);

        $customer = $this->customerFromToken($data['token']);

        return response()->json([
            'customer' => $customer->only(['id', 'name', 'email', 'phone']),
            'cards' => $customer->stampCards()
                ->with('program:id,name,stamps_required')
                ->whereIn('status', ['active', 'completed'])
                ->get(['id', 'stamp_program_id', 'stamps_count', 'status']),
        ]);
    }

    public function scanAddStamp(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
            'stamp_program_id' => 'required|exists:stamp_programs,id',
            'quantity' => 'nullable|integer|min:1|max:1000',
            'purchase_amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $this->assertProgramInScope((int) $data['stamp_program_id']);

        $customer = $this->customerFromToken($data['token']);
        $storeId = CashierContext::storeId();
        $vendorId = CashierContext::vendor()->id;
        $applied = 0;

        $card = DB::transaction(function () use ($customer, $data, $storeId, $vendorId, &$applied) {
            // Reuse the member's open card for this program at ANY store, the
            // way the hub does — a loyalty card follows the customer, not the
            // branch. Only a brand-new card is stamped with this till's store.
            $card = StampCard::where('customer_id', $customer->id)
                ->where('stamp_program_id', $data['stamp_program_id'])
                ->whereIn('status', ['active', 'completed'])
                ->first();

            if (! $card) {
                $card = StampCard::create([
                    'customer_id' => $customer->id,
                    'stamp_program_id' => $data['stamp_program_id'],
                    'store_id' => $storeId,
                    'stamps_count' => 0,
                    'status' => 'active',
                    'cashier_vendor_id' => $vendorId,
                ]);
            }

            $applied = $this->applyStamps(
                $card,
                (int) ($data['quantity'] ?? 1),
                'scan',
                $data['purchase_amount'],
                $data['note'] ?? null,
            );

            return $card->fresh(['customer:id,name', 'program:id,name,stamps_required']);
        });

        return response()->json(['card' => $card, 'applied' => $applied]);
    }

    /* ----------------------------------------------------------------------
     | Cards
     * ------------------------------------------------------------------- */

    public function addStamps(Request $request, StampCard $card)
    {
        $this->assertCardInScope($card);

        $data = $request->validate([
            'quantity' => 'required|integer|min:1|max:1000',
            'purchase_amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $this->applyStamps($card, $data['quantity'], 'manual', $data['purchase_amount'], $data['note'] ?? null);

        return back()->with('success', 'Stamps added.');
    }

    public function recordPurchase(Request $request, StampCard $card)
    {
        $this->assertCardInScope($card);

        $data = $request->validate([
            'purchase_amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $program = $card->program;
        if (! $program) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'This card has no stamp program assigned. Ask an administrator to reassign it.',
            ]);
        }
        if (! $program->auto_stamp_amount || (float) $program->auto_stamp_amount <= 0) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'This program has no amount-based earning rule configured.',
            ]);
        }

        $earned = (int) floor((float) $data['purchase_amount'] / (float) $program->auto_stamp_amount);
        if ($earned < 1) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'Purchase amount is below the value required to earn a stamp.',
            ]);
        }

        $this->applyStamps($card, $earned, 'purchase', $data['purchase_amount'], $data['note'] ?? null);

        return back()->with('success', "Recorded purchase — {$earned} stamp(s) earned.");
    }

    public function cardEntries(StampCard $card)
    {
        $this->assertCardInScope($card);

        return response()->json([
            'card' => $card->load(['customer:id,name', 'program:id,name,stamps_required']),
            'entries' => $card->entries()
                ->with(['store:id,code,name', 'creator:id,name', 'cashierVendor:id,name'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * Cap at the program threshold, flip the card to "completed" when it fills,
     * and return how many stamps actually fit — which can be fewer than were
     * asked for. Mirrors the hub's StampController::applyStamps.
     */
    private function applyStamps(StampCard $card, int $quantity, string $source, $purchaseAmount, ?string $note): int
    {
        if ($card->status !== 'active') {
            throw ValidationException::withMessages(['quantity' => 'Stamps can only be added to an active card.']);
        }

        if (! $card->program) {
            throw ValidationException::withMessages([
                'quantity' => 'This card has no stamp program assigned, so stamps cannot be added.',
            ]);
        }

        $required = (int) $card->program->stamps_required;
        $applied = min($quantity, max(0, $required - $card->stamps_count));

        if ($applied < 1) {
            throw ValidationException::withMessages(['quantity' => 'This card is already full.']);
        }

        $storeId = CashierContext::storeId();
        $vendorId = CashierContext::vendor()->id;

        DB::transaction(function () use ($card, $applied, $source, $purchaseAmount, $note, $storeId, $vendorId, $required) {
            StampEntry::create([
                'stamp_card_id' => $card->id,
                'store_id' => $storeId,
                'quantity' => $applied,
                'source' => $source,
                'purchase_amount' => $purchaseAmount,
                'note' => $note,
                'cashier_vendor_id' => $vendorId,
            ]);

            $card->stamps_count += $applied;
            $card->cashier_vendor_id = $vendorId;

            if ($card->stamps_count >= $required) {
                $card->stamps_count = $required;
                $card->status = 'completed';
                $card->completed_at = now();
            }

            $card->save();
        });

        return $applied;
    }

    /* ----------------------------------------------------------------------
     | Redemption (deducts inventory at this cashier's store)
     * ------------------------------------------------------------------- */

    public function resolveRedeemScan(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:255']);

        $cardId = LoyaltyRedeemQrService::decode($data['token']);
        if (! $cardId) {
            throw ValidationException::withMessages(['token' => 'That code is not a valid reward redemption QR.']);
        }

        $card = StampCard::with([
            'customer:id,name,email,phone,is_active',
            'program:id,name,stamps_required',
            'store:id,code,name',
        ])->find($cardId);

        if (! $card) {
            throw ValidationException::withMessages(['token' => 'That reward card no longer exists.']);
        }

        // Every failure is answered here rather than at redeem() time, so the
        // member gets a straight reason while they are still at the counter.
        if ($card->status === 'redeemed') {
            throw ValidationException::withMessages([
                'token' => 'This reward has already been redeemed'
                    .($card->redeemed_at ? ' on '.$card->redeemed_at->format('M j, Y g:i A') : '').'.',
            ]);
        }

        if ($card->status !== 'completed') {
            throw ValidationException::withMessages([
                'token' => "This card is not full yet ({$card->stamps_count} of {$card->program->stamps_required} stamps) and cannot be redeemed.",
            ]);
        }

        if ($card->customer && ! $card->customer->is_active) {
            throw ValidationException::withMessages(['token' => 'That member could not be found or is inactive.']);
        }

        return response()->json(['card' => $card]);
    }

    public function redeem(Request $request, StampCard $card)
    {
        $data = $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1|max:1000',
            'stock_in_ids' => 'required|array|min:1|max:1000',
            'stock_in_ids.*' => 'required|integer|distinct|exists:stock_ins,id',
            'remarks' => 'nullable|string|max:255',
        ]);

        $stockInIds = collect($data['stock_in_ids'])->map(fn ($id) => (int) $id)->values();
        if ($stockInIds->count() !== (int) $data['quantity']) {
            throw ValidationException::withMessages([
                'stock_in_ids' => 'Select one specific barcode/QR code for each quantity being redeemed.',
            ]);
        }

        $asset = Asset::findOrFail($data['asset_id']);
        // Not client-supplied: the reward leaves the shelf of the till the
        // cashier is signed in to, so the location is theirs to state, not the
        // request's.
        $location = CashierContext::store()->code;
        $variants = $this->locationVariants($location);
        $vendorId = CashierContext::vendor()->id;

        DB::transaction(function () use ($card, $asset, $location, $variants, $stockInIds, $data, $vendorId) {
            $lockedCard = StampCard::query()->lockForUpdate()->findOrFail($card->id);
            if ($lockedCard->status !== 'completed') {
                throw ValidationException::withMessages(['asset_id' => 'Only a completed card can be redeemed.']);
            }

            StockIn::query()->whereIn('id', $stockInIds)->lockForUpdate()->get();

            $soh = (int) InventoryTransaction::query()
                ->validInventoryLedger('inventory_transactions', 'portal_redeem_valid')
                ->where('inventory_transactions.asset_id', $asset->id)
                ->whereIn('inventory_transactions.location', $variants)
                ->sum('inventory_transactions.quantity');

            if ($soh < $stockInIds->count()) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock at {$location}. Available: {$soh}.",
                ]);
            }

            $availableUnits = $this->redeemableUnitsAt($asset, $variants, $soh)->keyBy('id');
            if ($stockInIds->diff($availableUnits->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'stock_in_ids' => 'One or more selected barcode/QR codes are no longer available at this location.',
                ]);
            }

            $redemption = StampRedemption::create([
                'stamp_card_id' => $lockedCard->id,
                'customer_id' => $lockedCard->customer_id,
                'stamp_program_id' => $lockedCard->stamp_program_id,
                'asset_id' => $asset->id,
                'location' => $location,
                'quantity' => $stockInIds->count(),
                'remarks' => $data['remarks'] ?? null,
                'cashier_vendor_id' => $vendorId,
            ]);

            foreach ($stockInIds as $stockInId) {
                $unit = $availableUnits->get($stockInId);
                StampRedemptionUnit::create([
                    'stamp_redemption_id' => $redemption->id,
                    'stock_in_id' => $unit->id,
                    'serial_no' => $unit->serial_no,
                    'barcode' => $unit->barcode,
                    'qrcode' => $unit->qrcode,
                ]);
            }

            $tx = InventoryTransaction::create([
                'asset_id' => $asset->id,
                'location' => $location,
                'transaction_type' => 'Stamp Redemption',
                // The hub writes its own class name here and both apps namespace
                // these models App\Models, so the two agree on the string.
                'reference_type' => StampRedemption::class,
                'reference_id' => $redemption->id,
                'quantity' => -1 * $stockInIds->count(),
            ]);

            $redemption->update(['inventory_transaction_id' => $tx->id]);

            $lockedCard->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'cashier_vendor_id' => $vendorId,
            ]);
        });

        return back()->with('success', 'Reward redeemed and deducted from inventory.');
    }

    /** Inventory items with positive stock on hand at this cashier's store. */
    public function assetsAtLocation()
    {
        $variants = $this->locationVariants(CashierContext::store()->code);

        $sohData = InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'portal_assets_valid')
            ->whereIn('inventory_transactions.location', $variants)
            ->groupBy('inventory_transactions.asset_id')
            ->selectRaw('inventory_transactions.asset_id, SUM(inventory_transactions.quantity) as total')
            ->pluck('total', 'asset_id')
            ->filter(fn ($soh) => $soh > 0);

        if ($sohData->isEmpty()) {
            return response()->json([]);
        }

        return response()->json(
            Asset::whereIn('id', $sohData->keys())
                ->orderBy('item_code')
                ->get(['id', 'item_code', 'brand', 'model', 'description', 'type', 'cost'])
                ->map(fn ($a) => array_merge($a->toArray(), ['soh' => (int) $sohData->get($a->id, 0)]))
                ->values()
        );
    }

    /** The specific coded units that may be picked for a redemption. */
    public function unitsAtLocation(Asset $asset)
    {
        $variants = $this->locationVariants(CashierContext::store()->code);

        $soh = (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'portal_unit_valid')
            ->where('inventory_transactions.asset_id', $asset->id)
            ->whereIn('inventory_transactions.location', $variants)
            ->sum('inventory_transactions.quantity');

        if ($soh <= 0) {
            return response()->json([]);
        }

        return response()->json(
            $this->redeemableUnitsAt($asset, $variants, $soh)
                ->map(fn (StockIn $unit) => [
                    'stock_in_id' => $unit->id,
                    'serial_no' => $unit->serial_no,
                    'barcode' => $unit->barcode,
                    'qrcode' => $unit->qrcode,
                ])
                ->values()
        );
    }

    private function redeemableUnitsAt(Asset $asset, array $locationVariants, int $soh)
    {
        return $this->fixedUnitsCurrentlyAt($locationVariants, function ($query) use ($asset) {
            $query->where('stock_ins.asset_id', $asset->id)
                ->whereNotIn('stock_ins.id', StampRedemptionUnit::query()->select('stock_in_id'));
        })
            ->reject(fn (StockIn $unit) => $unit->sourceStockTransfers->contains(
                fn ($transfer) => in_array($transfer->status, ['For Posting', 'Posted'], true)
            ))
            ->filter(fn (StockIn $unit) => filled($unit->barcode) || filled($unit->qrcode))
            ->take(max(0, $soh))
            ->values();
    }

    /* ----------------------------------------------------------------------
     | Scope guards — the cashier's store is the boundary, and a route model
     | bound by id would otherwise reach straight past it.
     * ------------------------------------------------------------------- */

    private function assertCardInScope(StampCard $card): void
    {
        abort_unless((int) $card->store_id === CashierContext::storeId(), 404);
    }

    private function assertProgramInScope(int $programId): void
    {
        $companyId = CashierContext::companyId();

        $inScope = StampProgram::where('id', $programId)
            ->when($companyId, fn ($q) => $q->where(
                fn ($q2) => $q2->where('company_id', $companyId)->orWhereNull('company_id')
            ))
            ->exists();

        if (! $inScope) {
            throw ValidationException::withMessages([
                'stamp_program_id' => 'That program is not available at this store.',
            ]);
        }
    }

    private function customerFromToken(string $token): Customer
    {
        $customerId = LoyaltyQrService::decode($token);
        if (! $customerId) {
            throw ValidationException::withMessages(['token' => 'That code is not a valid loyalty member QR.']);
        }

        $customer = Customer::find($customerId);
        if (! $customer || ! $customer->is_active) {
            throw ValidationException::withMessages(['token' => 'That member could not be found or is inactive.']);
        }

        return $customer;
    }
}
