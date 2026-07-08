<?php

namespace App\Traits;

/**
 * Shared line-item validation and totals computation for vendor transaction
 * documents (invoices, purchase orders, quotations).
 */
trait HandlesLineItems
{
    protected function lineItemRules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:portal_products,id',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.uom_id' => 'nullable|exists:portal_units_of_measure,id',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * Returns [items-with-line_total, subtotal, tax_amount, total_amount].
     */
    protected function computeTotals(array $items): array
    {
        $subtotal = 0;
        $taxAmount = 0;
        $prepared = [];

        foreach (array_values($items) as $index => $item) {
            $qty = (float) $item['quantity'];
            $price = (float) $item['unit_price'];
            $taxRate = isset($item['tax_rate']) && $item['tax_rate'] !== null && $item['tax_rate'] !== ''
                ? (float) $item['tax_rate']
                : 0.0;

            $lineNet = round($qty * $price, 2);
            $lineTax = round($lineNet * $taxRate / 100, 2);

            $subtotal += $lineNet;
            $taxAmount += $lineTax;

            $prepared[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $qty,
                'uom_id' => $item['uom_id'] ?? null,
                'unit_price' => $price,
                'tax_rate' => $taxRate ?: null,
                'line_total' => $lineNet + $lineTax,
                'sort_order' => $index,
            ];
        }

        return [$prepared, round($subtotal, 2), round($taxAmount, 2), round($subtotal + $taxAmount, 2)];
    }

    protected function syncItems($document, array $preparedItems): void
    {
        $document->items()->delete();
        $document->items()->createMany($preparedItems);
    }

    protected function storeAttachments(
        $document,
        $request,
        int $vendorId,
        string $uploadedByType = 'vendor',
        ?int $uploadedById = null
    ): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("portal/vendors/{$vendorId}/attachments", 'public');

            $document->attachments()->create([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by_type' => $uploadedByType,
                'uploaded_by_id' => $uploadedById ?? $vendorId,
            ]);
        }
    }
}
