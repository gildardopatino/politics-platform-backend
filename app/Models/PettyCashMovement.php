<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cada entrada y salida del fondo (Spec 0057).
 *
 * Es el libro: sin él el saldo sería un número sin historia y no habría forma de
 * explicar por qué es el que es. `balance_after` guarda el saldo tras el
 * movimiento para poder leer la secuencia sin recalcularla.
 *
 * Ojo: la **legalización no aparece aquí**, porque no mueve dinero — el dinero
 * ya salió con el anticipo. Solo el reintegro del sobrante vuelve al fondo.
 */
class PettyCashMovement extends Model
{
    use HasFactory, HasTenant;

    public const REPOSICION = 'reposicion';

    public const ANTICIPO = 'anticipo';

    public const REINTEGRO = 'reintegro';

    protected $fillable = [
        'tenant_id',
        'petty_cash_fund_id',
        'petty_cash_advance_id',
        'type',
        'amount',
        'balance_after',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function fund()
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    public function advance()
    {
        return $this->belongsTo(PettyCashAdvance::class, 'petty_cash_advance_id');
    }
}
