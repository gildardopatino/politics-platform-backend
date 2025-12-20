<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TipoVotante extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tipo_votante';

    protected $fillable = [
        'descripcion',
    ];

    /**
     * Relación con Voters
     */
    public function voters(): HasMany
    {
        return $this->hasMany(Voter::class, 'tipo_votante_id');
    }
}
