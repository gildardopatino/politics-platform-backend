<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeetingReminder extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'meeting_id',
        'created_by_user_id',
        'reminder_datetime',
        'recipients',
        'status',
        'job_id',
        'message',
        'metadata',
        'total_recipients',
        'sent_count',
        'failed_count',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'reminder_datetime' => 'datetime',
        'recipients' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    /**
     * Relación con la reunión
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * Relación con el usuario que creó el recordatorio
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope para recordatorios pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope para recordatorios que deben enviarse pronto
     */
    public function scopeDueToSend($query)
    {
        return $query->where('status', 'pending')
            ->where('reminder_datetime', '<=', now());
    }

    /**
     * Verifica si el recordatorio puede ser cancelado
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Marca el recordatorio como cancelado
     */
    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update(['status' => 'cancelled']);
        return true;
    }
}
