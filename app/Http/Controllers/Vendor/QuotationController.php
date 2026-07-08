<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\ApprovalService;
use App\Http\Services\AuditLogger;
use App\Http\Services\NumberingService;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Uom;
use App\Traits\HandlesLineItems;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QuotationController extends Controller
{
    use HandlesLineItems;

    public function index(Request $request)
    {
        $vendor = $request->user('vendor');

        $query = Quotation::forVendor($vendor->id)->with('company:id,name')->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_no', 'like', "%{$request->search}%")
                    ->orWhere('quotation_no', 'like', "%{$request->search}%")
                    ->orWhere('title', 'like', "%{$request->search}%");
            });
        }

        return Inertia::render('Vendor/Quotations/Index', [
            'quotations' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Vendor/Quotations/Form', [
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
            'quotation_no' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'quotation_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quotation_date',
            'payment_terms' => 'nullable|string|max:100',
            'delivery_terms' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'action' => 'required|in:draft,submit',
            ...$this->lineItemRules(),
        ]);

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $quotation = Quotation::create([
            'vendor_id' => $vendor->id,
            'company_id' => $validated['company_id'],
            'reference_no' => NumberingService::next('quotation', $validated['company_id']),
            'quotation_no' => $validated['quotation_no'] ?? null,
            'title' => $validated['title'],
            'quotation_date' => $validated['quotation_date'],
            'valid_until' => $validated['valid_until'] ?? null,
            'payment_terms' => $validated['payment_terms'] ?? null,
            'delivery_terms' => $validated['delivery_terms'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'status' => 'draft',
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $quotation->items()->createMany($items);
        $this->storeAttachments($quotation, $request, $vendor->id);
        AuditLogger::log('quotation_created', $quotation);

        if ($validated['action'] === 'submit') {
            ApprovalService::submit($quotation, (float) $total);
        }

        return redirect()->route('vendor.quotations.show', $quotation)
            ->with('success', $validated['action'] === 'submit' ? 'Quotation submitted for approval.' : 'Quotation saved as draft.');
    }

    public function show(Request $request, Quotation $quotation)
    {
        abort_unless($quotation->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/Quotations/Show', [
            'quotation' => $quotation->load(['company:id,name', 'items.uom:id,code', 'attachments', 'approvals.user:id,name']),
        ]);
    }

    public function edit(Request $request, Quotation $quotation)
    {
        abort_unless($quotation->vendor_id === $request->user('vendor')->id, 403);

        return Inertia::render('Vendor/Quotations/Form', [
            'quotation' => $quotation->load(['items', 'attachments']),
            'companies' => Company::where('is_active', true)->select('id', 'name')->orderBy('name')->get(),
            'products' => Product::active()->select('id', 'code', 'name', 'default_price', 'tax_rate', 'uom_id')->orderBy('name')->get(),
            'uoms' => Uom::active()->select('id', 'code', 'name')->get(),
        ]);
    }

    public function update(Request $request, Quotation $quotation)
    {
        $vendor = $request->user('vendor');
        abort_unless($quotation->vendor_id === $vendor->id, 403);

        if (! $quotation->isEditable()) {
            return redirect()->back()->with('error', 'Only draft or returned quotations can be edited.');
        }

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'quotation_no' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'quotation_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quotation_date',
            'payment_terms' => 'nullable|string|max:100',
            'delivery_terms' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'action' => 'required|in:draft,submit',
            ...$this->lineItemRules(),
        ]);

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $quotation->update([
            'company_id' => $validated['company_id'],
            'quotation_no' => $validated['quotation_no'] ?? null,
            'title' => $validated['title'],
            'quotation_date' => $validated['quotation_date'],
            'valid_until' => $validated['valid_until'] ?? null,
            'payment_terms' => $validated['payment_terms'] ?? null,
            'delivery_terms' => $validated['delivery_terms'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $this->syncItems($quotation, $items);
        $this->storeAttachments($quotation, $request, $vendor->id);
        AuditLogger::log('quotation_updated', $quotation);

        if ($validated['action'] === 'submit') {
            ApprovalService::submit($quotation, (float) $total);
        }

        return redirect()->route('vendor.quotations.show', $quotation)
            ->with('success', $validated['action'] === 'submit' ? 'Quotation submitted for approval.' : 'Quotation updated.');
    }

    public function destroy(Request $request, Quotation $quotation)
    {
        abort_unless($quotation->vendor_id === $request->user('vendor')->id, 403);

        if ($quotation->status !== 'draft') {
            return redirect()->back()->with('error', 'Only draft quotations can be deleted.');
        }

        AuditLogger::log('quotation_deleted', $quotation);
        $quotation->delete();

        return redirect()->route('vendor.quotations.index')->with('success', 'Quotation deleted.');
    }
}
