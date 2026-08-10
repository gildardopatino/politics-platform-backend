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

    /** Subida desde el panel, todavía sin encolar (Spec 0071). */
    public const ESTADO_CARGADA = 'cargada';

    /** En la cola, esperando a que un worker la reclame. */
    public const ESTADO_PENDIENTE = 'pendiente';

    /** Reclamada por un worker; nadie más puede tomarla. */
    public const ESTADO_PROCESANDO = 'procesando';

    public const ESTADO_PROCESADA = 'procesada';

    public const ESTADO_INCONSISTENTE = 'inconsistente';

    public const ESTADO_REVISION_MANUAL = 'revision_manual';

    public const ESTADOS = [
        self::ESTADO_CARGADA,
        self::ESTADO_PENDIENTE,
        self::ESTADO_PROCESANDO,
        self::ESTADO_PROCESADA,
        self::ESTADO_INCONSISTENTE,
        self::ESTADO_REVISION_MANUAL,
    ];

    /**
     * Estados en los que el acta ya se leyó. Son los únicos que el cliente de
     * ingesta directa (Spec 0061) puede declarar: la cola es cosa del servidor.
     */
    public const ESTADOS_LEIDA = [
        self::ESTADO_PROCESADA,
        self::ESTADO_INCONSISTENTE,
        self::ESTADO_REVISION_MANUAL,
    ];

    /**
     * Tipos de elección (Spec 0071). Uninominales primero, corporaciones después:
     * estas últimas se cargan y encolan, pero su lectura necesita el parser
     * multipágina de la 0067.
     */
    public const TIPO_ALCALDIA = 'alcaldia';

    public const TIPO_GOBERNACION = 'gobernacion';

    public const TIPO_CONCEJO = 'concejo';

    public const TIPO_SENADO = 'senado';

    public const TIPO_ASAMBLEA = 'asamblea_departamental';

    public const TIPOS = [
        self::TIPO_ALCALDIA,
        self::TIPO_GOBERNACION,
        self::TIPO_CONCEJO,
        self::TIPO_SENADO,
        self::TIPO_ASAMBLEA,
    ];

    /** Los que se leen con el formato de una página. */
    public const TIPOS_UNINOMINALES = [
        self::TIPO_ALCALDIA,
        self::TIPO_GOBERNACION,
    ];

    public const FUENTE_VISION = 'vision';

    public const FUENTE_MANUAL = 'manual';

    protected $table = 'e14_actas';

    protected $fillable = [
        'tenant_id',
        'electoral_event_id',
        'tipo',
        'departamento_code',
        'departamento',
        'municipio_code',
        'municipio',
        'zona',
        'puesto',
        'mesa',
        'lugar',
        'voting_place_id',
        'archivo_nombre',
        'archivo_hash',
        'upload_batch_id',
        'archivo_path',
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
        'hubo_recuento',
        'constancias',
        'recuento_solicitado_por',
        'recuento_representacion',
        'processed_at',
        'claimed_at',
        'intentos',
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
        // Nullable de verdad: sí, no, y «no se pudo leer» son tres cosas
        // distintas (Spec 0073).
        'hubo_recuento' => 'boolean',
        'processed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'intentos' => 'integer',
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

    /**
     * El puesto canónico al que resolvió el `lugar` impreso (Spec 0062).
     *
     * Es la llave del cruce con los registrados. Puede ser nulo: un acta sin
     * `lugar` legible no tiene con qué resolverlo, y eso se reporta como
     * cobertura en vez de inventarse un puesto.
     */
    public function votingPlace(): BelongsTo
    {
        return $this->belongsTo(VotingPlace::class);
    }

    public function cuadra(): bool
    {
        return $this->estado === self::ESTADO_PROCESADA;
    }

    /** ¿Ya se leyó, o sigue en algún punto de la cola? */
    public function fueLeida(): bool
    {
        return in_array($this->estado, self::ESTADOS_LEIDA, true);
    }

    public function esUninominal(): bool
    {
        return in_array($this->tipo, self::TIPOS_UNINOMINALES, true);
    }

    /**
     * ¿Los jurados dejaron algo escrito en la página 2? (Spec 0073)
     *
     * Es lo que decide si el revisor tiene contexto que leer antes de tocar las
     * cifras de una mesa que no cuadra.
     */
    public function tieneConstancias(): bool
    {
        return filled($this->constancias) || $this->hubo_recuento === true;
    }
}
