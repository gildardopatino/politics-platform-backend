<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
    ];

    /**
     * Permissions are global (not tenant-scoped)
     * They are defined at system level
     */
}
