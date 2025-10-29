<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Traits\HasTenant;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes, HasRoles, LogsActivity, HasTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'cedula',
        'password',
        'is_team_leader',
        'is_super_admin',
        'created_by_user_id',
        'reports_to',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_team_leader' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_team_leader'])
            ->logOnlyDirty();
    }

    // JWT Methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'tenant_id' => $this->tenant_id,
            'is_super_admin' => $this->is_super_admin,
        ];
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'reports_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by_user_id');
    }

    public function plannedMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'planner_user_id');
    }

    public function assignedCommitments(): HasMany
    {
        return $this->hasMany(Commitment::class, 'assigned_user_id');
    }

    public function resourceAllocations(): HasMany
    {
        return $this->hasMany(ResourceAllocation::class, 'assigned_to_user_id');
    }

    // Scopes
    public function scopeTeamLeaders(Builder $query): Builder
    {
        return $query->where('is_team_leader', true);
    }

    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where('is_super_admin', true);
    }

    // Helpers
    public function isSuperAdminGlobal(): bool
    {
        return $this->is_super_admin && is_null($this->tenant_id);
    }

    public function isTenantSuperAdmin(): bool
    {
        return $this->is_super_admin && !is_null($this->tenant_id);
    }

    public function getTeamHierarchy(): array
    {
        $subordinates = $this->subordinates()->with('subordinates')->get();
        
        return $subordinates->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_team_leader' => $user->is_team_leader,
                'subordinates' => $user->getTeamHierarchy(),
            ];
        })->toArray();
    }
}
