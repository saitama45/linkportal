<?php

namespace App\Models;

use App\Traits\Approvable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use Approvable, SoftDeletes;

    protected $table = 'portal_quotations';

    protected $fillable = [
        'vendor_id',
        'company_id',
        'reference_no',
        'quotation_no',
        'title',
        'quotation_date',
        'valid_until',
        'currency',
        'subtotal',
        'tax_amount',
        'total_amount',
        'payment_terms',
        'delivery_terms',
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
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
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
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
