<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * El escrutinio de una mesa (Spec 0061).
 *
 * Se guarda auditado a propósito: corregir a mano el resultado de una mesa es
 * exactamente la clase de cambio del que después alguien va a pedir cuentas, y
 * `audits` conserva el antes y el después con el usuario que lo hizo.
 */
class E14Acta extends Model implements Auditable
{
    use HasFactory, HasTenant, LogsActivity;
    use \OwenIt\Auditing\Auditable;

    public const ESTADO_PROCESADA = 'procesada';

    public const ESTADO_INCONSISTENTE = 'inconsistente';

    public const ESTADO_REVISION_MANUAL = 'revision_manual';

    public const ESTADOS = [
        self::ESTADO_PROCESADA,
        self::ESTADO_INCONSISTENTE,
        self::ESTADO_REVISION_MANUAL,
    ];

    public const FUENTE_VISION = 'vision';

    public const FUENTE_MANUAL = 'manual';

    protected $table = 'e14_actas';

    protected $fillable = [
        'tenant_id',
        'electoral_event_id',
        'tipo',
        'departamento_code',
        'municipio_code',
        'zona',
        'puesto',
        'mesa',
        'lugar',
        'archivo_nombre',
        'archivo_hash',
        'estado',
        'suma_calculada',
        'suma_declarada',
        'votos_urna',
        'votantes_e11',
        'dif_nivelacion',
        'votos_blanco',
        'votos_nulos',
        'votos_no_marcados',
        'fuente',
        'confianza',
        'observacion',
        'processed_at',
    ];

    protected $casts = [
        'suma_calculada' => 'integer',
        'suma_declarada' => 'integer',
        'votos_urna' => 'integer',
        'votantes_e11' => 'integer',
        'dif_nivelacion' => 'integer',
        'votos_blanco' => 'integer',
        'votos_nulos' => 'integer',
        'votos_no_marcados' => 'integer',
        'confianza' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'suma_declarada', 'votos_urna', 'fuente', 'observacion'])
            ->logOnlyDirty();
    }

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
        return $this->hasMany(E14Resultado::class, 'e14_acta_id');
    }

    public function cuadra(): bool
    {
        return $this->estado === self::ESTADO_PROCESADA;
    }
}
