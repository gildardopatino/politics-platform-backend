<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vereda extends Model
{
    use HasFactory;

    protected $table = 'veredas';

    protected $fillable = [
        'municipality_id',
        'corregimiento_id',
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

    public function corregimiento()
    {
        return $this->belongsTo(Corregimiento::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }
}
