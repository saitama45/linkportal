<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\StampRedemptionUnit;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\Store;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherRedemption;
use App\Services\CampaignReceivingService;
use App\Services\LoyaltyQrService;
use App\Services\LoyaltyRedeemQrService;
use App\Services\StampAllocator;
use App\Services\StampAward;
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
 *
 * A sixth tab, Receive, is the store's side of the hub's Receiving Stock: the
 * reward stock a transfer delivers here is confirmed by
 * CampaignReceivingController before any of it can be redeemed.
 */
class CampaignController extends Controller
{
    use LocatesInventoryUnits;

    /**
     * Whether a redemption must name each unit by scanning its barcode/QR.
     * Switched off for now: the cashier picks the reward item only, and the
     * next available coded unit on this store's shelf is assigned to it.
     */
    public const REQUIRE_UNIT_SCAN = false;

    public function index(CampaignReceivingService $receiving)
    {
        $store = CashierContext::store();
        $storeId = (int) $store->id;
        $companyId = CashierContext::companyId();
        $storeVariants = $this->locationVariants($store->code);
        $receivings = $receiving->receivingsAt($storeVariants);

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
                'program:id,name,stamps_required,holiday_stamps,max_cards_per_customer,auto_stamp_amount',
                'store:id,code,name',
            ])
                ->where($this->cardsOfStore($storeId))
                ->orderByDesc('id')
                ->get(),
            // Rewards that left this store's shelf, plus the ones claimed on
            // its cards elsewhere. `location` is the releasing store, and it is
            // not always the card's own: a reward QR is honoured at any branch.
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
                ->where(fn ($q) => $q
                    ->whereIn('stamp_redemptions.location', $this->locationVariants($store->code))
                    ->orWhereHas('card', fn ($card) => $card->where($this->cardsOfStore($storeId))))
                ->orderByDesc('id')
                ->get(),
            'summary' => [
                'customers' => Customer::count(),
                // Counted over the same cards the table lists, so the stat and
                // the rows can never disagree.
                'active_cards' => StampCard::where($this->cardsOfStore($storeId))->where('status', 'active')->count(),
                'completed_cards' => StampCard::where($this->cardsOfStore($storeId))->where('status', 'completed')->count(),
                'redeemed_cards' => StampCard::where($this->cardsOfStore($storeId))->where('status', 'redeemed')->count(),
                // Sales rung up at this till, by the entry's own store — the way
                // the hub totals them. A storeless entry (older hub stamping)
                // falls back to the store that issued its card.
                'total_amount' => StampEntry::where(fn ($q) => $q
                    ->where('stamp_entries.store_id', $storeId)
                    ->orWhere(fn ($legacy) => $legacy
                        ->whereNull('stamp_entries.store_id')
                        ->whereHas('card', fn ($card) => $card->where('stamp_cards.store_id', $storeId))))
                    ->sum('purchase_amount'),
            ],
            ...$this->voucherProps($companyId),
            'receivings' => $receivings,
            'receivingSummary' => $receiving->summarize($receivings),
            'outgoingInTransit' => $receiving->outgoingInTransit($storeVariants),
            'requireUnitScan' => self::REQUIRE_UNIT_SCAN,
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
            'cards' => $this->openCardsForScan($customer),
            // Cards held per program, redeemed ones included — what a
            // program's card limit counts against.
            'cards_held' => $customer->stampCards()
                ->selectRaw('stamp_program_id, COUNT(*) as held')
                ->groupBy('stamp_program_id')
                ->pluck('held', 'stamp_program_id'),
        ]);
    }

    /**
     * The member's unredeemed cards, each with its number among all their
     * cards for that program ("card 2 of 3") and its holiday/regular split,
     * so the scan modal can say where the next stamp will land. Mirrors the
     * hub's StampController::openCardsForScan.
     */
    private function openCardsForScan(Customer $customer)
    {
        $numbers = [];
        $all = $customer->stampCards()->orderBy('id')->get(['id', 'stamp_program_id']);
        foreach ($all->groupBy('stamp_program_id') as $group) {
            foreach ($group->values() as $i => $card) {
                $numbers[$card->id] = $i + 1;
            }
        }

        return $customer->stampCards()
            ->with('program:id,name,stamps_required,holiday_stamps,max_cards_per_customer')
            ->whereIn('status', ['active', 'completed'])
            ->orderBy('id')
            ->get(['id', 'stamp_program_id', 'stamps_count', 'holiday_count', 'status'])
            ->each(function (StampCard $card) use ($numbers) {
                $card->setAttribute('card_number', $numbers[$card->id] ?? null);
                if ($card->program) {
                    $card->setAttribute('holiday_filled', $card->holidayFilled($card->program));
                    $card->setAttribute('regular_filled', $card->regularFilled($card->program));
                }
            })
            ->values();
    }

    public function scanAddStamp(Request $request, StampAllocator $allocator)
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
            'stamp_program_id' => 'required|exists:stamp_programs,id',
            'stamp_type' => 'nullable|in:'.implode(',', StampAllocator::TYPES),
            'quantity' => 'nullable|integer|min:1|max:1000',
            'purchase_amount' => 'required|numeric|min:0.01',
            // Optional at the scan, unlike Add Stamps / Record Purchase: the
            // counter scans while the sale is being rung up, often before the
            // POS has printed a receipt. When one is given it is still held to
            // once-per-store (assertReceiptUnusedAtStore).
            'receipt_number' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:255',
        ]);

        $this->assertProgramInScope((int) $data['stamp_program_id']);

        $customer = $this->customerFromToken($data['token']);
        $program = StampProgram::findOrFail($data['stamp_program_id']);

        // Which card each stamp lands on is StampAllocator's call, the same as
        // at the hub: the member's oldest ACTIVE card with a free slot of the
        // requested type at ANY store (a loyalty card follows the customer,
        // not the branch), else a new card within the program's limit, stamped
        // with this till's store. A full card waiting to be redeemed is passed
        // over, not refused — the customer is buying again, and their earned
        // reward stays claimable on its own card.
        $award = $this->award(
            $allocator,
            (int) $customer->id,
            $program,
            $data['stamp_type'] ?? StampAllocator::REGULAR,
            (int) ($data['quantity'] ?? 1),
            $data['purchase_amount'],
            'scan',
            $data['note'] ?? null,
            filled($data['receipt_number'] ?? null) ? trim($data['receipt_number']) : null,
        );

        // The award can differ from the request — holiday stamps landing as
        // regular, stamps past the card limit refused — so the cashier is told
        // what actually happened rather than the request echoed back.
        return response()->json([
            'card' => $award->cards->last()->fresh(['customer:id,name', 'program:id,name,stamps_required,holiday_stamps']),
            ...$award->toArray(),
            'message' => $award->message($customer->name),
        ]);
    }

    /* ----------------------------------------------------------------------
     | Cards
     * ------------------------------------------------------------------- */

    public function addStamps(Request $request, StampCard $card, StampAllocator $allocator)
    {
        $this->assertCardInScope($card);

        $data = $request->validate([
            'stamp_type' => 'nullable|in:'.implode(',', StampAllocator::TYPES),
            'quantity' => 'required|integer|min:1|max:1000',
            'purchase_amount' => 'required|numeric|min:0.01',
            'receipt_number' => 'required|string|max:100',
            'note' => 'nullable|string|max:255',
        ]);

        $award = $this->awardFromCard(
            $card,
            $allocator,
            $data['stamp_type'] ?? StampAllocator::REGULAR,
            (int) $data['quantity'],
            $data['purchase_amount'],
            'manual',
            $data['note'] ?? null,
            trim($data['receipt_number']),
        );

        return back()->with('success', $award->message($card->customer?->name ?? 'this member'));
    }

    /**
     * Take back stamps added by mistake — a wrong member scanned, a wrong
     * quantity typed, a Holiday stamp that should have been Regular.
     *
     * Recorded, never erased: the correction is its own entry with a negative
     * quantity, source `deduction` and the cashier's reason in `note`, so the
     * card's history still shows the original stamp and who took it back and
     * why. The cashier says which kind of slot to empty (holiday or regular),
     * and can never take back more of that kind than the card holds.
     *
     * A redeemed card is refused — that reward has already left the counter.
     * A full card waiting to be redeemed drops back to active, which also
     * retires its redeem code (`resolveRedeemScan` only honours `completed`).
     */
    public function deductStamps(Request $request, StampCard $card)
    {
        $this->assertCardInScope($card);

        $data = $request->validate([
            'stamp_type' => 'nullable|in:'.implode(',', StampAllocator::TYPES),
            'quantity' => 'required|integer|min:1|max:1000',
            'reason' => 'required|string|min:5|max:255',
        ]);

        $storeId = CashierContext::storeId();
        $vendorId = CashierContext::vendor()->id;
        $quantity = (int) $data['quantity'];
        $reason = trim($data['reason']);

        [$type, $before, $after] = DB::transaction(function () use ($card, $data, $quantity, $reason, $storeId, $vendorId) {
            $locked = StampCard::with('program')->lockForUpdate()->findOrFail($card->id);

            if ($locked->status === 'redeemed') {
                throw ValidationException::withMessages([
                    'quantity' => 'This reward has already been redeemed, so its stamps can no longer be deducted.',
                ]);
            }
            if (! $locked->program) {
                throw ValidationException::withMessages([
                    'quantity' => 'This card has no stamp program assigned, so its stamps cannot be changed.',
                ]);
            }

            $type = $locked->program->holidaySlots() > 0 && ($data['stamp_type'] ?? null) === StampAllocator::HOLIDAY
                ? StampAllocator::HOLIDAY
                : StampAllocator::REGULAR;
            $available = $type === StampAllocator::HOLIDAY ? (int) $locked->holiday_count : $locked->regularCount();

            if ($quantity > $available) {
                $kind = $type === StampAllocator::HOLIDAY ? 'Holiday' : ($locked->program->holidaySlots() > 0 ? 'Regular' : '');
                throw ValidationException::withMessages([
                    'quantity' => "This card has only {$available} ".trim("{$kind} ".($available === 1 ? 'stamp' : 'stamps')).' to deduct.',
                ]);
            }

            $before = $locked->only(['stamps_count', 'holiday_count', 'status']);

            StampEntry::create([
                'stamp_card_id' => $locked->id,
                'store_id' => $storeId,
                'quantity' => -$quantity,
                'stamp_type' => $type,
                'source' => 'deduction',
                'note' => $reason,
                'cashier_vendor_id' => $vendorId,
            ]);

            $locked->stamps_count = (int) $locked->stamps_count - $quantity;
            if ($type === StampAllocator::HOLIDAY) {
                $locked->holiday_count = (int) $locked->holiday_count - $quantity;
            }
            if ($locked->status === 'completed' && $locked->stamps_count < (int) $locked->program->stamps_required) {
                $locked->status = 'active';
                $locked->completed_at = null;
            }
            $locked->cashier_vendor_id = $vendorId;
            $locked->save();

            return [$type, $before, $locked->only(['stamps_count', 'holiday_count', 'status'])];
        });

        AuditLogger::log('stamp_deducted', $card, $before, $after + ['quantity' => $quantity, 'stamp_type' => $type, 'reason' => $reason]);

        $noun = $quantity === 1 ? 'stamp' : 'stamps';
        $kind = $type === StampAllocator::HOLIDAY ? 'Holiday ' : '';

        return back()->with('success', "{$quantity} {$kind}{$noun} deducted from {$card->customer?->name}'s card.");
    }

    public function recordPurchase(Request $request, StampCard $card, StampAllocator $allocator)
    {
        $this->assertCardInScope($card);

        $data = $request->validate([
            'stamp_type' => 'nullable|in:'.implode(',', StampAllocator::TYPES),
            'purchase_amount' => 'required|numeric|min:0.01',
            'receipt_number' => 'required|string|max:100',
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

        $award = $this->awardFromCard(
            $card,
            $allocator,
            $data['stamp_type'] ?? StampAllocator::REGULAR,
            $earned,
            $data['purchase_amount'],
            'purchase',
            $data['note'] ?? null,
            trim($data['receipt_number']),
        );

        return back()->with('success', 'Recorded purchase — '.$award->message($card->customer?->name ?? 'this member'));
    }

    public function cardEntries(StampCard $card)
    {
        $this->assertCardInScope($card);

        return response()->json([
            'card' => $card->load(['customer:id,name', 'program:id,name,stamps_required,holiday_stamps']),
            'entries' => $card->entries()
                ->with(['store:id,code,name', 'creator:id,name', 'cashierVendor:id,name'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * Add Stamps / Record Purchase start from a card the cashier opened, but
     * the stamps are placed by the same rules as a scan — the card names the
     * member and program; StampAllocator decides the slot.
     */
    private function awardFromCard(StampCard $card, StampAllocator $allocator, string $type, int $quantity, $purchaseAmount, string $source, ?string $note, string $receiptNumber): StampAward
    {
        if ($card->status !== 'active') {
            throw ValidationException::withMessages(['quantity' => 'Stamps can only be added to an active card.']);
        }

        if (! $card->program) {
            throw ValidationException::withMessages([
                'quantity' => 'This card has no stamp program assigned, so stamps cannot be added.',
            ]);
        }

        return $this->award($allocator, (int) $card->customer_id, $card->program, $type, $quantity, $purchaseAmount, $source, $note, $receiptNumber);
    }

    /**
     * One receipt, one award: the receipt is checked once for the whole
     * purchase — however many cards its stamps spread over — and in the same
     * transaction that writes them, so the store lock it takes is still held
     * when the entries carrying that receipt land. A scan may carry no receipt
     * at all; there is then nothing to hold unique.
     */
    private function award(StampAllocator $allocator, int $customerId, StampProgram $program, string $type, int $quantity, $purchaseAmount, string $source, ?string $note, ?string $receiptNumber): StampAward
    {
        $storeId = CashierContext::storeId();
        $vendorId = CashierContext::vendor()->id;

        return DB::transaction(function () use ($allocator, $customerId, $program, $type, $quantity, $purchaseAmount, $source, $note, $receiptNumber, $storeId, $vendorId) {
            if ($receiptNumber !== null) {
                $this->assertReceiptUnusedAtStore($storeId, $receiptNumber);
            }

            return $allocator->award(
                $customerId,
                $program,
                $type,
                $quantity,
                $purchaseAmount,
                newCard: ['store_id' => $storeId, 'cashier_vendor_id' => $vendorId],
                touch: ['cashier_vendor_id' => $vendorId],
                entry: [
                    'store_id' => $storeId,
                    'source' => $source,
                    'receipt_number' => $receiptNumber,
                    'note' => $note,
                    'cashier_vendor_id' => $vendorId,
                ],
            );
        });
    }

    /**
     * A POS receipt earns stamps once per store. Scoped to the store rather
     * than global on purpose: every outlet runs its own receipt series, so two
     * branches legitimately issue the same number.
     *
     * Enforced here and not by a unique index: the hub owns `stamp_entries`,
     * and its own manual stamping records no receipt at all.
     */
    private function assertReceiptUnusedAtStore(int $storeId, string $receiptNumber): void
    {
        // Holding the store row serialises stamping at this store, so two
        // requests carrying the same receipt cannot both pass the check below.
        Store::query()->whereKey($storeId)->lockForUpdate()->first();

        // Case-folded so "or-1001" and "OR-1001" are one receipt on every
        // driver, the same way the voucher sale key treats them.
        $used = StampEntry::where('store_id', $storeId)
            ->whereRaw('UPPER(receipt_number) = ?', [mb_strtoupper($receiptNumber)])
            ->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'receipt_number' => "Receipt no. {$receiptNumber} has already been used for stamps at this store.",
            ]);
        }
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
            'stock_in_ids' => self::REQUIRE_UNIT_SCAN ? 'required|array|min:1|max:1000' : 'nullable|array|max:1000',
            'stock_in_ids.*' => 'required|integer|distinct|exists:stock_ins,id',
            'remarks' => 'nullable|string|max:255',
        ]);

        // Empty only when the scan step is off; the units are then assigned
        // inside the transaction.
        $stockInIds = collect($data['stock_in_ids'] ?? [])->map(fn ($id) => (int) $id)->values();
        if ($stockInIds->isNotEmpty() && $stockInIds->count() !== (int) $data['quantity']) {
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

            $quantity = $stockInIds->isEmpty() ? (int) $data['quantity'] : $stockInIds->count();

            if ($stockInIds->isNotEmpty()) {
                StockIn::query()->whereIn('id', $stockInIds)->lockForUpdate()->get();
            }

            $soh = (int) InventoryTransaction::query()
                ->validInventoryLedger('inventory_transactions', 'portal_redeem_valid')
                ->where('inventory_transactions.asset_id', $asset->id)
                ->whereIn('inventory_transactions.location', $variants)
                ->sum('inventory_transactions.quantity');

            if ($soh < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock at {$location}. Available: {$soh}.",
                ]);
            }

            $availableUnits = $this->redeemableUnitsAt($asset, $variants, $soh)->keyBy('id');

            if ($stockInIds->isEmpty()) {
                // No scan: release the next available coded units, then lock and
                // re-check them so two tills cannot be handed the same piece.
                $stockInIds = $availableUnits->keys()->take($quantity)->values();
                if ($stockInIds->count() < $quantity) {
                    throw ValidationException::withMessages([
                        'asset_id' => 'No barcoded/QR-coded unit of this item is available to release at this store.',
                    ]);
                }

                StockIn::query()->whereIn('id', $stockInIds)->lockForUpdate()->get();
                $availableUnits = $this->redeemableUnitsAt($asset, $variants, $soh)->keyBy('id');
            }

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
        // Pieces this store received as Damaged are not a reward to hand a
        // member. (One that never arrived is already off every shelf — see
        // fixedUnitsCurrentlyAt.) Keyed by the transfer that brought each piece
        // here, so a piece later moved on and received good elsewhere is not
        // held against it.
        $offShelf = StockReceiving::query()
            ->where('asset_id', $asset->id)
            ->whereIn('destination_location', $locationVariants)
            ->where('status', CampaignReceivingService::RECEIVED)
            ->whereNotNull('stock_transfer_id')
            ->where(fn ($q) => $q->where('received_quantity', '<', 1)->orWhere('condition', '<>', 'Good'))
            ->pluck('stock_transfer_id')
            ->flip();

        return $this->fixedUnitsCurrentlyAt($locationVariants, function ($query) use ($asset) {
            $query->where('stock_ins.asset_id', $asset->id)
                ->whereNotIn('stock_ins.id', StampRedemptionUnit::query()->select('stock_in_id'));
        })
            ->reject(fn (StockIn $unit) => $unit->sourceStockTransfers->contains(
                fn ($transfer) => in_array($transfer->status, ['For Posting', 'Posted'], true)
            ))
            ->reject(function (StockIn $unit) use ($offShelf) {
                $broughtBy = $unit->sourceStockTransfers->firstWhere('status', 'Received')?->id;

                return $broughtBy !== null && $offShelf->has($broughtBy);
            })
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
        // The same rule as the list, so every row the cashier can see is a row
        // they can open and stamp — and nothing outside it is reachable by id.
        abort_unless(
            StampCard::whereKey($card->id)->where($this->cardsOfStore(CashierContext::storeId()))->exists(),
            404,
        );
    }

    /**
     * The cards this store has a stake in: the ones it issued, and the ones it
     * has stamped.
     *
     * The issuing store alone is not the boundary. A scan reuses the member's
     * active card wherever it was first opened — a loyalty card follows the
     * customer, not the branch — so a card started at another branch and then
     * stamped here belongs on this till's list too. Filtering on
     * `stamp_cards.store_id` only made that sale vanish from the store that
     * had just taken it, while the member's app still showed it there.
     *
     * Returned as a constraint so the list, the counters and the scope guard
     * all apply one definition; always pass it to `where()`, which keeps the
     * OR inside its own group.
     */
    private function cardsOfStore(int $storeId): \Closure
    {
        return fn ($query) => $query
            ->where('stamp_cards.store_id', $storeId)
            ->orWhereHas('entries', fn ($entries) => $entries->where('stamp_entries.store_id', $storeId));
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
