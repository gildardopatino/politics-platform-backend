<?php

namespace Tests\Feature\Logistics;

use App\Models\ResourceAllocation;
use App\Models\ResourceItem;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Reparación del flujo base (Spec 0057, fase 0).
 *
 * La 0056 dejó por escrito que el módulo no movía inventario por API: `status`
 * no estaba en las reglas del FormRequest, así que la máquina de estados del
 * controlador era código inalcanzable (H3). Estas pruebas fijan el flujo ya
 * reparado, y de paso los cuatro arreglos que venían con él.
 */
class LogisticsBaseRepairTest extends TestCase
{
    private User $usuario;

    private function autenticado(): void
    {
        [$user, $token] = $this->createTenantWithUser([
            Permissions::VIEW_RESOURCES,
            Permissions::CREATE_RESOURCES,
            Permissions::EDIT_RESOURCES,
            Permissions::DELETE_RESOURCES,
        ]);
        $this->actingAsTenantUser($user, $token);
        $this->usuario = $user;
    }

    private function recurso(int $stock = 100): ResourceItem
    {
        return ResourceItem::factory()
            ->conStock($stock)
            ->create(['tenant_id' => $this->usuario->tenant_id, 'unit_cost' => 15000]);
    }

    private function asignacionCon(ResourceItem $recurso, float $cantidad = 10): ResourceAllocation
    {
        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'title' => 'Montaje',
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => $cantidad]],
        ])->assertCreated();

        return ResourceAllocation::findOrFail($respuesta->json('data.id'));
    }

    // --- H3: el ciclo de estados vuelve a existir -------------------------

    public function test_entregar_descuenta_el_stock_y_libera_la_reserva(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);

        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $recurso->refresh();
        $this->assertSame(90, $recurso->stock_quantity);
        $this->assertSame(0, $recurso->reserved_quantity);
        $this->assertSame('delivered', $asignacion->fresh()->items->first()->status);
    }

    public function test_devolver_reintegra_el_stock_completo(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);

        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])->assertOk();
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'returned'])->assertOk();

        $recurso->refresh();
        $this->assertSame(100, $recurso->stock_quantity);
        $this->assertSame('returned', $asignacion->fresh()->items->first()->status);
    }

    public function test_cancelar_una_pendiente_libera_la_reserva(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);

        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'cancelled'])->assertOk();

        $recurso->refresh();
        $this->assertSame(100, $recurso->stock_quantity);
        $this->assertSame(0, $recurso->reserved_quantity);
        $this->assertSame('cancelled', $asignacion->fresh()->items->first()->status);
    }

    public function test_un_salto_de_estado_invalido_responde_422_y_no_mueve_nada(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);

        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'returned'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'allowed_transitions']);

        $recurso->refresh();
        $this->assertSame(100, $recurso->stock_quantity, 'no se toca el stock');
        $this->assertSame(10, $recurso->reserved_quantity, 'la reserva sigue donde estaba');
        $this->assertSame('pending', $asignacion->fresh()->status);
    }

    public function test_una_asignacion_sin_items_tambien_respeta_las_transiciones(): void
    {
        $this->autenticado();
        $asignacion = ResourceAllocation::factory()
            ->paraTenant($this->usuario->tenant, $this->usuario)
            ->efectivo()
            ->create();

        // Antes la máquina de estados solo se evaluaba si había ítems (H9), así
        // que una entrega de dinero podía saltar a cualquier estado.
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'returned'])
            ->assertStatus(422);

        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])
            ->assertOk();

        $this->assertSame('delivered', $asignacion->fresh()->status);
    }

    public function test_repetir_el_mismo_estado_no_vuelve_a_mover_el_stock(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);

        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])->assertOk();
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])->assertOk();

        $this->assertSame(90, $recurso->fresh()->stock_quantity, 'no se descuenta dos veces');
    }

    // --- H5: el propósito del efectivo ------------------------------------

    public function test_el_proposito_del_efectivo_se_guarda_y_se_edita(): void
    {
        $this->autenticado();

        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'type' => 'cash',
            'amount' => 200000,
            'cash_purpose' => 'Refrigerios y transporte',
        ])->assertCreated();

        $id = $respuesta->json('data.id');
        $this->assertSame('Refrigerios y transporte', ResourceAllocation::find($id)->cash_purpose);

        $this->putJson("/api/v1/resource-allocations/{$id}", ['cash_purpose' => 'Solo transporte'])->assertOk();
        $this->assertSame('Solo transporte', ResourceAllocation::find($id)->cash_purpose);
    }

    // --- H7: el rastreo de inventario -------------------------------------

    public function test_el_efectivo_del_catalogo_nace_sin_rastreo_de_inventario(): void
    {
        $this->autenticado();

        $respuesta = $this->postJson('/api/v1/resource-items', [
            'name' => 'Efectivo para gastos',
            'category' => 'cash',
            'unit' => 'COP',
            'unit_cost' => 1,
        ])->assertCreated();

        // El dinero se gasta, no se devuelve: la categoría manda, sin depender
        // de que quien llame se acuerde de mandar el flag.
        $this->assertFalse($respuesta->json('data.is_inventory_tracked'));
    }

    public function test_el_rastreo_se_puede_fijar_y_corregir_por_api(): void
    {
        $this->autenticado();

        $respuesta = $this->postJson('/api/v1/resource-items', [
            'name' => 'Servicio de sonido',
            'category' => 'service',
            'unit' => 'jornada',
            'unit_cost' => 300000,
            'is_inventory_tracked' => false,
        ])->assertCreated();

        $this->assertFalse($respuesta->json('data.is_inventory_tracked'));

        $this->putJson("/api/v1/resource-items/{$respuesta->json('data.id')}", [
            'is_inventory_tracked' => true,
        ])->assertOk()->assertJsonPath('data.is_inventory_tracked', true);
    }

    // --- H8: el PUT de ítem --------------------------------------------------

    public function test_subir_la_cantidad_de_un_item_reajusta_la_reserva(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);
        $item = $asignacion->items->first();

        $this->putJson("/api/v1/resource-allocation-items/{$item->id}", ['quantity' => 40])->assertOk();

        $recurso->refresh();
        $this->assertSame(40, $recurso->reserved_quantity);
        $this->assertEquals(600000, $asignacion->fresh()->total_cost);
    }

    public function test_bajar_la_cantidad_de_un_item_devuelve_reserva(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);
        $item = $asignacion->items->first();

        $this->putJson("/api/v1/resource-allocation-items/{$item->id}", ['quantity' => 3])->assertOk();

        $this->assertSame(3, $recurso->fresh()->reserved_quantity);
    }

    public function test_no_se_puede_subir_la_cantidad_por_encima_del_disponible(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(20);
        $asignacion = $this->asignacionCon($recurso, 10);
        $item = $asignacion->items->first();

        $this->putJson("/api/v1/resource-allocation-items/{$item->id}", ['quantity' => 50])
            ->assertStatus(422)
            ->assertJsonPath('available', 20);

        $this->assertSame(10, $recurso->fresh()->reserved_quantity, 'la reserva no se movió');
        $this->assertEquals(10, $item->fresh()->quantity);
    }

    public function test_editar_un_item_ya_entregado_no_toca_reservas(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        $asignacion = $this->asignacionCon($recurso, 10);
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])->assertOk();
        $item = $asignacion->fresh()->items->first();

        $this->putJson("/api/v1/resource-allocation-items/{$item->id}", ['unit_cost' => 20000])->assertOk();

        $recurso->refresh();
        $this->assertSame(0, $recurso->reserved_quantity);
        $this->assertSame(90, $recurso->stock_quantity);
        $this->assertEquals(200000, $asignacion->fresh()->total_cost);
    }

    // --- H18: el 500 no cuenta de más ---------------------------------------

    public function test_un_fallo_al_crear_no_filtra_el_detalle_interno(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);

        // Un ítem con un `metadata` que el modelo no puede serializar rompe el
        // guardado dentro de la transacción.
        \Illuminate\Support\Facades\Log::spy();
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "resource_allocation_items"')) {
                throw new \RuntimeException('la base se cayó en mitad del insert');
            }
        });

        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => 5]],
        ]);

        $respuesta->assertStatus(500)->assertJson(['message' => 'Error al crear la asignación de recursos']);
        $this->assertStringNotContainsString('la base se cayó', $respuesta->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error');
    }
}
