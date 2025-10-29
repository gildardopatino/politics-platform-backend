<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app('tenant');
        
        if ($tenant && $model->getTable() !== 'users') {
            $builder->where($model->getTable() . '.tenant_id', $tenant->id);
        }
    }
}
