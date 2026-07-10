<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorIntakeEmail extends Model
{
    protected $table = 'portal_vendor_intake_emails';

    protected $fillable = ['vendor_id', 'type', 'value', 'is_verified', 'created_by'];

    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'is_verified' => 'boolean',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
