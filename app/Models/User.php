<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Traits\HasTenant;
use OwenIt\Auditing\Contracts\Auditable;

class User extends Authenticatable implements JWTSubject, Auditable
{
    use HasFactory, Notifiable, SoftDeletes, HasRoles, LogsActivity, HasTenant;
    use \OwenIt\Auditing\Auditable;

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
        'department_id',
        'municipality_id',
        'commune_id',
        'barrio_id',
        'corregimiento_id',
        'vereda_id',
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

    // ========================================
    // NEW: Many-to-Many Geographic Relationships
    // Using manual polymorphic queries
    // ========================================
    
    public function departments()
    {
        return $this->belongsToMany(
            Department::class,
            'user_geographic_assignments',
            'user_id',
            'assignable_id'
        )->wherePivot('assignable_type', 'App\\Models\\Department')
        ->withTimestamps();
    }

    public function municipalities()
    {
        return $this->belongsToMany(
            Municipality::class,
            'user_geographic_assignments',
            'user_id',
            'assignable_id'
        )->wherePivot('assignable_type', 'App\\Models\\Municipality')
        ->withTimestamps();
    }

    public function communes()
    {
        return $this->belongsToMany(
            Commune::class,
            'user_geographic_assignments',
            'user_id',
            'assignable_id'
        )->wherePivot('assignable_type', 'App\\Models\\Commune')
        ->withTimestamps();
    }

    public function barrios()
    {
        return $this->belongsToMany(
            Barrio::class,
            'user_geographic_assignments',
            'user_id',
            'assignable_id'
        )->wherePivot('assignable_type', 'App\\Models\\Barrio')
        ->withTimestamps();
    }

    public function corregimientos()
    {
        return $this->belongsToMany(
            Corregimiento::class,
            'user_geographic_assignments',
            'user_id',
            'assignable_id'
        )->wherePivot('assignable_type', 'App\\Models\\Corregimiento')
        ->withTimestamps();
    }

    public function veredas()
    {
        return $this->belongsToMany(
            Vereda::class,
            'user_geographic_assignments',
            'user_id',
            'assignable_id'
        )->wherePivot('assignable_type', 'App\\Models\\Vereda')
        ->withTimestamps();
    }

    // ========================================
    // DEPRECATED: Single Geographic Relationships (for backward compatibility)
    // These will be removed in future versions
    // ========================================

    /**
     * @deprecated Use departments() instead
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @deprecated Use municipalities() instead
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @deprecated Use communes() instead
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @deprecated Use barrios() instead
     */
    public function barrio(): BelongsTo
    {
        return $this->belongsTo(Barrio::class);
    }

    /**
     * @deprecated Use corregimientos() instead
     */
    public function corregimiento(): BelongsTo
    {
        return $this->belongsTo(Corregimiento::class);
    }

    /**
     * @deprecated Use veredas() instead
     */
    public function vereda(): BelongsTo
    {
        return $this->belongsTo(Vereda::class);
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
