<?php

namespace App\Http\Controllers\Admin;

use App\Http\Services\ApprovalService;
use App\Http\Services\AuditLogger;
use App\Http\Services\NumberingService;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class PurchaseOrderReviewController extends BaseTransactionReviewController
{
    protected string $modelClass = PurchaseOrder::class;

    protected string $pageFolder = 'PurchaseOrders';

    protected string $propName = 'purchaseOrders';

    protected string $permissionModule = 'purchase-orders';

    protected array $searchColumns = ['reference_no', 'po_number'];

    public function store(Request $request)
    {
        abort_unless($request->user()->can('purchase-orders.create'), 403);

        $validated = $request->validate([
            'vendor_id' => 'required|exists:portal_vendors,id',
            'company_id' => 'required|exists:companies,id',
            'po_number' => 'required|string|max:50',
            'po_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:po_date',
            'delivery_address' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            ...$this->lineItemRules(),
        ]);

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $purchaseOrder = PurchaseOrder::create([
            'vendor_id' => $validated['vendor_id'],
            'company_id' => $validated['company_id'],
            'reference_no' => NumberingService::next('purchase_order', $validated['company_id']),
            'po_number' => $validated['po_number'],
            'po_date' => $validated['po_date'],
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'status' => 'draft',
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $purchaseOrder->items()->createMany($items);
        $this->storeAttachments($purchaseOrder, $request, $validated['vendor_id'], 'user', $request->user()->id);
        AuditLogger::log('purchase_order_created_by_user', $purchaseOrder);
        ApprovalService::submit($purchaseOrder, (float) $total);

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order created and submitted for approval.');
    }
}
