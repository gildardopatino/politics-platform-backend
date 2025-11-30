<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Voter extends Model implements Auditable
{
    use HasFactory, SoftDeletes;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'cedula',
        'nombres',
        'apellidos',
        'email',
        'telefono',
        'direccion',
        'barrio_id',
        'corregimiento_id',
        'vereda_id',
        'meeting_id',
        'departamento_votacion',
        'municipio_votacion',
        'puesto_votacion',
        'direccion_votacion',
        'mesa_votacion',
        'voting_place_id',
        'has_multiple_records',
        'created_by',
    ];

    protected $casts = [
        'has_multiple_records' => 'boolean',
    ];

    protected $appends = [
        'full_name',
        'location_type',
    ];

    /**
     * Boot method
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Relaciones
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function barrio(): BelongsTo
    {
        return $this->belongsTo(Barrio::class);
    }

    public function corregimiento(): BelongsTo
    {
        return $this->belongsTo(Corregimiento::class);
    }

    public function vereda(): BelongsTo
    {
        return $this->belongsTo(Vereda::class);
    }

    public function votingPlace(): BelongsTo
    {
        return $this->belongsTo(VotingPlace::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /**
     * Accessors
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }

    public function getLocationTypeAttribute(): ?string
    {
        if ($this->barrio_id) return 'barrio';
        if ($this->corregimiento_id) return 'corregimiento';
        if ($this->vereda_id) return 'vereda';
        return null;
    }

    /**
     * Scopes
     */
    public function scopeWithMultipleRecords($query)
    {
        return $query->where('has_multiple_records', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('cedula', 'like', "%{$search}%")
              ->orWhere('nombres', 'like', "%{$search}%")
              ->orWhere('apellidos', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('telefono', 'like', "%{$search}%");
        });
    }
}
