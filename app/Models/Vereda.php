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

    public function corregimiento()
    {
        return $this->belongsTo(Corregimiento::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }
}
