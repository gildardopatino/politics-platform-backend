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
        'latitud',
        'longitud',
    ];

    protected $casts = [
        'latitud' => 'decimal:8',
        'longitud' => 'decimal:8',
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
}
