<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Meeting extends Model
{
    use HasFactory, HasTenant, SoftDeletes, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'planner_user_id',
        'assigned_to_cedula',
        'template_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'lugar_nombre',
        'direccion',
        'department_id',
        'municipality_id',
        'commune_id',
        'barrio_id',
        'corregimiento_id',
        'vereda_id',
        'latitude',
        'longitude',
        'qr_code',
        'status',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'metadata' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'starts_at', 'status'])
            ->logOnlyDirty();
    }

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function planner()
    {
        return $this->belongsTo(User::class, 'planner_user_id');
    }

    public function template()
    {
        return $this->belongsTo(MeetingTemplate::class, 'template_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function commune()
    {
        return $this->belongsTo(Commune::class);
    }

    public function barrio()
    {
        return $this->belongsTo(Barrio::class);
    }

    public function corregimiento()
    {
        return $this->belongsTo(Corregimiento::class);
    }

    public function vereda()
    {
        return $this->belongsTo(Vereda::class);
    }

    public function attendees()
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where('starts_at', '>', now())
                     ->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
