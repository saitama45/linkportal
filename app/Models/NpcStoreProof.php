<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcStoreProof extends Model
{
    protected $fillable = [
        'npc_status_id',
        'store_id',
        'seal_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uploaded_by' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
