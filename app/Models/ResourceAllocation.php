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
        'assigned_to_user_id',
        'assigned_by_user_id',
        'leader_user_id',
        'title',
        'type',
        'amount',
        'total_cost',
        'details',
        'allocation_date',
        'notes',
        'cash_purpose',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'allocation_date' => 'date',
        'details' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'amount', 'allocation_date', 'status'])
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

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
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

    // Nueva relación con items
    public function items()
    {
        return $this->hasMany(ResourceAllocationItem::class);
    }

    // Accessor para calcular total desde items
    public function getTotalFromItemsAttribute(): float
    {
        return $this->items->sum('subtotal');
    }
}
