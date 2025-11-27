<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Corregimiento extends Model
{
    use HasFactory;

    protected $table = 'corregimientos';

    protected $fillable = [
        'municipality_id',
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

    public function veredas()
    {
        return $this->hasMany(Vereda::class);
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
