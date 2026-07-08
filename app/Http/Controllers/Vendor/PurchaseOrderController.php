<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\ApprovalService;
use App\Http\Services\AuditLogger;
use App\Http\Services\NumberingService;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Uom;
use App\Traits\HandlesLineItems;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    use HandlesLineItems;

    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        $query = PurchaseOrder::forVendor($vendor->id)->with('company:id,name')->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_no', 'like', "%{$request->search}%")
                    ->orWhere('po_number', 'like', "%{$request->search}%");
            });
        }

        return Inertia::render('Vendor/PurchaseOrders/Index', [
            'purchaseOrders' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Vendor/PurchaseOrders/Form', [
            'companies' => Company::where('is_active', true)->select('id', 'name')->orderBy('name')->get(),
            'products' => Product::active()->select('id', 'code', 'name', 'default_price', 'tax_rate', 'uom_id')->orderBy('name')->get(),
            'uoms' => Uom::active()->select('id', 'code', 'name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $vendor = $request->user('vendor');

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'po_number' => 'required|string|max:50',
            'po_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:po_date',
            'delivery_address' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'action' => 'required|in:draft,submit',
            ...$this->lineItemRules(),
        ]);

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $po = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
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
        ]);

        $po->items()->createMany($items);
        $this->storeAttachments($po, $request, $vendor->id);
        AuditLogger::log('purchase_order_created', $po);

        if ($validated['action'] === 'submit') {
            ApprovalService::submit($po, (float) $total);
        }

        return redirect()->route('vendor.purchase-orders.show', $po)
            ->with('success', $validated['action'] === 'submit' ? 'Purchase order submitted for approval.' : 'Purchase order saved as draft.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder->load(['company:id,name', 'items.uom:id,code', 'attachments', 'approvals.user:id,name']),
        ]);
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/PurchaseOrders/Form', [
            'purchaseOrder' => $purchaseOrder->load(['items', 'attachments']),
            'companies' => Company::where('is_active', true)->select('id', 'name')->orderBy('name')->get(),
            'products' => Product::active()->select('id', 'code', 'name', 'default_price', 'tax_rate', 'uom_id')->orderBy('name')->get(),
            'uoms' => Uom::active()->select('id', 'code', 'name')->get(),
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $vendor = $request->user('vendor');
        abort_unless($purchaseOrder->vendor_id === $vendor->id, 403);

        if (! $purchaseOrder->isEditable()) {
            return redirect()->back()->with('error', 'Only draft or returned purchase orders can be edited.');
        }

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'po_number' => 'required|string|max:50',
            'po_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:po_date',
            'delivery_address' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'action' => 'required|in:draft,submit',
            ...$this->lineItemRules(),
        ]);

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $purchaseOrder->update([
            'company_id' => $validated['company_id'],
            'po_number' => $validated['po_number'],
            'po_date' => $validated['po_date'],
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $this->syncItems($purchaseOrder, $items);
        $this->storeAttachments($purchaseOrder, $request, $vendor->id);
        AuditLogger::log('purchase_order_updated', $purchaseOrder);

        if ($validated['action'] === 'submit') {
            ApprovalService::submit($purchaseOrder, (float) $total);
        }

        return redirect()->route('vendor.purchase-orders.show', $purchaseOrder)
            ->with('success', $validated['action'] === 'submit' ? 'Purchase order submitted for approval.' : 'Purchase order updated.');
    }

    /**
     * Vendor acknowledges (confirms) an approved PO.
     */
    public function acknowledge(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->vendor_id === $request->user('vendor')->id, 403);

        $validated = $request->validate([
            'acknowledgment_status' => 'required|in:acknowledged,declined',
            'acknowledgment_remarks' => 'nullable|string|max:1000',
        ]);

        $purchaseOrder->update([
            'acknowledgment_status' => $validated['acknowledgment_status'],
            'acknowledged_at' => now(),
            'acknowledgment_remarks' => $validated['acknowledgment_remarks'] ?? null,
        ]);

        AuditLogger::log('purchase_order_'.$validated['acknowledgment_status'], $purchaseOrder);

        return redirect()->back()->with('success', 'Purchase order '.$validated['acknowledgment_status'].'.');
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->vendor_id === $request->user('vendor')->id, 403);

        if ($purchaseOrder->status !== 'draft') {
            return redirect()->back()->with('error', 'Only draft purchase orders can be deleted.');
        }

        AuditLogger::log('purchase_order_deleted', $purchaseOrder);
        $purchaseOrder->delete();

        return redirect()->route('vendor.purchase-orders.index')->with('success', 'Purchase order deleted.');
    }
}
