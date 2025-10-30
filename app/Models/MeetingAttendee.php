<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MeetingAttendee extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'meeting_id',
        'created_by',
        'cedula',
        'nombres',
        'apellidos',
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
