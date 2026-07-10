<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplateVersion extends Model
{
    protected $table = 'portal_document_template_versions';

    protected $fillable = [
        'template_id', 'version_no', 'annotations', 'sample_file_path',
        'page_meta', 'status', 'created_by', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'version_no' => 'integer',
            'annotations' => 'array',
            'page_meta' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
