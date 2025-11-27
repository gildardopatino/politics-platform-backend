<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class GeographicContact extends Model implements Auditable
{
    use HasFactory, SoftDeletes;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'contactable_type',
        'contactable_id',
        'identificacion',
        'nombres',
        'apellidos',
        'telefono',
        'direccion',
    ];

    protected $appends = [
        'nombre_completo',
    ];

    /**
     * Boot method
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Relación polimórfica a la entidad geográfica
     */
    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relación con el tenant
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Accessor para nombre completo
     */
    public function getNombreCompletoAttribute(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }

    /**
     * Scope para buscar por identificación
     */
    public function scopeByIdentificacion($query, $identificacion)
    {
        return $query->where('identificacion', $identificacion);
    }

    /**
     * Scope para buscar por tipo de entidad
     */
    public function scopeByContactableType($query, $type)
    {
        return $query->where('contactable_type', $type);
    }
}
