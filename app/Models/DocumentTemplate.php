<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $table = 'portal_document_templates';

    protected $fillable = [
        'vendor_id', 'company_id', 'document_type', 'name', 'description',
        'status', 'active_version_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'company_id' => 'integer',
            'active_version_id' => 'integer',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function versions()
    {
        return $this->hasMany(DocumentTemplateVersion::class, 'template_id')->orderByDesc('version_no');
    }

    public function activeVersion()
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'active_version_id');
    }

    public function isGlobal(): bool
    {
        return $this->vendor_id === null;
    }

    /**
     * Active template for a vendor + type, falling back to the global template.
     */
    public static function resolveFor(?int $vendorId, string $documentType): ?self
    {
        if ($vendorId) {
            $vendorTemplate = static::where('vendor_id', $vendorId)
                ->where('document_type', $documentType)
                ->where('status', 'active')
                ->whereNotNull('active_version_id')
                ->first();
            if ($vendorTemplate) {
                return $vendorTemplate;
            }
        }

        return static::whereNull('vendor_id')
            ->where('document_type', $documentType)
            ->where('status', 'active')
            ->whereNotNull('active_version_id')
            ->first();
    }
}
