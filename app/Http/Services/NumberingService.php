<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\DB;

class NumberingService
{
    public const PREFIXES = [
        'invoice' => 'INV',
        'purchase_order' => 'PO',
        'quotation' => 'QTN',
        'vendor' => 'VND',
    ];

    /**
     * Generate the next reference number for a document type, e.g. INV-2026-00001.
     * Atomic per company/type/year via a transaction + row lock.
     */
    public static function next(string $documentType, ?int $companyId = null): string
    {
        $prefix = self::PREFIXES[$documentType] ?? strtoupper(substr($documentType, 0, 3));
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($documentType, $companyId, $prefix, $year) {
            $sequence = DB::table('portal_number_sequences')
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                DB::table('portal_number_sequences')->insert([
                    'company_id' => $companyId,
                    'document_type' => $documentType,
                    'year' => $year,
                    'prefix' => $prefix,
                    'next_number' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $number = 1;
            } else {
                $number = (int) $sequence->next_number;
                DB::table('portal_number_sequences')
                    ->where('id', $sequence->id)
                    ->update(['next_number' => $number + 1, 'updated_at' => now()]);
            }

            return sprintf('%s-%d-%05d', $prefix, $year, $number);
        });
    }
}
