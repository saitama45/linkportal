<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\ApprovalService;
use App\Http\Services\AuditLogger;
use App\Http\Services\FraudCheckService;
use App\Http\Services\NumberingService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Uom;
use App\Traits\HandlesLineItems;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    use HandlesLineItems;

    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        $query = Invoice::forVendor($vendor->id)->with('company:id,name')->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_no', 'like', "%{$request->search}%")
                    ->orWhere('invoice_no', 'like', "%{$request->search}%")
                    ->orWhere('po_number', 'like', "%{$request->search}%");
            });
        }

        return Inertia::render('Vendor/Invoices/Index', [
            'invoices' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Vendor/Invoices/Form', [
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
            'invoice_no' => 'required|string|max:50',
            'po_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'action' => 'required|in:draft,submit',
            ...$this->lineItemRules(),
        ]);

        if (FraudCheckService::isDuplicateInvoice($vendor->id, $validated['invoice_no'])) {
            return redirect()->back()->withErrors([
                'invoice_no' => 'An invoice with this number already exists. Duplicate submissions are not allowed.',
            ]);
        }

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $invoice = Invoice::create([
            'vendor_id' => $vendor->id,
            'company_id' => $validated['company_id'],
            'reference_no' => NumberingService::next('invoice', $validated['company_id']),
            'invoice_no' => $validated['invoice_no'],
            'po_number' => $validated['po_number'] ?? null,
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'status' => 'draft',
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $invoice->items()->createMany($items);
        $this->storeAttachments($invoice, $request, $vendor->id);
        AuditLogger::log('invoice_created', $invoice);

        if ($validated['action'] === 'submit') {
            ApprovalService::submit($invoice, (float) $total);
        }

        return redirect()->route('vendor.invoices.show', $invoice)
            ->with('success', $validated['action'] === 'submit' ? 'Invoice submitted for approval.' : 'Invoice saved as draft.');
    }

    public function show(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/Invoices/Show', [
            'invoice' => $invoice->load(['company:id,name', 'items.uom:id,code', 'attachments', 'approvals.user:id,name']),
        ]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        $vendor = $request->user('vendor');
        abort_unless($invoice->vendor_id === $vendor->id, 403);

        if (! $invoice->isEditable()) {
            return redirect()->back()->with('error', 'Only draft or returned invoices can be edited.');
        }

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'invoice_no' => 'required|string|max:50',
            'po_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'action' => 'required|in:draft,submit',
            ...$this->lineItemRules(),
        ]);

        if (FraudCheckService::isDuplicateInvoice($vendor->id, $validated['invoice_no'], $invoice->id)) {
            return redirect()->back()->withErrors([
                'invoice_no' => 'An invoice with this number already exists. Duplicate submissions are not allowed.',
            ]);
        }

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $invoice->update([
            'company_id' => $validated['company_id'],
            'invoice_no' => $validated['invoice_no'],
            'po_number' => $validated['po_number'] ?? null,
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $this->syncItems($invoice, $items);
        $this->storeAttachments($invoice, $request, $vendor->id);
        AuditLogger::log('invoice_updated', $invoice);

        if ($validated['action'] === 'submit') {
            ApprovalService::submit($invoice, (float) $total);
        }

        return redirect()->route('vendor.invoices.show', $invoice)
            ->with('success', $validated['action'] === 'submit' ? 'Invoice submitted for approval.' : 'Invoice updated.');
    }

    public function edit(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/Invoices/Form', [
            'invoice' => $invoice->load(['items', 'attachments']),
            'companies' => Company::where('is_active', true)->select('id', 'name')->orderBy('name')->get(),
            'products' => Product::active()->select('id', 'code', 'name', 'default_price', 'tax_rate', 'uom_id')->orderBy('name')->get(),
            'uoms' => Uom::active()->select('id', 'code', 'name')->get(),
        ]);
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->vendor_id === $request->user('vendor')->id, 403);

        if ($invoice->status !== 'draft') {
            return redirect()->back()->with('error', 'Only draft invoices can be deleted.');
        }

        AuditLogger::log('invoice_deleted', $invoice);
        $invoice->delete();

        return redirect()->route('vendor.invoices.index')->with('success', 'Invoice deleted.');
    }
}
