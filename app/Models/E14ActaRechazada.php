<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El acta que se rechazó por no ser de esta elección (Spec 0093 · RF-B6).
 *
 * Es la constancia de un borrado: el acta y su PDF ya no existen, y esto es todo
 * lo que queda de ellos. Por eso no tiene relación con `E14Acta` —no hay a qué
 * apuntar— y guarda el nombre y el hash del archivo copiados, no referenciados.
 *
 * **No lleva PII**: el acta rechazada nunca se leyó, así que aquí no hay cifras,
 * ni candidatos, ni mesa.
 */
class E14ActaRechazada extends Model
{
    use HasFactory, HasTenant;

    /** Por qué se rechazó. Hoy solo hay un motivo; el campo deja sitio a más. */
    public const MOTIVO_OTRA_ELECCION = 'otra_eleccion';

    protected $table = 'e14_actas_rechazadas';

    /**
     * Un rechazo pasa, no se edita: solo tiene `created_at`.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'electoral_event_id',
        'archivo_nombre',
        'archivo_hash',
        'eleccion_detectada',
        'eleccion_esperada',
        'motivo',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function electoralEvent(): BelongsTo
    {
        return $this->belongsTo(ElectoralEvent::class);
    }
}
