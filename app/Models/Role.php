<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'tenant_id',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Apply tenant scope
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Relationship with Tenant
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
