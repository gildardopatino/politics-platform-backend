<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Tenant extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'slug',
        'nombre',
        'tipo_cargo',
        'identificacion',
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
        if (!$this->expiration_date) {
            return true;
        }

        // If no start date is set, only check expiration
        if (!$this->start_date) {
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
        if (!$this->expiration_date) {
            return false;
        }

        return now()->gt($this->expiration_date);
    }

    /**
     * Check if the tenant hasn't started yet
     */
    public function isNotStarted(): bool
    {
        if (!$this->start_date) {
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
        if (!$this->expiration_date) {
            return null;
        }

        return (int) floor(now()->diffInDays($this->expiration_date, false));
    }
}
