<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcStatusAttachment extends Model
{
    public const TYPE_DPO_SEAL = 'dpo_seal';

    public const TYPE_DPO_REGISTRATION = 'dpo_registration';

    public const TYPE_CCTV_SEAL = 'cctv_seal';

    public const TYPES = [
        self::TYPE_DPO_SEAL,
        self::TYPE_DPO_REGISTRATION,
        self::TYPE_CCTV_SEAL,
    ];

    // Seals that assigned stores download & get confirmed on, per year.
    public const SEAL_TYPES = [
        self::TYPE_DPO_SEAL,
        self::TYPE_DPO_REGISTRATION,
        self::TYPE_CCTV_SEAL,
    ];

    public const TYPE_LABELS = [
        self::TYPE_DPO_SEAL => 'DPO Seal',
        self::TYPE_DPO_REGISTRATION => 'DPO Registration',
        self::TYPE_CCTV_SEAL => 'CCTV Seal',
    ];

    protected $fillable = [
        'npc_status_id',
        'store_id',
        'type',
        'validity_from',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $casts = [
        'validity_from' => 'date:Y-m-d',
        'store_id' => 'integer',
        'file_size' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
