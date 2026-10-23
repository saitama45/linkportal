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
        'holiday_stamps', 'max_cards_per_customer',
        'auto_stamp_amount', 'eligible_items_description', 'reward_description',
        'terms_and_conditions', 'starts_at', 'ends_at', 'display_order', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'stamps_required' => 'integer',
        'holiday_stamps' => 'integer',
        'max_cards_per_customer' => 'integer',
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

    /** Slots per card only a Holiday stamp may fill; never more than the card has. */
    public function holidaySlots(): int
    {
        return max(0, min((int) $this->holiday_stamps, (int) $this->stamps_required));
    }

    public function regularSlots(): int
    {
        return (int) $this->stamps_required - $this->holidaySlots();
    }

    /** Null when a member may hold any number of cards for this program. */
    public function cardLimit(): ?int
    {
        return $this->max_cards_per_customer ? (int) $this->max_cards_per_customer : null;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
