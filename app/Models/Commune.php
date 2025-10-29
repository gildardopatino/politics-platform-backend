<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commune extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_id',
        'codigo',
        'nombre',
        'latitud',
        'longitud',
    ];

    protected $casts = [
        'latitud' => 'decimal:8',
        'longitud' => 'decimal:8',
    ];

    // Relationships
    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function barrios()
    {
        return $this->hasMany(Barrio::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }
}
