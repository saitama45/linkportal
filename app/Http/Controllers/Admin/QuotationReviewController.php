<?php

namespace App\Http\Controllers\Admin;

use App\Http\Services\ApprovalService;
use App\Http\Services\AuditLogger;
use App\Http\Services\NumberingService;
use App\Models\Quotation;
use Illuminate\Http\Request;

class QuotationReviewController extends BaseTransactionReviewController
{
    protected string $modelClass = Quotation::class;

    protected string $pageFolder = 'Quotations';

    protected string $propName = 'quotations';

    protected string $permissionModule = 'quotations';

    protected array $searchColumns = ['reference_no', 'quotation_no', 'title'];

    public function store(Request $request)
    {
        abort_unless($request->user()->can('quotations.create'), 403);

        $validated = $request->validate([
            'vendor_id' => 'required|exists:portal_vendors,id',
            'company_id' => 'required|exists:companies,id',
            'quotation_no' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'quotation_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quotation_date',
            'payment_terms' => 'nullable|string|max:100',
            'delivery_terms' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            ...$this->lineItemRules(),
        ]);

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $quotation = Quotation::create([
            'vendor_id' => $validated['vendor_id'],
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
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $quotation->items()->createMany($items);
        $this->storeAttachments($quotation, $request, $validated['vendor_id'], 'user', $request->user()->id);
        AuditLogger::log('quotation_created_by_user', $quotation);
        ApprovalService::submit($quotation, (float) $total);

        return redirect()->route('quotations.show', $quotation)
            ->with('success', 'Quotation created and submitted for approval.');
    }
}
