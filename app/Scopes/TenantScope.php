<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Check if there's a current tenant context
        if (!app()->bound('current_tenant_id')) {
            return;
        }

        $tenantId = app('current_tenant_id');
        
        // If tenant_id is null, it means super admin - no filtering
        if ($tenantId === null) {
            return;
        }

        // Apply tenant filter
        $builder->where($model->getTable() . '.tenant_id', $tenantId);
    }
}
