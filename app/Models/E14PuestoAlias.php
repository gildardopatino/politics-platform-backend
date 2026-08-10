<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una fusión de puestos decidida por una persona (Spec 0062).
 *
 * Auditada a propósito: decir que dos nombres son el mismo puesto mueve
 * registrados de una fila del cruce a otra, y de ese cambio alguien va a pedir
 * cuentas. El repunte de los votantes y las actas se hace con un `UPDATE` masivo
 * —no fila por fila—, así que el rastro de quién lo pidió y cuándo es este
 * registro.
 */
class E14PuestoAlias extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'e14_puesto_alias';

    protected $fillable = [
        'tenant_id',
        'clave',
        'voting_place_id',
        'municipio',
        'puesto',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function votingPlace(): BelongsTo
    {
        return $this->belongsTo(VotingPlace::class);
    }
}
