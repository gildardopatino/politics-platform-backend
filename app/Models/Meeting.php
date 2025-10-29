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
        'planned_by_user_id',
        'meeting_template_id',
        'titulo',
        'descripcion',
        'fecha_programada',
        'fecha_realizacion',
        'direccion',
        'department_id',
        'city_id',
        'commune_id',
        'barrio_id',
        'corregimiento_id',
        'vereda_id',
        'latitud',
        'longitud',
        'qr_code',
        'status',
        'metadata',
    ];

    protected $casts = [
        'fecha_programada' => 'datetime',
        'fecha_realizacion' => 'datetime',
        'latitud' => 'decimal:8',
        'longitud' => 'decimal:8',
        'metadata' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['titulo', 'fecha_programada', 'status'])
            ->logOnlyDirty();
    }

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plannedBy()
    {
        return $this->belongsTo(User::class, 'planned_by_user_id');
    }

    public function template()
    {
        return $this->belongsTo(MeetingTemplate::class, 'meeting_template_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function commune()
    {
        return $this->belongsTo(Commune::class);
    }

    public function barrio()
    {
        return $this->belongsTo(Barrio::class);
    }

    public function attendees()
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function resourceAllocations()
    {
        return $this->hasMany(ResourceAllocation::class);
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where('fecha_programada', '>', now())
                     ->where('status', 'planned');
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
