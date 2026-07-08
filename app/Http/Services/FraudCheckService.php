<?php

namespace App\Http\Services;

use App\Models\Invoice;

class FraudCheckService
{
    /**
     * Duplicate-invoice guard: same vendor + same vendor invoice number on any
     * live (not rejected/cancelled) invoice.
     */
    public static function isDuplicateInvoice(int $vendorId, string $invoiceNo, ?int $excludeId = null): bool
    {
        return Invoice::where('vendor_id', $vendorId)
            ->where('invoice_no', $invoiceNo)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }
}
