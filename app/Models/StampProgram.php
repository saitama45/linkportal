<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class StampProgram extends Model
{
    protected $fillable = [
        'company_id', 'name', 'year', 'description', 'emoji', 'tag', 'stamps_required',
        'auto_stamp_amount', 'eligible_items_description', 'reward_description',
        'terms_and_conditions', 'starts_at', 'ends_at', 'display_order', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'stamps_required' => 'integer',
        'auto_stamp_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function stampCards()
    {
        return $this->hasMany(StampCard::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
