<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingEvento extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'titulo',
        'fecha',
        'hora',
        'lugar',
        'descripcion',
        'imagen',
        'tipo',
        'is_active',
    ];

    protected $casts = [
        'fecha' => 'date',
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
     * Get the tenant that owns the evento.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
