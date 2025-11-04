<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MeetingAttendee extends Model
{
    use HasFactory, HasTenant, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'meeting_id',
        'created_by',
        'cedula',
        'nombres',
        'apellidos',
        'barrio_id',
        'direccion',
        'telefono',
        'email',
        'extra_fields',
        'checked_in',
        'checked_in_at',
    ];

    protected $casts = [
        'extra_fields' => 'array',
        'checked_in' => 'boolean',
        'checked_in_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($attendee) {
            // Auto-set tenant_id if not provided
            if (!$attendee->tenant_id) {
                if ($attendee->meeting_id) {
                    $meeting = Meeting::find($attendee->meeting_id);
                    $attendee->tenant_id = $meeting->tenant_id;
                } elseif (app()->bound('current_tenant_id')) {
                    $attendee->tenant_id = app('current_tenant_id');
                }
            }
            
            // Auto-set created_by if not provided
            if (!$attendee->created_by && request()->user()) {
                $attendee->created_by = request()->user()->id;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cedula', 'nombres', 'apellidos', 'checked_in'])
            ->logOnlyDirty();
    }

    // Relationships
    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function barrio()
    {
        return $this->belongsTo(Barrio::class);
    }

    // Scopes
    public function scopeCheckedIn($query)
    {
        return $query->where('checked_in', true);
    }

    public function scopeNotCheckedIn($query)
    {
        return $query->where('checked_in', false);
    }

    // Accessors
    public function getFullNameAttribute(): string
    {
        return "{$this->nombres} {$this->apellidos}";
    }
}
