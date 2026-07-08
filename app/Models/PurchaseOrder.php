<?php

namespace App\Models;

use App\Traits\Approvable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use Approvable, SoftDeletes;

    protected $table = 'portal_purchase_orders';

    protected $fillable = [
        'vendor_id',
        'company_id',
        'reference_no',
        'po_number',
        'po_date',
        'expected_delivery_date',
        'delivery_address',
        'currency',
        'subtotal',
        'tax_amount',
        'total_amount',
        'status',
        'current_approval_level',
        'submitted_at',
        'acknowledgment_status',
        'acknowledged_at',
        'acknowledgment_remarks',
        'remarks',
        'erp_ref',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
