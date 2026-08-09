<?php

namespace App\Services\Logistics;

use App\Models\PettyCashAdvance;
use App\Models\PettyCashFund;
use App\Models\PettyCashMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caja menor: fondo, anticipos y cierre (Spec 0057).
 *
 * La regla del saldo es una sola y conviene tenerla a la vista:
 *
 *     saldo = Σ reposiciones − Σ anticipos + Σ reintegros
 *
 * La **legalización no aparece en esa suma**. Es la parte que se presta a
 * confusión: cuando alguien explica en qué gastó el dinero, no está moviendo
 * nada — el dinero salió del fondo el día que se le entregó. Legalizar solo
 * clasifica lo que ya salió (gastado / devuelto / castigado). Lo único que
 * vuelve al fondo es el sobrante.
 *
 * Todo lo que toca el saldo pasa por `DB::transaction` con `lockForUpdate` sobre
 * la fila del fondo: dos anticipos simultáneos no pueden gastar el mismo saldo
 * dos veces.
 */
class PettyCashService
{
    /** Entra dinero al fondo. */
    public function replenish(PettyCashFund $fund, float $amount, ?string $notes = null): PettyCashFund
    {
        $this->exigirPositivo($amount);

        return DB::transaction(function () use ($fund, $amount, $notes) {
            $bloqueado = $this->bloquear($fund);

            $saldo = (float) $bloqueado->balance + $amount;
            $this->fijarSaldo($bloqueado, $saldo);

            $this->registrar($bloqueado, PettyCashMovement::REPOSICION, $amount, $saldo, null, $notes);

            return $bloqueado->fresh();
        });
    }

    /**
     * Sale dinero del fondo hacia una persona. El anticipo nace abierto:
     * `pendiente_legalizar`.
     *
     * @param  array<string, mixed>  $datos
     */
    public function advance(PettyCashFund $fund, array $datos): PettyCashAdvance
    {
        $amount = (float) $datos['amount'];
        $this->exigirPositivo($amount);

        return DB::transaction(function () use ($fund, $datos, $amount) {
            $bloqueado = $this->bloquear($fund);

            if ($amount > (float) $bloqueado->balance) {
                $this->rechazar('amount', "El anticipo supera el saldo del fondo ({$this->dinero($bloqueado->balance)}).");
            }

            $saldo = (float) $bloqueado->balance - $amount;
            $this->fijarSaldo($bloqueado, $saldo);

            $anticipo = PettyCashAdvance::create([
                'tenant_id' => $bloqueado->tenant_id,
                'petty_cash_fund_id' => $bloqueado->id,
                'user_id' => $datos['user_id'],
                'meeting_id' => $datos['meeting_id'] ?? null,
                'created_by_user_id' => auth()->id(),
                'amount' => $amount,
                'status' => PettyCashAdvance::PENDIENTE,
                'purpose' => $datos['purpose'] ?? null,
                'notes' => $datos['notes'] ?? null,
            ]);

            $this->registrar($bloqueado, PettyCashMovement::ANTICIPO, $amount, $saldo, $anticipo->id, $datos['purpose'] ?? null);

            return $anticipo->fresh(['fund', 'user']);
        });
    }

