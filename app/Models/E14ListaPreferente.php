<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * El voto preferente de un candidato dentro de una lista (Spec 0067).
 *
 * **Sin nombre a propósito.** El E-14 de corporación solo imprime el número de
 * preferencia; ponerle un nombre aquí obligaría a inventárselo o a cruzarlo con
 * un catálogo que el acta no trae. La identidad que necesitan el consolidado y
 * el cruce es exactamente `(lista, número)`, y es la que se guarda.
 *
 * Los números **no son correlativos**: el acta salta los renglones que la lista
 * no llenó, así que una lista puede ir 1, 3, 5, 12, 19.
 */
class E14ListaPreferente extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'e14_lista_preferentes';

    protected $fillable = [
        'tenant_id',
        'e14_acta_id',
        'e14_lista_resultado_id',
        'numero',
        'votos',
    ];

    protected $casts = [
        'numero' => 'integer',
        'votos' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function acta(): BelongsTo
    {
        return $this->belongsTo(E14Acta::class, 'e14_acta_id');
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(E14ListaResultado::class, 'e14_lista_resultado_id');
    }
}
