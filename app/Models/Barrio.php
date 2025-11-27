<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Barrio extends Model
{
    use HasFactory;

    protected $fillable = [
        'municipality_id',
        'commune_id',
        'codigo',
        'nombre',
        'latitude',
        'longitude',
        'path',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    // Relationships
    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function commune()
    {
        return $this->belongsTo(Commune::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function contacts()
    {
        return $this->morphMany(GeographicContact::class, 'contactable');
    }

    // Helper: Obtiene el municipio (directo o a través de la comuna)
    public function getMunicipalityAttribute()
    {
        if ($this->municipality_id) {
            return $this->municipality;
        }
        
        if ($this->commune_id && $this->commune) {
            return $this->commune->municipality;
        }
        
        return null;
    }
}
