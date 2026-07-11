<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApInvoiceStatus extends Model
{
    public const STATUSES = [
        'for_collection', 'processing', 'partially_paid', 'paid', 'on_hold', 'cancelled',
    ];

    protected $table = 'portal_ap_invoice_statuses';

    protected $fillable = [
        'vendor_id', 'intake_document_id', 'invoice_no', 'status', 'mode_of_payment',
        'invoice_amount', 'paid_amount', 'outstanding_amount', 'payment_reference_no',
        'paid_date', 'remarks', 'source', 'external_ref', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'intake_document_id' => 'integer',
            'invoice_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'paid_date' => 'date:Y-m-d',
            'last_synced_at' => 'datetime',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function intakeDocument()
    {
        return $this->belongsTo(IntakeDocument::class);
    }
}
