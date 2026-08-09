<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Anticipo: dinero entregado a una persona para una jornada (Spec 0057).
 *
 * Vive en `pendiente_legalizar` hasta que se cierra, y cierra de dos maneras:
 * `legalizado` (se explicó en qué se fue, con o sin recibo, y volvió el
 * sobrante) o `castigado` (no se comprobó ni volvió). El castigo existe a
 * propósito: es el caso real que el equipo describió, y registrarlo sin fricción
 * es lo que consigue que quede registrado en vez de desaparecer.
 *
 * Invariante al cerrar: `amount = amount_spent + amount_returned + amount_charged_off`.
 */
class PettyCashAdvance extends Model implements Auditable
{
    use HasFactory, HasTenant, SoftDeletes;
    use \OwenIt\Auditing\Auditable;

    public const PENDIENTE = 'pendiente_legalizar';

    public const LEGALIZADO = 'legalizado';

    public const CASTIGADO = 'castigado';

    protected $fillable = [
        'tenant_id',
        'petty_cash_fund_id',
        'user_id',
        'meeting_id',
        'created_by_user_id',
        'amount',
        'amount_spent',
        'amount_returned',
        'amount_charged_off',
        'status',
        'purpose',
        'notes',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_spent' => 'decimal:2',
        'amount_returned' => 'decimal:2',
        'amount_charged_off' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function fund()
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function expenseLines()
    {
        return $this->hasMany(PettyCashExpenseLine::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDIENTE);
    }

    /** Lo que sigue sin explicarse mientras el anticipo está abierto. */
    public function getAmountOutstandingAttribute(): float
    {
        return (float) $this->amount
            - (float) $this->amount_spent
            - (float) $this->amount_returned
            - (float) $this->amount_charged_off;
    }
}
