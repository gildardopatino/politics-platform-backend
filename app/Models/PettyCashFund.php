<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Fondo de caja menor: de aquí sale el dinero (Spec 0057).
 *
 * `balance` está guardado, pero **no se escribe desde fuera**: lo mantiene
 * `PettyCashService` dentro de una transacción con la fila bloqueada. Guardarlo
 * en vez de sumarlo al vuelo es lo que permite bloquear una fila concreta y que
 * dos anticipos simultáneos no gasten el mismo saldo dos veces.
 */
class PettyCashFund extends Model implements Auditable
{
    use HasFactory, HasTenant, SoftDeletes;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'responsible_user_id',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function advances()
    {
        return $this->hasMany(PettyCashAdvance::class);
    }

    public function movements()
    {
        return $this->hasMany(PettyCashMovement::class);
    }

    /**
     * El saldo recalculado desde los movimientos. No se usa para operar —para
     * eso está la columna, que es la que se bloquea— sino para comprobar que la
     * columna y su historia siguen contando lo mismo.
     */
    public function getBalanceFromMovementsAttribute(): float
    {
        return (float) $this->movements()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'anticipo' THEN -amount ELSE amount END), 0) as total")
            ->value('total');
    }
}
