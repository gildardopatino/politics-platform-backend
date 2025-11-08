<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingSocialFeed extends Model
{
    use HasFactory;

    protected $table = 'landing_social_feed';

    protected $fillable = [
        'tenant_id',
        'plataforma',
        'usuario',
        'contenido',
        'fecha',
        'likes',
        'compartidos',
        'comentarios',
        'imagen',
        'external_id',
        'external_url',
        'last_synced_at',
        'is_synced',
        'is_active',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'likes' => 'integer',
        'compartidos' => 'integer',
        'comentarios' => 'integer',
        'last_synced_at' => 'datetime',
        'is_synced' => 'boolean',
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
     * Get the tenant that owns the social feed post.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
