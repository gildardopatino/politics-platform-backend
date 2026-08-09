<?php

namespace Tests\Feature\Logistics;

use App\Models\Meeting;
use App\Models\PettyCashAdvance;
use App\Models\PettyCashFund;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Caja menor (Spec 0057, fase 2).
 *
 * La regla que sostiene todo lo demás:
 *
 *     saldo = Σ reposiciones − Σ anticipos + Σ reintegros
 *
 * La legalización **no** entra en esa suma: cuando alguien explica en qué gastó,
 * el dinero ya había salido del fondo. Es el punto que se presta a confusión y
 * por eso tiene su propia prueba.
 */
class PettyCashTest extends TestCase
{
    private User $usuario;

    private Tenant $tenant;

    private function autenticado(array $permisos = [
        Permissions::VIEW_PETTY_CASH,
        Permissions::MANAGE_PETTY_CASH,
    ]): void
    {
        [$user, $token] = $this->createTenantWithUser($permisos);
        $this->actingAsTenantUser($user, $token);
        $this->usuario = $user;
        $this->tenant = $user->tenant;
    }

    private function fondoCon(float $saldo = 1000000): PettyCashFund
    {
        $respuesta = $this->postJson('/api/v1/petty-cash-funds', [
            'name' => 'Caja de la campaña',
            'initial_balance' => $saldo,
        ])->assertCreated();

        return PettyCashFund::findOrFail($respuesta->json('data.id'));
    }

    private function anticipo(PettyCashFund $fondo, float $monto, ?Meeting $reunion = null): PettyCashAdvance
    {
        $respuesta = $this->postJson('/api/v1/petty-cash-advances', [
            'petty_cash_fund_id' => $fondo->id,
            'user_id' => $this->usuario->id,
            'meeting_id' => $reunion?->id,
            'amount' => $monto,
            'purpose' => 'Refrigerios y transporte',
        ])->assertCreated();

        return PettyCashAdvance::findOrFail($respuesta->json('data.id'));
    }

    // --- El saldo -----------------------------------------------------------

    public function test_el_fondo_nace_en_cero_y_el_saldo_inicial_es_una_reposicion(): void
    {
        $this->autenticado();

        $fondo = $this->fondoCon(500000);

        $this->assertEquals(500000, $fondo->balance);
        // El saldo inicial no se escribe a mano: deja su movimiento, para que la
        // cifra siempre tenga una historia detrás.
        $this->assertDatabaseHas('petty_cash_movements', [
            'petty_cash_fund_id' => $fondo->id,
            'type' => 'reposicion',
            'amount' => 500000,
        ]);
    }

    public function test_el_saldo_sigue_los_cuatro_movimientos(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);

        // Reposición: +200.000 → 1.200.000
        $this->postJson("/api/v1/petty-cash-funds/{$fondo->id}/replenish", ['amount' => 200000])->assertOk();
        $this->assertEquals(1200000, $fondo->fresh()->balance);

        // Anticipo: −300.000 → 900.000
        $anticipo = $this->anticipo($fondo, 300000);
        $this->assertEquals(900000, $fondo->fresh()->balance);

