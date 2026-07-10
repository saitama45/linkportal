<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'portal_document_events';

    protected $fillable = [
        'intake_document_id', 'event', 'actor_type', 'actor_id', 'notes', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'intake_document_id' => 'integer',
            'actor_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function intakeDocument()
    {
        return $this->belongsTo(IntakeDocument::class);
    }
}
