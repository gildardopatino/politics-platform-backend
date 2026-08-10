<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un renglón del tarjetón (Spec 0061).
 *
 * El catálogo se llena solo con la primera acta que menciona a cada candidato:
 * exigir que alguien lo cargue a mano antes de poder leer un acta sería un paso
 * de configuración que en la noche de escrutinio nadie va a dar.
 */
class E14Candidate extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'e14_candidates';

    protected $fillable = [
        'tenant_id',
        'electoral_event_id',
        'numero',
        'nombre',
        'agrupacion',
        'cargo',
    ];

    protected $casts = [
        'numero' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function electoralEvent(): BelongsTo
    {
        return $this->belongsTo(ElectoralEvent::class);
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(E14Resultado::class);
    }
}
