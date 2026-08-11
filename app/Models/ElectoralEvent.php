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

    /** ¿Esta elección es a una corporación —concejo, asamblea, senado—? */
    public function esDeCorporacion(): bool
    {
        return E14Acta::esCorporacion($this->tipo);
    }

    /**
     * ¿Ya se sabe de quién son los votos que hay que cruzar? (Specs 0062 y 0083)
     *
     * Manda el número, no el nombre ni el partido: sin él no hay fila del E-14
     * que mirar. En corporación hacen falta **los dos** números, porque ahí el
     * candidato es una persona dentro de una lista y el de preferencia se
     * repite en todas las agrupaciones del tarjetón: el 5 solo identifica a
     * alguien acompañado de su lista. Media configuración no abre la puerta.
     */
    public function tieneCandidatoPropio(): bool
    {
        if ($this->candidato_propio_numero === null) {
            return false;
        }

        return ! $this->esDeCorporacion() || $this->candidato_propio_lista_numero !== null;
    }

    /**
     * Quién es mi candidato, para la `meta` del cruce y del rendimiento.
     *
     * Vive en el modelo y no en cada servicio porque los dos paneles rotulan lo
     * mismo, y `es_corporacion` es justo lo que le dice al frontend si «5» es un
     * número del tarjetón o el de preferencia dentro de una lista.
     *
     * @return array<string, mixed>
     */
    public function resumenDelCandidato(): array
    {
        return [
            'numero' => $this->candidato_propio_numero,
            'lista_numero' => $this->candidato_propio_lista_numero,
            'nombre' => $this->candidato_propio_nombre,
            'agrupacion' => $this->candidato_propio_agrupacion,
            'es_corporacion' => $this->esDeCorporacion(),
        ];
    }
}
