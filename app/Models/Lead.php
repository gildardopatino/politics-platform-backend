<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'cedula',
        'nombre1',
        'nombre2',
        'apellido1',
        'apellido2',
        'fecha_nacimiento',
        'barrio_otro',
        'direccion',
        'telefono',
        'puesto_votacion',
        'departamento_votacion',
        'municipio_votacion',
        'zona_votacion',
        'locality_name',
        'direccion_votacion',
        'latitud',
        'longitud',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'latitud' => 'decimal:8',
        'longitud' => 'decimal:8',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeByCedula($query, $cedula)
    {
        return $query->where('cedula', $cedula);
    }

    public function scopeByTelefono($query, $telefono)
    {
        return $query->where('telefono', $telefono);
    }

    public function scopeByMunicipio($query, $municipio)
    {
        return $query->where('municipio_votacion', $municipio);
    }

    public function scopeSearchByName($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('nombre1', 'ILIKE', "%{$search}%")
              ->orWhere('nombre2', 'ILIKE', "%{$search}%")
              ->orWhere('apellido1', 'ILIKE', "%{$search}%")
              ->orWhere('apellido2', 'ILIKE', "%{$search}%");
        });
    }

    // Accessors
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->nombre1,
            $this->nombre2,
            $this->apellido1,
            $this->apellido2
        ]);
        
        return implode(' ', $parts);
    }

    public function getNombresAttribute(): string
    {
        $parts = array_filter([$this->nombre1, $this->nombre2]);
        return implode(' ', $parts);
    }

    public function getApellidosAttribute(): string
    {
        $parts = array_filter([$this->apellido1, $this->apellido2]);
        return implode(' ', $parts);
    }

    public function getHasLocationAttribute(): bool
    {
        return !is_null($this->latitud) && !is_null($this->longitud);
    }
}
