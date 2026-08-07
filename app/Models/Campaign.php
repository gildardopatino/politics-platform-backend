<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Campaign extends Model implements Auditable
{
    use HasFactory, HasTenant, LogsActivity, SoftDeletes;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'creator_token',
        'title',
        'message',
        'channel',
        'filter_json',
        'scheduled_at',
        'queued_at',
        'sent_at',
        'status',
        'total_recipients',
        'sent_count',
        'failed_count',
    ];

    protected $casts = [
        'filter_json' => 'array',
        'scheduled_at' => 'datetime',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /**
     * `creator_token` es una credencial: el JWT con el que el webhook de correo
     * de n8n se autentica al enviar la campaña. No sale del servidor.
     *
     * Fuera de toda serialización del modelo (Spec 0039, Art. VII). No afecta al
     * acceso por atributo, que es como lo lee `CampaignService::sendToRecipient`.
     */
    protected $hidden = [
        'creator_token',
    ];

    /**
     * Y fuera del registro de auditoría: sin esto `owen-it` copiaba el token a
     * `audits.new_values`, que se consulta por API con `view_audits`, y la
     * credencial se multiplicaba (hallazgo de la caracterización 0013).
     *
     * @var array<int, string>
     */
    protected $auditExclude = [
        'creator_token',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'channel', 'status', 'sent_count'])
            ->logOnlyDirty();
    }

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients()
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * En curso. El estado real es `sending`, el que escribe `SendCampaignJob`;
     * este scope preguntaba por `in_progress`, que no lo escribe nadie y no cabe
     * en la columna (Spec 0038).
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'sending');
    }

    /**
     * Terminada. Igual que arriba: el estado real es `sent`, no `completed`.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    // Helpers
    public function getProgressPercentage(): float
    {
        if ($this->total_recipients === 0) {
            return 0;
        }

        return round(($this->sent_count / $this->total_recipients) * 100, 2);
    }
}
