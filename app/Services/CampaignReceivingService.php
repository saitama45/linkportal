<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The portal's half of the LINK HUB's Receiving Stock module
 * (/stock-receivings): a store confirms what actually arrived from a transfer
 * the hub posted, and only then is the reward stock on its shelf.
 *
 * The ledger effect is the hub's own: each received piece gets a 'Transfer In'
 * at the destination and its transfer is marked Received, so the two apps can
 * never disagree about stock on hand. What differs is how the counts get
 * here. The hub only credits pieces that were scanned; a till may scan, count
 * by hand, or both, and says per line whether it arrived good, damaged, or
 * not at all.
 *
 * "Not at all" is not the end of the line. An unserved piece is still in
 * transit — the source has already been deducted — so it stays open until it
 * either turns up and is received, or the SOURCE resolves it: returned to its
 * stock (it was never shipped), or written off (it is lost). The hub runs the
 * same rules; both apps write the same ledger rows:
 *
 *   Receiving Unserved    0  at the destination, when a line is reported unserved
 *   Receiving Declined   +n  at the origin, when the source takes it back
 *   Unserved Written Off  0  at the origin, when the source writes it off
 */
class CampaignReceivingService
{
    /**
     * Condition as the counter says it => as the hub stores it. The hub owns
     * the column and accepts only Good / Damaged / Missing on its own edit
     * screen, so "Unserved" is written as Missing rather than as a fourth
     * value the hub would refuse.
     */
    public const CONDITIONS = ['Good' => 'Good', 'Damaged' => 'Damaged', 'Unserved' => 'Missing'];

    public const PENDING = 'For Receiving';

    public const RECEIVED = 'Received';

    public const DECLINED = 'Declined';

    public const UNSERVED_ENTRY = 'Receiving Unserved';

    public const WRITTEN_OFF_ENTRY = 'Unserved Written Off';

    /**
     * One row per receiving at this store. The hub has no header table: a
     * receiving is the set of piece rows that share its number, date, route and
     * status, so the list is that grouping — the same one the hub's index uses.
     * Once some of a receiving is posted and some is left in transit, the two
     * halves are two rows here, exactly as in the hub.
     */
    public function receivingsAt(array $locationVariants): Collection
    {
        if (empty($locationVariants)) {
            return collect();
        }

        $condition = DB::connection()->getQueryGrammar()->wrap('condition');

        return StockReceiving::query()
            ->whereIn('destination_location', $locationVariants)
            ->select('receiving_no', 'receiving_date', 'origin_location', 'destination_location', 'status', 'received_by', 'received_at')
            ->selectRaw('SUM(transferred_quantity) as transferred_quantity')
            ->selectRaw('SUM(received_quantity) as received_quantity')
            ->selectRaw("SUM(CASE WHEN {$condition} = 'Damaged' THEN received_quantity ELSE 0 END) as damaged_quantity")
            ->selectRaw("SUM(CASE WHEN status = 'For Receiving' AND {$condition} = 'Missing' AND received_quantity = 0 THEN transferred_quantity ELSE 0 END) as in_transit_quantity")
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('COUNT(DISTINCT asset_id) as asset_count')
            ->selectRaw('MAX(id) as id')
            ->groupBy('receiving_no', 'receiving_date', 'origin_location', 'destination_location', 'status', 'received_by', 'received_at')
            // Whatever still waits on this store comes first; history after.
            ->orderByRaw("CASE WHEN status = 'For Receiving' THEN 0 ELSE 1 END")
            ->orderByDesc('receiving_date')
            ->orderByRaw('MAX(id) DESC')
            ->get()
            ->map(function (StockReceiving $group) {
                $group->damaged_quantity = (int) $group->damaged_quantity;
                $group->in_transit_quantity = (int) $group->in_transit_quantity;
                $group->record_count = (int) $group->record_count;
                $group->asset_count = (int) $group->asset_count;
                // Closed without arriving: written off by the source, or an
                // older receiving posted as zero.
                $group->written_off_quantity = $group->status === self::RECEIVED
                    ? max(0, $group->transferred_quantity - $group->received_quantity)
                    : 0;

                return $group;
            })
            ->values();
    }

