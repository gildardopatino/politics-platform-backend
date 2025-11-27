<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commune extends Model
{
    use HasFactory;

    protected $fillable = [
        'municipality_id',
        'codigo',
        'nombre',
        'latitude',
        'longitude',
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

    public function barrios()
    {
        return $this->hasMany(Barrio::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function contacts()
    {
        return $this->morphMany(GeographicContact::class, 'contactable');
    }
}
