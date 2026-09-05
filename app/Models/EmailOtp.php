<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    protected $table = 'portal_email_otps';

    protected $fillable = ['vendor_id', 'email', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < EmailOtp::MAX_ATTEMPTS;
    }

    public const MAX_ATTEMPTS = 5;

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
