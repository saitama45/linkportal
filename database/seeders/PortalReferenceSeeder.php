<?php

namespace Database\Seeders;

use App\Models\ReferenceOption;
use App\Models\Uom;
use Illuminate\Database\Seeder;

class PortalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // --- Accreditation / compliance document types (PH context) ---
        $documentTypes = [
            'BIR Certificate of Registration (Form 2303)',
            'DTI / SEC Registration',
            'Mayor\'s / Business Permit',
            'Tax Clearance Certificate',
            'Audited Financial Statements',
            'Company Profile',
            'Bank Certification',
            'Insurance Certificate',
            'ISO / Quality Certification',
            'Accreditation Form',
            'Other Supporting Document',
        ];

        foreach ($documentTypes as $i => $label) {
            ReferenceOption::firstOrCreate(
                ['type' => 'document_type', 'value' => str_replace(' ', '_', strtolower(preg_replace('/[^A-Za-z0-9 ]/', '', $label)))],
                ['label' => $label, 'sort_order' => $i + 1, 'is_active' => true]
            );
        }

        // --- Payment terms ---
        foreach (['COD' => 'Cash on Delivery', 'NET7' => 'Net 7 days', 'NET15' => 'Net 15 days', 'NET30' => 'Net 30 days', 'NET60' => 'Net 60 days', 'NET90' => 'Net 90 days'] as $value => $label) {
            ReferenceOption::firstOrCreate(
                ['type' => 'payment_terms', 'value' => $value],
                ['label' => $label, 'is_active' => true]
            );
        }

        // --- Currencies ---
        foreach (['PHP' => 'Philippine Peso', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'JPY' => 'Japanese Yen', 'SGD' => 'Singapore Dollar'] as $value => $label) {
            ReferenceOption::firstOrCreate(
                ['type' => 'currency', 'value' => $value],
                ['label' => $label, 'is_active' => true]
            );
        }

        // --- Vendor types ---
        foreach (['supplier' => 'Supplier', 'service_provider' => 'Service Provider', 'contractor' => 'Contractor', 'consultant' => 'Consultant', 'logistics' => 'Logistics / Forwarder'] as $value => $label) {
            ReferenceOption::firstOrCreate(
                ['type' => 'vendor_type', 'value' => $value],
                ['label' => $label, 'is_active' => true]
            );
        }

        // --- Units of measure ---
        $uoms = [
            'pcs' => 'Pieces', 'box' => 'Box', 'set' => 'Set', 'unit' => 'Unit', 'lot' => 'Lot',
            'kg' => 'Kilogram', 'g' => 'Gram', 'l' => 'Liter', 'ml' => 'Milliliter',
            'm' => 'Meter', 'sqm' => 'Square Meter', 'hr' => 'Hour', 'day' => 'Day', 'month' => 'Month',
        ];

        foreach ($uoms as $code => $name) {
            Uom::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        $this->command?->info('Portal reference data seeded.');
    }
}
