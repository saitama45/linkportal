<?php

namespace App\Models;

use App\Traits\Approvable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use Approvable, SoftDeletes;

    protected $table = 'portal_invoices';

    protected $fillable = [
        'vendor_id',
        'company_id',
        'reference_no',
        'invoice_no',
        'po_number',
        'invoice_date',
        'due_date',
        'currency',
        'subtotal',
        'tax_amount',
        'withholding_tax',
        'total_amount',
        'status',
        'current_approval_level',
        'submitted_at',
        'remarks',
        'erp_ref',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
