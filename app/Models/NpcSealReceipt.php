<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcSealReceipt extends Model
{
    protected $fillable = [
        'npc_status_id',
        'store_id',
        'seal_type',
        'downloaded_at',
        'downloaded_by',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'npc_status_id' => 'integer',
        'store_id' => 'integer',
        'downloaded_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'downloaded_by' => 'integer',
        'confirmed_by' => 'integer',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function downloader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
