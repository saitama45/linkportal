<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundEmail extends Model
{
    protected $table = 'portal_inbound_emails';

    protected $fillable = [
        'message_id', 'from_email', 'from_name', 'subject', 'received_at',
        'matched_vendor_id', 'match_method', 'status', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'matched_vendor_id' => 'integer',
            'received_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function matchedVendor()
    {
        return $this->belongsTo(Vendor::class, 'matched_vendor_id');
    }

    public function intakeDocuments()
    {
        return $this->hasMany(IntakeDocument::class);
    }
}
