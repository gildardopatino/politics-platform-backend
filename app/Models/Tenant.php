<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Tenant extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Los cargos a los que una campaña puede aspirar (Spec 0084).
     *
     * **Es la lista, no una copia de la lista.** La validan los FormRequest de
     * alta y edición, y es el mismo conjunto que el enum de la columna
     * `tenants.tipo_cargo`; una prueba compara las dos fuentes y se cae si
     * alguien mueve una sola. Antes de la 0084 divergían: el FormRequest aceptaba
     * `Representante` y la columna no, así que una campaña de Cámara pasaba la
     * validación y reventaba contra el CHECK al insertar, lejos de la causa.
     *
     * `Representante` (Cámara) se quitó porque el sistema **no tiene actas de
     * Cámara**: sin tipo de acta E-14 no hay escrutinio ni cruce que ofrecerle.
     * Vuelve cuando exista el lector, como extensión de la 0067.
     *
     * `Otro` se queda como catch-all sin elección asociada: no es un cargo de
     * elección popular y `EventoResolver` lo deja sin mapear a propósito.
     *
     * @var array<int, string>
     */
    public const TIPOS_CARGO = [
        'Gobernacion',
        'Alcaldia',
        'Concejo',
        'Congresista',
        'Diputado',
        'Otro',
    ];

    /**
     * A qué elección del E-14 corresponde cada cargo (Spec 0093).
     *
     * **Un tenant sirve a una sola elección**, y esta tabla es la que lo dice:
     * el producto se vende por uso, así que la campaña de un alcalde escruta
     * alcaldía y nada más. De aquí sale el tipo que usan la carga, el cruce, el
     * consolidado, las estadísticas y la proyección; ninguno lo vuelve a
     * preguntar al cliente, porque un `?tipo=` en la URL era la forma de mirar
     * —y de mezclar— la elección de otro.
     *
     * Vive pegada a `TIPOS_CARGO` porque son la misma decisión mirada dos veces,
     * y una prueba comprueba que las dos listas cubren exactamente los mismos
     * cargos: un cargo nuevo sin elección asignada dejaría el escrutinio mudo.
     *
     * Los dos idiomas no se traducen por texto: `tenants.tipo_cargo` es el enum
     * del alta de campañas (capitalizado, sin tildes) y `E14Acta::TIPOS` es el
     * vocabulario del papel. Traducir por parecido acertaría en tres casos y
     * fallaría en los dos que importan — un **diputado** se elige en la
     * `asamblea_departamental` y un **congresista** en el `senado`.
     *
     * `Otro` vale `null` a propósito: no es un cargo de elección popular, así
     * que esa campaña **no tiene escrutinio**. No se le inventa un tipo.
     *
     * @var array<string, string|null>
     */
    public const ELECCION_POR_CARGO = [
        'Gobernacion' => E14Acta::TIPO_GOBERNACION,
        'Alcaldia' => E14Acta::TIPO_ALCALDIA,
        'Concejo' => E14Acta::TIPO_CONCEJO,
        'Congresista' => E14Acta::TIPO_SENADO,
        'Diputado' => E14Acta::TIPO_ASAMBLEA,
        'Otro' => null,
    ];

    protected $fillable = [
        'slug',
        'nombre',
        'tipo_cargo',
        'identificacion',
        'email_contacto',
        'phone_contacto',
        'metadata',
        'biografia_data',
        's3_bucket',
        'logo',
        'sidebar_bg_color',
        'sidebar_text_color',
        'header_bg_color',
        'header_text_color',
        'content_bg_color',
        'content_text_color',
        'hierarchy_mode',
        'auto_assign_hierarchy',
        'hierarchy_conflict_resolution',
        'require_hierarchy_config',
        'start_date',
        'expiration_date',
        // Social media credentials
        'twitter_enabled',
        'twitter_bearer_token',
        'twitter_user_id',
        'twitter_username',
        'facebook_enabled',
        'facebook_access_token',
        'facebook_page_id',
        'instagram_enabled',
        'instagram_access_token',
        'instagram_user_id',
        'instagram_username',
        'youtube_enabled',
        'youtube_api_key',
        'youtube_channel_id',
        'social_auto_sync_enabled',
        'social_sync_interval_minutes',
        'social_last_synced_at',
        // Notification settings
        'send_logistics_notifications',
    ];

    protected $casts = [
        'metadata' => 'array',
        'biografia_data' => 'array',
        'auto_assign_hierarchy' => 'boolean',
        'require_hierarchy_config' => 'boolean',
        'twitter_enabled' => 'boolean',
        'facebook_enabled' => 'boolean',
        'instagram_enabled' => 'boolean',
        'youtube_enabled' => 'boolean',
        'social_auto_sync_enabled' => 'boolean',
        'social_last_synced_at' => 'datetime',
        'send_logistics_notifications' => 'boolean',
        // Note: start_date and expiration_date use custom mutators/accessors
        // to preserve exact datetime without timezone conversion
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * La elección de esta campaña, o `null` si no escruta (Spec 0093).
     *
     * Es **la** pregunta del escrutinio: de qué elección son las actas que esta
     * campaña puede cargar, cruzar y consolidar. La respuesta sale del tenant y
     * de ningún otro sitio; el `tipo` que llegue por la URL se ignora.
     *
     * `null` significa «esta campaña no escruta» (cargo `Otro`), no «todavía no
     * se sabe»: quien lo reciba tiene que decirlo, no elegir una elección por él.
     */
    public function tipoEleccion(): ?string
    {
        return self::eleccionDelCargo($this->tipo_cargo);
    }

    /**
     * Lo mismo a partir del cargo suelto, para quien todavía no tiene el modelo.
     *
     * Se normaliza la caja porque el enum se escribió capitalizado y no hay
     * garantía de que un dato viejo lo respete; lo que **no** se hace es inferir
     * por parecido: fuera de la tabla, no hay elección.
     */
    public static function eleccionDelCargo(?string $tipoCargo): ?string
    {
        $clave = mb_strtolower(trim((string) $tipoCargo));

        return array_change_key_case(self::ELECCION_POR_CARGO)[$clave] ?? null;
    }

    /**
     * Set start_date attribute - stores exactly as received without timezone conversion
     */
    protected function setStartDateAttribute($value): void
    {
        if ($value) {
            // Remove timezone info and store as-is
            $this->attributes['start_date'] = \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } else {
            $this->attributes['start_date'] = null;
        }
    }

    /**
     * Get start_date attribute - returns as Carbon instance in app timezone
     */
    protected function getStartDateAttribute($value): ?\Carbon\Carbon
    {
        if ($value) {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value, config('app.timezone'));
        }

        return null;
    }

    /**
     * Set expiration_date attribute - stores exactly as received without timezone conversion
     */
    protected function setExpirationDateAttribute($value): void
    {
        if ($value) {
            // Remove timezone info and store as-is
            $this->attributes['expiration_date'] = \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } else {
            $this->attributes['expiration_date'] = null;
        }
    }

    /**
     * Get expiration_date attribute - returns as Carbon instance in app timezone
     */
    protected function getExpirationDateAttribute($value): ?\Carbon\Carbon
    {
        if ($value) {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value, config('app.timezone'));
        }

        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'nombre', 'tipo_cargo', 'identificacion'])
            ->logOnlyDirty();
    }

    // Relationships
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function messagingCredit()
    {
        return $this->hasOne(TenantMessagingCredit::class);
    }

    public function messagingTransactions()
    {
        return $this->hasMany(MessagingCreditTransaction::class);
    }

    public function messagingOrders()
    {
        return $this->hasMany(MessagingCreditOrder::class);
    }

    public function whatsappInstances()
    {
        return $this->hasMany(TenantWhatsAppInstance::class);
    }

    public function activeWhatsappInstances()
    {
        return $this->hasMany(TenantWhatsAppInstance::class)->where('is_active', true);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function resourceAllocations()
    {
        return $this->hasMany(ResourceAllocation::class);
    }

    /**
     * Check if the tenant is currently active (not expired)
     */
    public function isActive(): bool
    {
        // If no expiration date is set, tenant is always active
        if (! $this->expiration_date) {
            return true;
        }

        // If no start date is set, only check expiration
        if (! $this->start_date) {
            return now()->lte($this->expiration_date);
        }

        // Check if current date is between start and expiration dates
        return now()->gte($this->start_date) && now()->lte($this->expiration_date);
    }

    /**
     * Check if the tenant is expired
     */
    public function isExpired(): bool
    {
        if (! $this->expiration_date) {
            return false;
        }

        return now()->gt($this->expiration_date);
    }

    /**
     * Check if the tenant hasn't started yet
     */
    public function isNotStarted(): bool
    {
        if (! $this->start_date) {
            return false;
        }

        return now()->lt($this->start_date);
    }

    /**
     * Get the number of days until expiration
     * Returns null if no expiration date is set
     * Returns negative number if already expired
     */
    public function daysUntilExpiration(): ?int
    {
        if (! $this->expiration_date) {
            return null;
        }

        return (int) floor(now()->diffInDays($this->expiration_date, false));
    }
}
