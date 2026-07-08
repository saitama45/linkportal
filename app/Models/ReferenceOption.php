<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceOption extends Model
{
    protected $table = 'portal_reference_options';

    protected $fillable = [
        'type',
        'value',
        'label',
        'meta',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type)->where('is_active', true)->orderBy('sort_order');
    }
}
