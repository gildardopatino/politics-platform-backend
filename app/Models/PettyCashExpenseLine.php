<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea de la legalización: en qué se fue el dinero (Spec 0057).
 *
 * `has_receipt` es informativo, no una barrera: una línea sin comprobante se
 * acepta igual. Exigir recibos que no van a existir solo consigue que nadie
 * legalice.
 */
class PettyCashExpenseLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'petty_cash_advance_id',
        'description',
        'amount',
        'has_receipt',
        'receipt_ref',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'has_receipt' => 'boolean',
    ];

    public function advance()
    {
        return $this->belongsTo(PettyCashAdvance::class, 'petty_cash_advance_id');
    }
}
