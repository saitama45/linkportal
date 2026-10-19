<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Http\Controllers\Controller;
use App\Models\StockReceiving;
use App\Services\CampaignReceivingService;
use App\Support\CashierContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campaigns › Receive — the store's side of the LINK HUB's Receiving Stock.
 *
 * As the destination, a cashier confirms the reward stock delivered to their
 * own store. As the source, they settle what they sent that never arrived:
 * take it back into stock, or write it off. Nothing addressed to, or sent
 * from, another store is reachable, by list or by id.
 */
class CampaignReceivingController extends Controller
{
    use LocatesInventoryUnits;

    public function __construct(private CampaignReceivingService $receiving) {}

    public function show(StockReceiving $receiving)
    {
        $this->assertAtThisStore($receiving);

        return response()->json($this->receiving->detail($receiving));
    }

    public function receive(Request $request, StockReceiving $receiving)
    {
        $this->assertAtThisStore($receiving);

        $data = $request->validate([
            'entries' => 'required|array|min:1|max:5000',
            'entries.*.id' => 'required|integer',
            'entries.*.received_quantity' => 'required|integer|min:0',
            'entries.*.condition' => ['required', 'string', Rule::in(array_keys(CampaignReceivingService::CONDITIONS))],
            'entries.*.damage_notes' => 'nullable|string|max:1000',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $totals = $this->receiving->receive($receiving, $data['entries'], $data['remarks'] ?? null, CashierContext::vendor()->name);

        $message = "Received {$totals['received']} unit(s) into ".CashierContext::store()->code.'.';
        if ($totals['damaged']) {
            $message .= " {$totals['damaged']} damaged.";
        }
        if ($totals['in_transit']) {
            $message .= " {$totals['in_transit']} unserved — left in transit until it arrives or {$receiving->origin_location} resolves it.";
        }

        return back()->with('success', $message);
    }

    /** The source settles lines it sent that the destination reported unserved. */
    public function resolveOutgoing(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['return', 'write_off'])],
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'required|integer',
            'reason' => 'required|string|max:2000',
        ]);

        $reason = trim($data['reason']);
        if ($reason === '') {
            return back()->withErrors(['reason' => 'Say why, so the trail explains it.']);
        }

        $store = CashierContext::store();
        $units = $this->receiving->resolveInTransit(
            $data['ids'],
            $data['action'],
            $reason,
            CashierContext::vendor()->name,
            $this->locationVariants($store->code),
        );

        return back()->with('success', $data['action'] === 'return'
            ? "Returned {$units} unit(s) to {$store->code} stock."
            : "Wrote off {$units} unit(s) as lost in transit. {$store->code} stays deducted.");
    }

    /** A receiving addressed to another store reads as not found, not forbidden. */
    private function assertAtThisStore(StockReceiving $receiving): void
    {
        abort_unless(
            in_array($receiving->destination_location, $this->locationVariants(CashierContext::store()->code), true),
            404,
        );
    }
}
