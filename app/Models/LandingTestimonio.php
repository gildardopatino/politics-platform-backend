<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingTestimonio extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'nombre',
        'ocupacion',
        'municipio',
        'testimonio',
        'foto',
        'calificacion',
        'is_active',
    ];

    protected $casts = [
        'calificacion' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Get the tenant that owns the testimonio.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
