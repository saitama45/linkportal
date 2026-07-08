<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'portal_products';

    public const TYPES = ['asset', 'good', 'service', 'raw_material', 'consumable'];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'description',
        'product_type',
        'category_id',
        'uom_id',
        'default_price',
        'currency',
        'tax_rate',
        'attributes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'default_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
