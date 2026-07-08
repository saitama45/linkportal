<?php

namespace App\Http\Controllers\Admin;

use App\Http\Services\ApprovalService;
use App\Http\Services\AuditLogger;
use App\Http\Services\FraudCheckService;
use App\Http\Services\NumberingService;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceReviewController extends BaseTransactionReviewController
{
    protected string $modelClass = Invoice::class;

    protected string $pageFolder = 'Invoices';

    protected string $propName = 'invoices';

    protected string $permissionModule = 'invoices';

    protected array $searchColumns = ['reference_no', 'invoice_no', 'po_number'];

    public function store(Request $request)
    {
        abort_unless($request->user()->can('invoices.create'), 403);

        $validated = $request->validate([
            'vendor_id' => 'required|exists:portal_vendors,id',
            'company_id' => 'required|exists:companies,id',
            'invoice_no' => 'required|string|max:50',
            'po_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'remarks' => 'nullable|string|max:2000',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            ...$this->lineItemRules(),
        ]);

        if (FraudCheckService::isDuplicateInvoice($validated['vendor_id'], $validated['invoice_no'])) {
            return redirect()->back()->withErrors([
                'invoice_no' => 'An invoice with this number already exists for the selected vendor.',
            ]);
        }

        [$items, $subtotal, $tax, $total] = $this->computeTotals($validated['items']);

        $invoice = Invoice::create([
            'vendor_id' => $validated['vendor_id'],
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
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $invoice->items()->createMany($items);
        $this->storeAttachments($invoice, $request, $validated['vendor_id'], 'user', $request->user()->id);
        AuditLogger::log('invoice_created_by_user', $invoice);
        ApprovalService::submit($invoice, (float) $total);

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice created and submitted for approval.');
    }
}
