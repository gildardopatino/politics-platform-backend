<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oficio del catálogo global (Spec 0094).
 *
 * Sin `HasTenant` a propósito: es el mismo catálogo para todas las campañas
 * —como `voting_places`— y ninguna lo edita desde el formulario del votante.
 */
class Occupation extends Model
{
    use HasFactory;

    protected $fillable = ['nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function aliases(): HasMany
    {
        return $this->hasMany(OccupationAlias::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
