<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorBankAccount extends Model
{
    use SoftDeletes;

    protected $table = 'portal_vendor_bank_accounts';

    protected $fillable = [
        'vendor_id',
        'bank_name',
        'branch',
        'account_name',
        'account_number',
        'currency',
        'is_default',
        'approval_status',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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
