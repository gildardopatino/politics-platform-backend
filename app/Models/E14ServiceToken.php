<?php

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de servicio del lector de actas (Spec 0061 · Parte A).
 *
 * En la tabla solo vive el SHA-256 del token. El valor en claro se entrega una
 * vez al generarlo; si se pierde, se rota. `token_hash` está oculto de la
 * serialización para que no salga por accidente en una respuesta.
 */
class E14ServiceToken extends Model
{
    use HasFactory, HasTenant;

    /**
     * Prefijo del valor en claro. Sirve para dos cosas: distinguir de un vistazo
     * un token de servicio de un JWT en el `Authorization`, y permitir que un
     * escáner de secretos lo reconozca si se filtra a un repositorio.
     */
    public const PREFIJO = 'e14_';

    protected $table = 'e14_service_tokens';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'nombre',
        'token_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hash con el que se guarda y se busca un token.
     *
     * SHA-256 a secas y no bcrypt: el token es una cadena aleatoria de 192 bits,
     * no una contraseña que alguien pueda adivinar, y la búsqueda tiene que ser
     * por índice y no recorriendo la tabla.
     */
    public static function hashDe(string $valor): string
    {
        return hash('sha256', $valor);
    }

    /**
     * Busca el token activo que corresponde a un valor en claro.
     *
     * Sin el scope de tenant: en el momento de autenticar todavía no hay tenant
     * enlazado — es justo lo que este token va a resolver.
     */
    public static function porValor(string $valor): ?self
    {
        if (! str_starts_with($valor, self::PREFIJO)) {
            return null;
        }

        return self::withoutGlobalScope(TenantScope::class)
            ->whereNull('revoked_at')
            ->where('token_hash', self::hashDe($valor))
            ->first();
    }
}
