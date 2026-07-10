<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentException extends Model
{
    protected $table = 'portal_document_exceptions';

    protected $fillable = [
        'intake_document_id', 'rule_key', 'severity', 'field_key', 'message',
        'context', 'status', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'intake_document_id' => 'integer',
            'context' => 'array',
            'resolved_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function intakeDocument()
    {
        return $this->belongsTo(IntakeDocument::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function isBlocker(): bool
    {
        return $this->severity === 'blocker';
    }
}