        // Legalización con sobrante: solo el reintegro vuelve → 900.000 + 50.000
        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [['description' => 'Refrigerios', 'amount' => 250000, 'has_receipt' => true]],
            'amount_returned' => 50000,
        ])->assertOk();

        $this->assertEquals(950000, $fondo->fresh()->balance);
        $this->assertEquals($fondo->fresh()->balance, $fondo->fresh()->balance_from_movements);
    }

    public function test_legalizar_sin_sobrante_no_mueve_el_saldo(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);
        $anticipo = $this->anticipo($fondo, 300000);
        $saldoTrasAnticipo = (float) $fondo->fresh()->balance;

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [['description' => 'Transporte', 'amount' => 300000]],
        ])->assertOk();

        // El dinero salió del fondo el día del anticipo; explicar en qué se fue
        // no lo devuelve ni lo vuelve a sacar.
        $this->assertEquals($saldoTrasAnticipo, $fondo->fresh()->balance);
    }

    public function test_no_se_puede_anticipar_mas_de_lo_que_hay(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(100000);

        $this->postJson('/api/v1/petty-cash-advances', [
            'petty_cash_fund_id' => $fondo->id,
            'user_id' => $this->usuario->id,
            'amount' => 150000,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertEquals(100000, $fondo->fresh()->balance);
        $this->assertDatabaseCount('petty_cash_advances', 0);
    }

    public function test_el_saldo_no_se_puede_fijar_a_mano(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(100000);

        $this->postJson('/api/v1/petty-cash-funds', [
            'name' => 'Caja trucada',
            'balance' => 999999999,
        ])->assertCreated();

        $this->putJson("/api/v1/petty-cash-funds/{$fondo->id}", ['balance' => 999999999])->assertOk();

        $this->assertEquals(0, PettyCashFund::where('name', 'Caja trucada')->first()->balance);
        $this->assertEquals(100000, $fondo->fresh()->balance);
    }

    public function test_un_monto_de_cero_o_negativo_se_rechaza(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(100000);

        $this->postJson("/api/v1/petty-cash-funds/{$fondo->id}/replenish", ['amount' => 0])->assertStatus(422);
        $this->postJson("/api/v1/petty-cash-funds/{$fondo->id}/replenish", ['amount' => -100])->assertStatus(422);
        $this->assertEquals(100000, $fondo->fresh()->balance);
    }

    // --- La legalización ----------------------------------------------------

    public function test_la_legalizacion_tiene_que_cuadrar(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);
        $anticipo = $this->anticipo($fondo, 300000);

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [['description' => 'Refrigerios', 'amount' => 100000]],
            'amount_returned' => 50000,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame('pendiente_legalizar', $anticipo->fresh()->status);
        $this->assertEquals(700000, $fondo->fresh()->balance, 'nada se movió');
    }

    public function test_una_linea_sin_comprobante_no_bloquea(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);
        $anticipo = $this->anticipo($fondo, 200000);

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [
                ['description' => 'Refrigerios', 'amount' => 120000, 'has_receipt' => true, 'receipt_ref' => 'F-0012'],
                ['description' => 'Transporte mototaxi', 'amount' => 80000, 'has_receipt' => false],
            ],
        ])->assertOk();

        $anticipo->refresh();
        $this->assertSame('legalizado', $anticipo->status);
        $this->assertEquals(200000, $anticipo->amount_spent);
        $this->assertCount(2, $anticipo->expenseLines);
        $this->assertFalse($anticipo->expenseLines->firstWhere('description', 'Transporte mototaxi')->has_receipt);
    }

    public function test_castigar_registra_el_gasto_sin_comprobante(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);
        $anticipo = $this->anticipo($fondo, 150000);
        $saldo = (float) $fondo->fresh()->balance;

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/charge-off", [
            'notes' => 'No se comprobó ni volvió',
        ])->assertOk();

        $anticipo->refresh();
        $this->assertSame('castigado', $anticipo->status);
        $this->assertEquals(150000, $anticipo->amount_charged_off);
        $this->assertEquals(0, $anticipo->amount_outstanding, 'el anticipo queda cuadrado');
        $this->assertNotNull($anticipo->settled_at);
        // Castigar no devuelve dinero al fondo: se dio por gastado.
        $this->assertEquals($saldo, $fondo->fresh()->balance);
    }

    public function test_una_legalizacion_mixta_puede_castigar_una_parte(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);
        $anticipo = $this->anticipo($fondo, 300000);

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [['description' => 'Refrigerios', 'amount' => 200000, 'has_receipt' => true]],
            'amount_returned' => 50000,
            'amount_charged_off' => 50000,
        ])->assertOk();

        $anticipo->refresh();
        $this->assertSame('legalizado', $anticipo->status);
        $this->assertEquals(200000, $anticipo->amount_spent);
        $this->assertEquals(50000, $anticipo->amount_returned);
        $this->assertEquals(50000, $anticipo->amount_charged_off);
    }

    public function test_un_anticipo_no_se_cierra_dos_veces(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(1000000);
        $anticipo = $this->anticipo($fondo, 100000);

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [],
            'amount_returned' => 100000,
        ])->assertOk();

        $saldo = (float) $fondo->fresh()->balance;

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/settle", [
            'expense_lines' => [],
            'amount_returned' => 100000,
        ])->assertStatus(422);

        $this->assertEquals($saldo, $fondo->fresh()->balance, 'el reintegro no se cobra dos veces');
    }

    // --- Consultas para el informe -------------------------------------------

    public function test_el_resumen_dice_cuanto_se_entrego_legalizo_y_castigo(): void
    {
        $this->autenticado();
        $reunion = Meeting::factory()->create(['tenant_id' => $this->tenant->id]);
        $fondo = $this->fondoCon(2000000);

        $legalizado = $this->anticipo($fondo, 300000, $reunion);
        $this->postJson("/api/v1/petty-cash-advances/{$legalizado->id}/settle", [
            'expense_lines' => [['description' => 'Refrigerios', 'amount' => 250000]],
            'amount_returned' => 50000,
        ])->assertOk();

        $castigado = $this->anticipo($fondo, 100000, $reunion);
        $this->postJson("/api/v1/petty-cash-advances/{$castigado->id}/charge-off")->assertOk();

        $this->anticipo($fondo, 70000);

        $respuesta = $this->getJson('/api/v1/petty-cash-advances')->assertOk();

        $this->assertEquals(470000, $respuesta->json('summary.total_advanced'));
        $this->assertEquals(250000, $respuesta->json('summary.total_spent'));
        $this->assertEquals(50000, $respuesta->json('summary.total_returned'));
        $this->assertEquals(100000, $respuesta->json('summary.total_charged_off'));
        $this->assertEquals(70000, $respuesta->json('summary.total_pending'), 'lo que sigue sin legalizar');

        $porReunion = $this->getJson("/api/v1/petty-cash-advances?filter[meeting_id]={$reunion->id}")->assertOk();
        $this->assertCount(2, $porReunion->json('data'));

        $pendientes = $this->getJson('/api/v1/petty-cash-advances?filter[status]=pendiente_legalizar')->assertOk();
        $this->assertCount(1, $pendientes->json('data'));
    }

    // --- Tenant y permisos ---------------------------------------------------

    public function test_no_se_ve_ni_se_toca_la_caja_de_otra_campana(): void
    {
        $this->autenticado();
        $otro = Tenant::factory()->create();
        $ajenoUser = User::factory()->forTenant($otro)->create();
        $fondoAjeno = PettyCashFund::create([
            'tenant_id' => $otro->id,
            'name' => 'Caja ajena',
        ]);
        // `balance` no es asignable en masa a propósito (solo lo mueve el
        // servicio), así que el fixture lo pone por asignación directa.
        $fondoAjeno->balance = 500000;
        $fondoAjeno->save();
        $anticipoAjeno = PettyCashAdvance::create([
            'tenant_id' => $otro->id,
            'petty_cash_fund_id' => $fondoAjeno->id,
            'user_id' => $ajenoUser->id,
            'amount' => 100000,
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/petty-cash-funds')->assertOk()->json('data'));
        $this->getJson("/api/v1/petty-cash-funds/{$fondoAjeno->id}")->assertNotFound();
        $this->putJson("/api/v1/petty-cash-funds/{$fondoAjeno->id}", ['name' => 'Secuestrada'])->assertNotFound();
        $this->postJson("/api/v1/petty-cash-funds/{$fondoAjeno->id}/replenish", ['amount' => 1])->assertNotFound();
        $this->getJson("/api/v1/petty-cash-advances/{$anticipoAjeno->id}")->assertNotFound();
        $this->postJson("/api/v1/petty-cash-advances/{$anticipoAjeno->id}/charge-off")->assertNotFound();

        $this->postJson('/api/v1/petty-cash-advances', [
            'petty_cash_fund_id' => $fondoAjeno->id,
            'user_id' => $this->usuario->id,
            'amount' => 1000,
        ])->assertNotFound();

        $this->assertEquals(500000, $fondoAjeno->fresh()->balance);
    }

    public function test_ver_la_caja_no_alcanza_para_mover_dinero(): void
    {
        $this->autenticado([Permissions::VIEW_PETTY_CASH]);
        $fondo = PettyCashFund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Caja de la campaña',
            'balance' => 100000,
        ]);

        $this->getJson('/api/v1/petty-cash-funds')->assertOk();
        $this->getJson('/api/v1/petty-cash-advances')->assertOk();

        $this->postJson('/api/v1/petty-cash-funds', ['name' => 'Otra'])->assertForbidden();
        $this->postJson("/api/v1/petty-cash-funds/{$fondo->id}/replenish", ['amount' => 1000])->assertForbidden();
        $this->postJson('/api/v1/petty-cash-advances', [
            'petty_cash_fund_id' => $fondo->id,
            'user_id' => $this->usuario->id,
            'amount' => 1000,
        ])->assertForbidden();
    }

    public function test_quien_administra_recursos_no_administra_la_caja(): void
    {
        // Mover sillas y mover dinero son permisos distintos a propósito.
        $this->autenticado([Permissions::VIEW_RESOURCES, Permissions::EDIT_RESOURCES]);

        $this->getJson('/api/v1/petty-cash-funds')->assertForbidden();
        $this->postJson('/api/v1/petty-cash-funds', ['name' => 'Caja'])->assertForbidden();
    }

    public function test_sin_sesion_la_caja_no_responde(): void
    {
        $this->getJson('/api/v1/petty-cash-funds')->assertUnauthorized();
        $this->postJson('/api/v1/petty-cash-advances', [])->assertUnauthorized();
    }

    public function test_no_se_borra_un_fondo_con_anticipos_abiertos(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(500000);
        $anticipo = $this->anticipo($fondo, 100000);

        $this->deleteJson("/api/v1/petty-cash-funds/{$fondo->id}")->assertStatus(422);

        $this->postJson("/api/v1/petty-cash-advances/{$anticipo->id}/charge-off")->assertOk();
        $this->deleteJson("/api/v1/petty-cash-funds/{$fondo->id}")->assertOk();
    }

    public function test_lo_que_toca_dinero_es_auditable(): void
    {
        $this->autenticado();
        $fondo = $this->fondoCon(500000);
        $anticipo = $this->anticipo($fondo, 100000);

        // Dinero que se mueve sin dejar quién lo movió no sirve de nada. La
        // comprobación va sobre `toAudit()` y no sobre la tabla `audits` porque
        // en el entorno de pruebas la auditoría no persiste —tampoco para los
        // modelos que ya existían—; es la misma forma que usa la 0013.
        $this->assertInstanceOf(\OwenIt\Auditing\Contracts\Auditable::class, $fondo);
        $this->assertInstanceOf(\OwenIt\Auditing\Contracts\Auditable::class, $anticipo);

        $anticipo->setAuditEvent('created');
        $registro = $anticipo->toAudit()['new_values'];

        $this->assertArrayHasKey('amount', $registro);
        $this->assertArrayHasKey('status', $registro);
        $this->assertArrayHasKey('user_id', $registro);

        $fondo->setAuditEvent('updated');
        $this->assertIsArray($fondo->toAudit());
    }
}
