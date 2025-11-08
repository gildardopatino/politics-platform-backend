<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPropuesta extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'categoria',
        'titulo',
        'descripcion',
        'puntos_clave',
        'icono',
        'order',
        'is_active',
    ];

    protected $casts = [
        'puntos_clave' => 'array',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Get the tenant that owns the propuesta.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
