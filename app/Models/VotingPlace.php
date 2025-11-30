<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VotingPlace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'departamento_votacion',
        'municipio_votacion',
        'puesto_votacion',
        'direccion_votacion',
        'latitud',
        'longitud',
    ];

    protected $casts = [
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
    ];

    /**
     * Relación con votantes
     */
    public function voters()
    {
        return $this->hasMany(Voter::class);
    }
}