    /**
     * Cierra el anticipo. Exige cuadre exacto:
     * `gastado + devuelto + castigado = entregado`.
     *
     * Las líneas de gasto pueden ir sin comprobante y no bloquean: lo que no se
     * puede es dejar dinero sin explicar. Si algo no se comprobó ni volvió, va
     * como `charged_off` y el anticipo queda `castigado` — visible, que es de lo
     * que se trata.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function settle(PettyCashAdvance $advance, array $lineas, float $returned, float $chargedOff, ?string $notes = null): PettyCashAdvance
    {
        if ($advance->status !== PettyCashAdvance::PENDIENTE) {
            $this->rechazar('status', 'Este anticipo ya se cerró.');
        }

        $gastado = round(array_sum(array_map(fn ($linea) => (float) $linea['amount'], $lineas)), 2);
        $entregado = round((float) $advance->amount, 2);
        $declarado = round($gastado + $returned + $chargedOff, 2);

        if ($returned < 0 || $chargedOff < 0) {
            $this->rechazar('amount', 'Los montos de la legalización no pueden ser negativos.');
        }

        if (abs($declarado - $entregado) > 0.001) {
            $this->rechazar(
                'amount',
                "La legalización no cuadra: se entregaron {$this->dinero($entregado)} y se declararon {$this->dinero($declarado)}."
            );
        }

        return DB::transaction(function () use ($advance, $lineas, $gastado, $returned, $chargedOff, $notes) {
            $fondo = $this->bloquear($advance->fund);

            // El sobrante es lo único que vuelve al fondo: lo gastado y lo
            // castigado ya no están.
            if ($returned > 0) {
                $saldo = (float) $fondo->balance + $returned;
                $this->fijarSaldo($fondo, $saldo);
                $this->registrar($fondo, PettyCashMovement::REINTEGRO, $returned, $saldo, $advance->id, 'Sobrante devuelto');
            }

            $advance->expenseLines()->delete();

            foreach ($lineas as $linea) {
                $advance->expenseLines()->create([
                    'description' => $linea['description'],
                    'amount' => $linea['amount'],
                    'has_receipt' => (bool) ($linea['has_receipt'] ?? false),
                    'receipt_ref' => $linea['receipt_ref'] ?? null,
                ]);
            }

            $advance->update([
                'amount_spent' => $gastado,
                'amount_returned' => $returned,
                'amount_charged_off' => $chargedOff,
                // Un anticipo del que nada se comprobó es un castigo; si algo se
                // explicó, cuenta como legalizado aunque parte se castigue.
                'status' => $chargedOff > 0 && $gastado <= 0 && $returned <= 0
                    ? PettyCashAdvance::CASTIGADO
                    : PettyCashAdvance::LEGALIZADO,
                'settled_at' => now(),
                'notes' => $notes ?? $advance->notes,
            ]);

            return $advance->fresh(['expenseLines', 'fund', 'user']);
        });
    }

    /**
     * Atajo del caso más común y más incómodo de contar: no se comprobó nada y
     * no volvió nada.
     */
    public function chargeOff(PettyCashAdvance $advance, ?string $notes = null): PettyCashAdvance
    {
        return $this->settle($advance, [], 0.0, (float) $advance->amount, $notes);
    }

    /**
     * `balance` está fuera del `$fillable` del modelo a propósito: así ningún
     * controlador puede fijarlo con un `update()` desde el cuerpo de la
     * petición. El servicio lo escribe por asignación directa, que es el único
     * camino que debe existir.
     */
    private function fijarSaldo(PettyCashFund $fund, float $saldo): void
    {
        $fund->balance = $saldo;
        $fund->save();
    }

    /** Bloquea la fila del fondo para el resto de la transacción. */
    private function bloquear(PettyCashFund $fund): PettyCashFund
    {
        return PettyCashFund::whereKey($fund->id)->lockForUpdate()->firstOrFail();
    }

    private function registrar(PettyCashFund $fund, string $tipo, float $monto, float $saldo, ?int $anticipoId, ?string $notas): void
    {
        PettyCashMovement::create([
            'tenant_id' => $fund->tenant_id,
            'petty_cash_fund_id' => $fund->id,
            'petty_cash_advance_id' => $anticipoId,
            'type' => $tipo,
            'amount' => $monto,
            'balance_after' => $saldo,
            'notes' => $notas,
            'created_by_user_id' => auth()->id(),
        ]);
    }

    private function exigirPositivo(float $amount): void
    {
        if ($amount <= 0) {
            $this->rechazar('amount', 'El monto debe ser mayor que cero.');
        }
    }

    private function dinero(float $valor): string
    {
        return '$'.number_format($valor, 0, ',', '.');
    }

    private function rechazar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje])->status(422);
    }
}
