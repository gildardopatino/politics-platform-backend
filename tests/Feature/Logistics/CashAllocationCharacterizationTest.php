<?php

namespace Tests\Feature\Logistics;

use App\Models\ResourceAllocation;
use App\Models\ResourceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Efectivo — caracterización (Spec 0056, RC4).
 *
 * El contexto del dominio dice que hoy el dinero es «entregar y ya». Estas
 * pruebas comprueban hasta dónde llega ese «y ya»: qué se puede guardar, qué no,
 * y qué piezas de una caja menor no existen (que es lo que la 0057 tiene que
 * construir).
 */
class CashAllocationCharacterizationTest extends TestCase
{
    private User $usuario;

    private Tenant $tenant;

    private function autenticado(): void
    {
        [$user, $token] = $this->createTenantWithUser([
            Permissions::VIEW_RESOURCES,
            Permissions::CREATE_RESOURCES,
            Permissions::EDIT_RESOURCES,
        ]);
        $this->actingAsTenantUser($user, $token);
        $this->usuario = $user;
        $this->tenant = $user->tenant;
    }

    public function test_una_entrega_de_efectivo_se_crea_con_tipo_y_monto(): void
    {
        $this->autenticado();

        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'title' => 'Gastos de la jornada',
            'type' => 'cash',
            'amount' => 200000,
        ])->assertCreated();

        $this->assertSame('cash', $respuesta->json('data.type'));
        $this->assertEquals(200000, $respuesta->json('data.amount'));
        $this->assertSame('pending', $respuesta->json('data.status'));
    }

    public function test_el_proposito_del_efectivo_se_guarda(): void
    {
        $this->autenticado();

        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'type' => 'cash',
            'amount' => 200000,
            'cash_purpose' => 'Refrigerios y transporte de la vereda',
        ])->assertCreated();

        // Reparado por la 0057 (era el hallazgo H5): la columna y el `fillable`
        // existían, pero ningún FormRequest aceptaba el campo, así que el único
        // dato que explicaba en qué se iba el dinero se descartaba en silencio.
        $this->assertSame(
            'Refrigerios y transporte de la vereda',
            ResourceAllocation::find($respuesta->json('data.id'))->cash_purpose
        );
    }

    public function test_el_monto_del_efectivo_no_se_valida_contra_nada(): void
    {
        $this->autenticado();

        // No hay fondo, ni saldo, ni tope: cualquier monto entra mientras sea
        // un número positivo. Un cero también.
        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'type' => 'cash',
            'amount' => 999999999,
        ])->assertCreated();

        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'type' => 'cash',
            'amount' => 0,
        ])->assertCreated();

        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'type' => 'cash',
            'amount' => -5,
        ])->assertStatus(422);
    }

    public function test_la_caja_menor_ya_existe_y_vive_aparte_de_la_asignacion(): void
    {
        $this->autenticado();

        // Esta prueba nació en la 0056 comprobando la **ausencia** de la caja,
        // con la nota de que avisara el día que el hueco se cerrara. La 0057 lo
        // cerró: la caja es su propio modelo (fondo + anticipos + líneas), no
        // campos añadidos a la asignación de recursos.
        foreach (['petty_cash_funds', 'petty_cash_advances', 'petty_cash_expense_lines', 'petty_cash_movements'] as $tabla) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($tabla));
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('inventory_losses'));

        // La entrega de efectivo por `resource_allocations` sigue existiendo tal
        // cual —no se migró nada— y sigue sin tener cierre propio: quien quiera
        // controlar el dinero usa la caja.
        foreach (['returned_amount', 'settled_amount', 'settled_at', 'settlement_status'] as $columna) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('resource_allocations', $columna)
            );
        }
    }

    public function test_el_efectivo_del_catalogo_no_reserva_ni_descuenta_stock(): void
    {
        $this->autenticado();
        $efectivo = ResourceItem::factory()->cash()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $efectivo->id, 'quantity' => 200000]],
        ])->assertCreated();

        // `is_inventory_tracked = false` hace que reservar y descontar sean no-op:
        // el dinero se gasta, no se devuelve.
        $efectivo->refresh();
        $this->assertSame(0, $efectivo->reserved_quantity);
        $this->assertNull($efectivo->stock_quantity);
    }

    public function test_un_efectivo_marcado_como_inventario_queda_inasignable(): void
    {
        $this->autenticado();

        // Es el ítem que produce hoy la API (ver el hallazgo de
        // `is_inventory_tracked` en el catálogo): categoría `cash`, sin stock,
        // pero con el rastreo encendido.
        $efectivo = ResourceItem::factory()->cash()->create([
            'tenant_id' => $this->tenant->id,
            'is_inventory_tracked' => true,
        ]);

        // HALLAZGO (🟠): con rastreo y `stock_quantity` nulo, el disponible es 0
        // y el 422 de stock insuficiente bloquea cualquier entrega de dinero por
        // el camino de ítems. → 0057.
        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $efectivo->id, 'quantity' => 200000]],
        ])->assertStatus(422)->assertJsonPath('available', 0);
    }

    public function test_una_entrega_de_efectivo_no_tiene_cierre(): void
    {
        $this->autenticado();
        $asignacion = ResourceAllocation::factory()
            ->paraTenant($this->tenant, $this->usuario)
            ->efectivo(300000)
            ->create();

        // La 0057 reparó el ciclo, así que una entrega de dinero ya se puede
        // marcar como entregada. Lo que sigue sin existir es el **cierre**: no
        // hay «legalizado», ni «devuelto», ni «castigado», ni forma de decir en
        // qué se gastó. Eso es la caja menor de la fase 2.
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", [
            'status' => 'delivered',
            'amount' => 300000,
        ])->assertOk();

        $this->assertSame('delivered', $asignacion->fresh()->status);
        $this->assertContains($asignacion->fresh()->status, ['pending', 'delivered', 'returned', 'cancelled']);
    }
}