    /** Totals for the stat row, taken from the same groups the table lists. */
    public function summarize(Collection $receivings): array
    {
        $pending = $receivings->where('status', self::PENDING);
        $received = $receivings->where('status', self::RECEIVED);
        // "To receive" is what still needs counting. A line already reported
        // unserved is waiting on the source, not on this store.
        $toCount = $pending->filter(fn ($group) => $group->transferred_quantity > $group->in_transit_quantity);

        return [
            'pending' => $toCount->count(),
            'units_pending' => (int) $toCount->sum(fn ($group) => $group->transferred_quantity - $group->in_transit_quantity),
            'units_received' => (int) $received->sum('received_quantity'),
            'units_damaged' => (int) $received->sum('damaged_quantity'),
            'units_in_transit' => (int) $pending->sum('in_transit_quantity'),
        ];
    }

    /** Every piece row of the receiving that `$anchor` belongs to. */
    public function rowsOf(StockReceiving $anchor): Builder
    {
        return StockReceiving::query()
            ->when(
                $anchor->receiving_no !== null,
                fn ($q) => $q->where('receiving_no', $anchor->receiving_no),
                fn ($q) => $q->whereNull('receiving_no'),
            )
            ->whereDate('receiving_date', $anchor->receiving_date)
            ->where('origin_location', $anchor->origin_location)
            ->where('destination_location', $anchor->destination_location)
            ->where('status', $anchor->status)
            ->orderBy('id');
    }

    /** What the Receive modal opens with. */
    public function detail(StockReceiving $anchor): array
    {
        $rows = $this->rowsOf($anchor)
            ->with(['asset:id,item_code,brand,model,description', 'pack:id,barcode,bulk_uom'])
            ->get();

        $first = $rows->first();

        return [
            'header' => [
                'id' => $anchor->id,
                'receiving_no' => $anchor->receiving_no,
                'receiving_date' => $anchor->receiving_date?->toDateString(),
                'origin_location' => $anchor->origin_location,
                'destination_location' => $anchor->destination_location,
                'status' => $anchor->status,
                'received_by' => $first?->received_by,
                'received_at' => $first?->received_at,
                'remarks' => $first?->remarks,
            ],
            'rows' => $rows->map(fn (StockReceiving $row) => [
                'id' => $row->id,
                'asset_id' => $row->asset_id,
                'asset' => $row->asset,
                'pack' => $row->pack,
                'serial_no' => $row->serial_no,
                'barcode' => $row->barcode,
                'qrcode' => $row->qrcode,
                'transferred_quantity' => $row->transferred_quantity,
                'received_quantity' => $row->received_quantity,
                'condition' => $this->conditionLabel($row->condition),
                'damage_notes' => $row->damage_notes,
                'in_transit' => $this->isInTransit($row),
            ])->values(),
        ];
    }

