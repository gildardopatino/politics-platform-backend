<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * La meta de votos de un puesto (Spec 0064 · RF-1).
 *
 * Un override de la meta global de la elección, puesto a mano. Que exista la fila
 * **es** la meta: no hay estado «meta cero por defecto», porque un cero sembrado
 * se leería como un objetivo fijado y no como el «todavía no la repartí» que en
 * realidad es.
 *
 * Auditada como el resto de la configuración de campaña: cambiar la meta cambia
 * el semáforo de todo el tablero, y conviene saber quién la movió.
 */
class E14MetaPuesto extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'e14_meta_puesto';

    protected $fillable = [
        'tenant_id',
        'electoral_event_id',
        'voting_place_id',
        'meta_votos',
    ];

    protected $casts = [
        'meta_votos' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(ElectoralEvent::class, 'electoral_event_id');
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(VotingPlace::class, 'voting_place_id');
    }
}
