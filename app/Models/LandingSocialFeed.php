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
        'is_active',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'likes' => 'integer',
        'compartidos' => 'integer',
        'comentarios' => 'integer',
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
