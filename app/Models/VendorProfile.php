<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorProfile extends Model
{
    protected $table = 'portal_vendor_profiles';

    protected $fillable = [
        'vendor_id',
        'legal_name',
        'trade_name',
        'tin',
        'rdo_code',
        'business_type',
        'vat_type',
        'address',
        'city',
        'province',
        'zip_code',
        'country',
        'website',
        'payment_terms',
        'currency',
        'pending_changes',
        'approval_status',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'pending_changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
