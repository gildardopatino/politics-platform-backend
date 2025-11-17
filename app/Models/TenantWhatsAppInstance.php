<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class TenantWhatsAppInstance extends Model
{
    use SoftDeletes;

    protected $table = 'tenant_whatsapp_instances';

    protected $fillable = [
        'tenant_id',
        'phone_number',
        'instance_name',
        'evolution_api_key',
        'evolution_api_url',
        'daily_message_limit',
        'messages_sent_today',
        'last_reset_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'daily_message_limit' => 'integer',
        'messages_sent_today' => 'integer',
        'last_reset_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'evolution_api_key', // Ocultar API key en respuestas JSON por defecto
    ];

    /**
     * Relationship: Tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope: Only active instances
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Instances with available quota
     */
    public function scopeWithAvailableQuota($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw('messages_sent_today < daily_message_limit')
              ->orWhereDate('last_reset_date', '<', Carbon::today());
        });
    }

    /**
     * Check if instance can send messages (active and has quota)
     */
    public function canSendMessage(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Reset counter if it's a new day
        $this->resetDailyCounterIfNeeded();

        return $this->messages_sent_today < $this->daily_message_limit;
    }

    /**
     * Get remaining daily quota
     */
    public function getRemainingQuota(): int
    {
        $this->resetDailyCounterIfNeeded();
        
        return max(0, $this->daily_message_limit - $this->messages_sent_today);
    }

    /**
     * Increment sent messages counter
     */
    public function incrementSentCount(int $count = 1): void
    {
        $this->resetDailyCounterIfNeeded();
        
        $this->increment('messages_sent_today', $count);
    }

    /**
     * Reset daily counter if it's a new day
     */
    public function resetDailyCounterIfNeeded(): void
    {
        if (!$this->last_reset_date || $this->last_reset_date->lt(Carbon::today())) {
            $this->update([
                'messages_sent_today' => 0,
                'last_reset_date' => Carbon::today(),
            ]);
            
            // Refresh model to get updated values
            $this->refresh();
        }
    }

    /**
     * Get Evolution API base URL (use custom or default)
     */
    public function getEvolutionApiBaseUrl(): string
    {
        return $this->evolution_api_url ?? config('services.evolution_api.base_url', 'https://evolution-api.com');
    }
}
