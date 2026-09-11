<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared NPC tables and migrations are owned by ghelpdesk. */
class NpcStatus extends Model
{
    protected $casts = [
        'year' => 'integer',
        'validity_from' => 'date:Y-m-d',
        'validity_to' => 'date:Y-m-d',
    ];

    protected $hidden = ['register_password'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class, 'npc_status_store')->withPivot('year')->withTimestamps();
    }

    public function attachments()
    {
        return $this->hasMany(NpcStatusAttachment::class);
    }

    public function sealReceipts()
    {
        return $this->hasMany(NpcSealReceipt::class);
    }

    public function storeProofs()
    {
        return $this->hasMany(NpcStoreProof::class);
    }
}
