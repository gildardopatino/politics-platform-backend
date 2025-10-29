<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ResourceAllocation extends Model
{
    use HasFactory, HasTenant, SoftDeletes, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'meeting_id',
        'allocated_by_user_id',
        'leader_user_id',
        'type',
        'descripcion',
        'amount',
        'fecha_asignacion',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fecha_asignacion' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'descripcion', 'amount'])
            ->logOnlyDirty();
    }

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function allocatedBy()
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    // Scopes
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeCash($query)
    {
        return $query->where('type', 'cash');
    }

    public function scopeMaterial($query)
    {
        return $query->where('type', 'material');
    }

    public function scopeService($query)
    {
        return $query->where('type', 'service');
    }
}
