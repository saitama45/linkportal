<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Services\CampaignVoucherService;
use App\Support\CashierContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The two voucher actions a till performs: check a code, then apply it as
 * payment. Everything else about a voucher batch — creating it, activating,
 * printing, suspending, voiding, exporting — stays in the LINK HUB.
 */
class CampaignVoucherController extends Controller
{
    public function verify(Request $request, CampaignVoucherService $service)
    {
        $data = $request->validate(['code' => 'required|string|max:255']);

        return response()->json($service->verify(
            $data['code'],
            CashierContext::vendor()->id,
            CashierContext::storeId(),
            CashierContext::companyId(),
        ));
    }

    public function redeem(Request $request, CampaignVoucherService $service)
    {
        $companyId = CashierContext::companyId();
        abort_unless($companyId, 422, 'This store is not assigned to an entity.');

        $data = $request->validate([
            'code' => 'required|string|max:255',
            'customer_id' => 'nullable|required_without:new_customer_name|exists:customers,id',
            'new_customer_name' => 'nullable|required_without:customer_id|string|max:255',
            'new_customer_phone' => 'nullable|required_without:customer_id|string|max:50',
            'new_customer_email' => 'nullable|email|max:255',
            'receipt_number' => 'required|string|max:100',
            'sale_date' => 'required|date|before_or_equal:today',
            'gross_sale_total' => 'required|numeric|min:0.01',
        ]);

        // The store is the cashier's own, never the request's — otherwise a
        // crafted payload could book a sale against another branch.
        $data['store_id'] = CashierContext::storeId();

        $redemption = $service->redeem($data, CashierContext::vendor()->id, $companyId);

        return response()->json([
            'message' => 'Voucher applied as payment.',
            'redemption' => $redemption,
        ]);
    }
}
