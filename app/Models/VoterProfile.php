<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoterProfile extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'voter_id',
        'busca_empleo',
        'disponibilidad',
        'nivel_educativo',
        'anios_experiencia',
        'notas',
        'autoriza_tratamiento_datos',
        'autorizado_at',
        'created_by',
    ];

    protected $casts = [
        'busca_empleo' => 'boolean',
        'autoriza_tratamiento_datos' => 'boolean',
        'autorizado_at' => 'datetime',
        'anios_experiencia' => 'integer',
    ];

    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Las hojas de vida del elector, colgadas del perfil como los oficios: por
     * `voter_id`, para que la bolsa pueda contarlas sin una consulta por fila.
     */
    public function resumes(): HasMany
    {
        return $this->hasMany(VoterResume::class, 'voter_id', 'voter_id');
    }

    /**
     * Los oficios del votante, colgados del perfil para poder cargarlos con él.
     *
     * El pivote vive sobre `voter_id` y no sobre `voter_profiles.id`: el vínculo
     * votante↔oficio es del votante, y así sobrevive a que el perfil se rehaga.
     */
    public function occupations(): BelongsToMany
    {
        return $this->belongsToMany(
            Occupation::class,
            'voter_occupations',
            'voter_id',
            'occupation_id',
            'voter_id',
            'id'
        )->withPivot('relacion');
    }
}
