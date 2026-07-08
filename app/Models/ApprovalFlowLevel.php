<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalFlowLevel extends Model
{
    protected $table = 'portal_approval_flow_levels';

    protected $fillable = [
        'approval_flow_id',
        'level',
        'name',
        'required_permission',
        'role_id',
        'sla_hours',
    ];

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
