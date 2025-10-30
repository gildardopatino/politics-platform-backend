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
            // Si el modelo ya tiene tenant_id, no hacer nada
            if (!is_null($model->tenant_id)) {
                return;
            }

            // No asignar tenant_id a super admins globales
            if (isset($model->is_super_admin) && $model->is_super_admin && is_null($model->tenant_id)) {
                return;
            }

            // Obtener tenant_id del usuario autenticado
            $user = request()->user();
            if ($user && $user->tenant_id) {
                $model->tenant_id = $user->tenant_id;
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
