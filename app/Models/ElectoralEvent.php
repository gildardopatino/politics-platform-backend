<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * La elección a la que pertenecen unas actas (Spec 0061).
 *
 * Existe para agrupar, nada más: sin ella, las actas de 2027 y las de 2031 de la
 * misma mesa colisionarían en el índice único y una pisaría a la otra.
 */
class ElectoralEvent extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'electoral_events';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'fecha',
        'tipo',
        'candidato_propio_numero',
        'candidato_propio_lista_numero',
        'candidato_propio_nombre',
        'candidato_propio_agrupacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'candidato_propio_numero' => 'integer',
        'candidato_propio_lista_numero' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(E14Candidate::class);
    }

    public function actas(): HasMany
    {
        return $this->hasMany(E14Acta::class);
    }

    /**
     * ¿Ya se sabe de quién son los votos que hay que cruzar? (Spec 0062)
     *
     * Es el número del tarjetón el que manda: sin él no hay fila del E-14 que
     * mirar, aunque estén el nombre y el partido.
     */
    public function tieneCandidatoPropio(): bool
    {
        return $this->candidato_propio_numero !== null;
    }
}
