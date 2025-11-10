<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class MessagingCreditOrder extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'quantity',
        'unit_price',
        'total_amount',
        'currency',
        'payment_method',
        'payment_provider',
        'payment_id',
        'preference_id',
        'status',
        'payment_status',
        'payment_details',
        'processed_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_details' => 'array',
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the order
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who created the order
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for pending orders
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for completed orders
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for failed orders
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Check if order is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if order can be processed
     */
    public function canBeProcessed(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }
}
