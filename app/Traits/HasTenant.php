<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use App\Scopes\TenantScope;

trait HasTenant
{
    protected static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (is_null($model->tenant_id) && !isset($model->is_super_admin)) {
                $tenant = app('tenant');
                if ($tenant) {
                    $model->tenant_id = $tenant->id;
                }
            }
        });
    }

    public function scopeForTenant(Builder $query, $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function belongsToTenant($tenant): bool
    {
        return $this->tenant_id === $tenant->id;
    }
}
