<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalFlow extends Model
{
    protected $table = 'portal_approval_flows';

    protected $fillable = [
        'document_type',
        'company_id',
        'min_amount',
        'max_amount',
        'total_levels',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function levels()
    {
        return $this->hasMany(ApprovalFlowLevel::class, 'approval_flow_id')->orderBy('level');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
