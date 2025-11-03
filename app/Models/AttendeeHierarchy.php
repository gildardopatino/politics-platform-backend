<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class AttendeeHierarchy extends Model
{
    use HasFactory, HasTenant, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'attendee_cedula',
        'attendee_name',
        'attendee_email',
        'attendee_phone',
        'supervisor_cedula',
        'supervisor_name',
        'relationship_strength',
        'last_interaction',
        'context',
        'is_primary',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'last_interaction' => 'date',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'relationship_strength' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['attendee_cedula', 'supervisor_cedula', 'is_primary', 'is_active'])
            ->logOnlyDirty();
    }

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeForAttendee($query, string $cedula)
    {
        return $query->where('attendee_cedula', $cedula);
    }

    public function scopeForSupervisor($query, string $cedula)
    {
        return $query->where('supervisor_cedula', $cedula);
    }

    public function scopeInContext($query, ?string $context = null)
    {
        if ($context) {
            return $query->where('context', $context);
        }
        return $query->whereNull('context');
    }

    // Helper methods
    public function getSubordinates()
    {
        return self::where('tenant_id', $this->tenant_id)
                  ->where('supervisor_cedula', $this->attendee_cedula)
                  ->active()
                  ->get();
    }

    public function getSupervisors()
    {
        return self::where('tenant_id', $this->tenant_id)
                  ->where('attendee_cedula', $this->attendee_cedula)
                  ->active()
                  ->get();
    }

    public function getPrimarySupervisor()
    {
        return self::where('tenant_id', $this->tenant_id)
                  ->where('attendee_cedula', $this->attendee_cedula)
                  ->primary()
                  ->active()
                  ->first();
    }
}