<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentExtraction extends Model
{
    protected $table = 'portal_document_extractions';

    protected $fillable = [
        'intake_document_id', 'template_version_id', 'attempt_no', 'engine_used',
        'header_fields', 'line_items', 'totals_check', 'overall_confidence',
        'status', 'error_message', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'intake_document_id' => 'integer',
            'template_version_id' => 'integer',
            'attempt_no' => 'integer',
            'engine_used' => 'array',
            'header_fields' => 'array',
            'line_items' => 'array',
            'totals_check' => 'array',
            'overall_confidence' => 'decimal:4',
            'duration_ms' => 'integer',
        ];
    }

    public function intakeDocument()
    {
        return $this->belongsTo(IntakeDocument::class);
    }

    public function field(string $key): ?array
    {
        foreach ($this->header_fields ?? [] as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }
}
