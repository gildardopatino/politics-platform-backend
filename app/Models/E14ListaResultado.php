<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Los votos de una agrupación política en una mesa (Spec 0067).
 *
 * Es el primer nivel del resultado de corporación: lo que sacó la lista entera,
 * que es la cifra con la que se reparten curules. El segundo nivel —quién se las
 * lleva dentro de la lista— son sus `preferentes`.
 *
 * Cubre las dos maquetas del papel con la misma fila: la lista **con** voto
 * preferente guarda su renglón «0 · VOTOS SOLO POR LA LISTA» en
 * `votos_solo_lista` y los candidatos aparte; la lista **sin** voto preferente
 * es un único renglón, que se guarda en el mismo campo con `preferentes` vacío.
 * Así el self-check —`solo_lista + Σ preferentes = total_agrupacion`— vale para
 * las dos sin preguntar cuál es.
 */
class E14ListaResultado extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'e14_lista_resultados';

    protected $fillable = [
        'tenant_id',
        'e14_acta_id',
        'lista_numero',
        'lista_nombre',
        'votos_solo_lista',
        'total_agrupacion',
        'con_voto_preferente',
    ];

    protected $casts = [
        'lista_numero' => 'integer',
        'votos_solo_lista' => 'integer',
        'total_agrupacion' => 'integer',
        'con_voto_preferente' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function acta(): BelongsTo
    {
        return $this->belongsTo(E14Acta::class, 'e14_acta_id');
    }

    public function preferentes(): HasMany
    {
        return $this->hasMany(E14ListaPreferente::class, 'e14_lista_resultado_id')
            ->orderBy('numero');
    }

    /**
     * Lo que suman de verdad las casillas de esta lista.
     */
    public function sumaCalculada(): int
    {
        return (int) $this->votos_solo_lista + (int) $this->preferentes->sum('votos');
    }

    /**
     * Cómo se nombra la lista en un motivo de revisión.
     *
     * Con diecisiete agrupaciones por acta, «no cuadra por un voto» no le sirve
     * a quien tiene el papel delante; el número y el partido le señalan la hoja.
     */
    public function etiqueta(): string
    {
        return filled($this->lista_nombre)
            ? "{$this->lista_numero} · {$this->lista_nombre}"
            : (string) $this->lista_numero;
    }
}
