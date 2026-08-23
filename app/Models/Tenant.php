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