    /**
     * Post a receiving: record what arrived, credit it to this store, and close
     * those transfers — all or nothing. Lines marked Unserved are NOT closed:
     * they stay open as in transit, with a history entry, and their transfers
     * stay Posted, until they arrive or the source resolves them.
     *
     * Every line of the receiving must be accounted for. A line that is simply
     * missing from the request is refused rather than assumed: the counter has
     * to say "unserved" out loud.
     *
     * @param  array<int, array{id: int|string, received_quantity: int|string, condition: string, damage_notes?: ?string}>  $entries
     * @return array{received: int, damaged: int, in_transit: int}
     */
    public function receive(StockReceiving $anchor, array $entries, ?string $remarks, string $actorName): array
    {
        return DB::transaction(function () use ($anchor, $entries, $remarks, $actorName) {
            // Re-read under lock: the hub, or a second till, may have posted it
            // since this one was opened.
            $current = StockReceiving::query()->lockForUpdate()->find($anchor->id);
            if (! $current || $current->status !== self::PENDING) {
                throw ValidationException::withMessages(['entries' => 'This receiving has already been posted.']);
            }

            $rows = $this->rowsOf($current)->lockForUpdate()->get()->keyBy('id');
            $byId = collect($entries)->keyBy(fn ($entry) => (int) $entry['id']);

            if ($byId->count() !== count($entries)
                || $byId->count() !== $rows->count()
                || $byId->keys()->diff($rows->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'entries' => 'This receiving changed after it was opened. Close it, open it again and recount.',
                ]);
            }

            // Every line is checked before anything is written, so one bad line
            // refuses the whole receiving instead of half-posting it.
            $plan = [];
            foreach ($rows as $id => $row) {
                $entry = $byId->get($id);
                $condition = (string) $entry['condition'];
                $quantity = $condition === 'Unserved' ? 0 : (int) $entry['received_quantity'];
                $line = $row->serial_no ?: ($row->barcode ?: "Line {$id}");

                if ($quantity > $row->transferred_quantity) {
                    throw ValidationException::withMessages([
                        'entries' => "{$line}: {$quantity} received is more than the {$row->transferred_quantity} sent.",
                    ]);
                }
                // All or nothing per line. Stock is one row per piece, so this only
                // bites an older multi-unit line — and part-receiving one would
                // let the rest vanish with no trail, which is what Unserved is for.
                if ($condition !== 'Unserved' && $quantity < $row->transferred_quantity) {
                    throw ValidationException::withMessages([
                        'entries' => $quantity < 1
                            ? "{$line}: enter the quantity received, or mark it Unserved."
                            : "{$line}: receive all {$row->transferred_quantity}, or mark it Unserved — a line cannot be part-received.",
                    ]);
                }

                $notes = trim((string) ($entry['damage_notes'] ?? ''));
                $plan[$id] = [
                    'quantity' => $quantity,
                    'label' => $condition,
                    'notes' => $condition === 'Good' || $notes === '' ? null : $notes,
                ];
            }

            $now = now();
            $remarks = trim((string) $remarks) ?: null;
            $transferIds = [];
            $totals = ['received' => 0, 'damaged' => 0, 'in_transit' => 0];

            foreach ($rows as $id => $row) {
                $line = $plan[$id];

                if ($line['label'] === 'Unserved') {
                    $this->markInTransit($row, $line['notes'], $remarks, $actorName);
                    $totals['in_transit'] += $row->transferred_quantity;

                    continue;
                }

                // `updated_by` is a hub user id; a cashier has none, so it is left
                // as it was rather than pointed at an unrelated user. The cashier
                // is named in `received_by`, which the hub also keeps as text.
                $row->update([
                    'received_quantity' => $line['quantity'],
                    'condition' => self::CONDITIONS[$line['label']],
                    'damage_notes' => $line['notes'],
                    'remarks' => $remarks,
                    'status' => self::RECEIVED,
                    'received_by' => $actorName,
                    'received_at' => $now,
                ]);

                InventoryTransaction::create([
                    'asset_id' => $row->asset_id,
                    'reference_type' => StockReceiving::class,
                    'reference_id' => $row->id,
                    'location' => $row->destination_location,
                    'transaction_type' => 'Transfer In',
                    'quantity' => $line['quantity'],
                ]);

                if ($row->stock_transfer_id) {
                    $transferIds[] = $row->stock_transfer_id;
                }

                $totals['received'] += $line['quantity'];
                $totals['damaged'] += $line['label'] === 'Damaged' ? $line['quantity'] : 0;
            }

            $this->updateTransfers($transferIds, [
                'status' => self::RECEIVED,
                'received_by' => $actorName,
                'received_at' => $now,
            ]);

            return $totals;
        });
    }

    /**
     * Lines sent FROM this store that the destination reported unserved and
     * that are still waiting for the source to resolve them.
     */
    public function outgoingInTransit(array $originVariants): Collection
    {
        if (empty($originVariants)) {
            return collect();
        }

        return $this->inTransit(StockReceiving::query())
            ->whereIn('origin_location', $originVariants)
            ->with(['asset:id,item_code,brand,model,description'])
            ->orderByDesc('receiving_date')
            ->orderBy('receiving_no')
            ->orderBy('id')
            ->get()
            ->map(fn (StockReceiving $row) => [
                'id' => $row->id,
                'receiving_no' => $row->receiving_no,
                'receiving_date' => $row->receiving_date?->toDateString(),
                'origin_location' => $row->origin_location,
                'destination_location' => $row->destination_location,
                'asset' => $row->asset,
                'serial_no' => $row->serial_no,
                'barcode' => $row->barcode,
                'qrcode' => $row->qrcode,
                'transferred_quantity' => $row->transferred_quantity,
                'reported_by' => $row->received_by,
                'reason' => $row->damage_notes,
            ])
            ->values();
    }

    /**
     * The source settles an in-transit line, one of two ways:
     *
     *  - return: it never left (or came back) — credit it to the source again,
     *    exactly as the hub's Decline does, and the piece is back on the
     *    source's shelf;
     *  - write_off: it is lost — the source stays deducted, and the loss is
     *    recorded with its reason.
     *
     * @param  array<int, int|string>  $ids
     * @return int units resolved
     */
    public function resolveInTransit(array $ids, string $action, string $reason, string $actorName, array $originVariants): int
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();

        return DB::transaction(function () use ($ids, $action, $reason, $actorName, $originVariants) {
            $rows = StockReceiving::query()->whereIn('id', $ids)->lockForUpdate()->get();

            // Re-checked under lock: the destination may have received it, or
            // the hub resolved it, since the list was loaded.
            $resolvable = $rows->filter(fn (StockReceiving $row) => $this->isInTransit($row)
                && in_array($row->origin_location, $originVariants, true));

            if ($rows->count() !== $ids->count() || $resolvable->count() !== $rows->count()) {
                throw ValidationException::withMessages([
                    'ids' => 'Some of these lines are no longer waiting on this store. Refresh and try again.',
                ]);
            }

            $now = now();
            $units = 0;

            foreach ($resolvable as $row) {
                if ($action === 'return') {
                    InventoryTransaction::create([
                        'asset_id' => $row->asset_id,
                        'reference_type' => StockReceiving::class,
                        'reference_id' => $row->id,
                        'location' => $row->origin_location,
                        'transaction_type' => 'Receiving Declined',
                        'quantity' => $row->transferred_quantity,
                    ]);
                    $row->update([
                        'status' => self::DECLINED,
                        'received_quantity' => 0,
                        'remarks' => $reason,
                        'received_by' => $actorName,
                        'received_at' => $now,
                    ]);
                } else {
                    InventoryTransaction::create([
                        'asset_id' => $row->asset_id,
                        'reference_type' => StockReceiving::class,
                        'reference_id' => $row->id,
                        'location' => $row->origin_location,
                        'transaction_type' => self::WRITTEN_OFF_ENTRY,
                        'quantity' => 0,
                    ]);
                    $row->update([
                        'status' => self::RECEIVED,
                        'received_quantity' => 0,
                        'condition' => self::CONDITIONS['Unserved'],
                        'remarks' => $reason,
                        'received_by' => $actorName,
                        'received_at' => $now,
                    ]);
                }

                $units += $row->transferred_quantity;
            }

            $transferIds = $resolvable->pluck('stock_transfer_id')->filter()->all();
            $this->updateTransfers($transferIds, $action === 'return'
                // As the hub's Decline leaves it: nobody received this transfer.
                ? ['status' => self::DECLINED, 'received_by' => null, 'received_at' => null]
                : ['status' => self::RECEIVED, 'received_by' => $actorName, 'received_at' => $now]);

            return $units;
        });
    }

    public function conditionLabel(?string $stored): string
    {
        return array_search($stored, self::CONDITIONS, true) ?: 'Good';
    }

    /** Reported unserved and not yet resolved: the shared definition of "in transit". */
    public function isInTransit(StockReceiving $row): bool
    {
        return $row->status === self::PENDING
            && $row->condition === self::CONDITIONS['Unserved']
            && (int) $row->received_quantity === 0;
    }

    private function inTransit(Builder $query): Builder
    {
        return $query
            ->where('status', self::PENDING)
            ->where('condition', self::CONDITIONS['Unserved'])
            ->where('received_quantity', 0);
    }

    /**
     * Leave the line open — still For Receiving, its transfer still Posted, the
     * source still deducted — and put the report on the destination's trail.
     */
    private function markInTransit(StockReceiving $row, ?string $notes, ?string $remarks, string $actorName): void
    {
        $alreadyReported = $this->isInTransit($row);

        $row->update([
            'received_quantity' => 0,
            'condition' => self::CONDITIONS['Unserved'],
            'damage_notes' => $notes ?? ($alreadyReported ? $row->damage_notes : null),
            'remarks' => $remarks,
            'received_by' => $actorName,
            'received_at' => null,
        ]);

        // Once per line: posting the leftover again must not repeat the entry.
        $logged = InventoryTransaction::query()
            ->where('reference_type', StockReceiving::class)
            ->where('reference_id', $row->id)
            ->where('transaction_type', self::UNSERVED_ENTRY)
            ->exists();

        if (! $logged) {
            InventoryTransaction::create([
                'asset_id' => $row->asset_id,
                'reference_type' => StockReceiving::class,
                'reference_id' => $row->id,
                'location' => $row->destination_location,
                'transaction_type' => self::UNSERVED_ENTRY,
                'quantity' => 0,
            ]);
        }
    }

    /** Chunked: SQL Server refuses more than 2100 bound parameters. */
    private function updateTransfers(array $transferIds, array $values): void
    {
        foreach (array_chunk(array_values(array_unique($transferIds)), 1000) as $chunk) {
            StockTransfer::whereIn('id', $chunk)->update($values);
        }
    }
}
