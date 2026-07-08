<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public function companies()
    {
        return $this->belongsToMany(Company::class, 'role_company', 'role_id', 'company_id');
    }
}
