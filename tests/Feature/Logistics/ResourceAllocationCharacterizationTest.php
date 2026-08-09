<?php

namespace Tests\Feature\Logistics;

use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use App\Models\ResourceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Asignaciones de inventario — caracterización (Spec 0056).
 *
 * El doc viejo describe un ciclo `pending → delivered → returned` que mueve
 * stock. Aquí se comprueba qué parte de eso ocurre de verdad **por la API**, que
 * es lo único que el panel puede hacer.
 */
class ResourceAllocationCharacterizationTest extends TestCase
{
    private User $usuario;

    private Tenant $tenant;

    private function autenticado(array $permisos = [
        Permissions::VIEW_RESOURCES,
        Permissions::CREATE_RESOURCES,
        Permissions::EDIT_RESOURCES,
        Permissions::DELETE_RESOURCES,
    ]): void
    {
        [$user, $token] = $this->createTenantWithUser($permisos);
        $this->actingAsTenantUser($user, $token);
        $this->usuario = $user;
        $this->tenant = $user->tenant;
    }

    private function recurso(int $stock = 100, int $reservado = 0): ResourceItem
    {
        return ResourceItem::factory()
            ->conStock($stock, $reservado)
            ->create(['tenant_id' => $this->tenant->id, 'unit_cost' => 15000]);
    }

    private function crearAsignacion(ResourceItem $recurso, float $cantidad = 10): array
    {
        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'title' => 'Montaje',
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => $cantidad]],
        ]);

        return [$respuesta, ResourceAllocation::find($respuesta->json('data.id'))];
    }

    public function test_crear_reserva_el_stock_sin_descontarlo(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);

        [$respuesta] = $this->crearAsignacion($recurso, 10);

        $respuesta->assertCreated()->assertJsonPath('data.status', 'pending');
        $recurso->refresh();
        $this->assertSame(100, $recurso->stock_quantity, 'el stock no baja hasta entregar');
        $this->assertSame(10, $recurso->reserved_quantity);
        $this->assertSame(90, $recurso->available_quantity);
    }

    public function test_crear_calcula_el_total_desde_el_catalogo(): void
    {
        $this->autenticado();
        $recurso = $this->recurso();

        [$respuesta, $asignacion] = $this->crearAsignacion($recurso, 4);

        // El costo unitario se congela en el ítem al momento de asignar.
        $respuesta->assertCreated();
        $this->assertEquals(60000, $asignacion->total_cost);
        $this->assertEquals(15000, $asignacion->items->first()->unit_cost);
    }

    public function test_sin_stock_suficiente_responde_422_con_el_detalle(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(5);

        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => 10]],
        ])
            ->assertStatus(422)
            ->assertJson([
                'resource' => $recurso->name,
                'requested' => 10,
                'available' => 5,
                'in_stock' => 5,
                'reserved' => 0,
            ]);

        $this->assertSame(0, $recurso->fresh()->reserved_quantity);
        $this->assertDatabaseCount('resource_allocations', 0);
    }

    public function test_un_recurso_de_otro_tenant_no_se_puede_asignar(): void
    {
        $this->autenticado();
        $ajeno = ResourceItem::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        // La validación `exists:resource_items,id` sí lo encuentra (no filtra por
        // tenant), pero el `ResourceItem::find` del controlador pasa por el
        // TenantScope y no lo ve: el aislamiento lo salva el scope, no la regla.
        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $ajeno->id, 'quantity' => 1]],
        ])->assertNotFound()->assertJsonPath('resource_item_id', $ajeno->id);
    }

    public function test_el_put_no_deja_cambiar_el_estado_asi_que_el_ciclo_de_inventario_no_corre(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        [, $asignacion] = $this->crearAsignacion($recurso, 10);

        $respuesta = $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", [
            'status' => 'delivered',
        ]);

        // HALLAZGO (🔴 funcional, NO se corrige aquí — es el eje de la 0057):
        // `UpdateResourceAllocationRequest` no incluye `status` en sus reglas,
        // así que `validated()` lo descarta y `$newStatus` acaba siendo el
        // estado viejo. La máquina de estados del controlador —descontar al
        // entregar, reintegrar al devolver, liberar al cancelar— es código
        // inalcanzable desde la API. Responde 200 y no hace nada.
        $respuesta->assertOk();
        $this->assertSame('pending', $asignacion->fresh()->status);

        $recurso->refresh();
        $this->assertSame(100, $recurso->stock_quantity, 'no se descuenta nada');
        $this->assertSame(10, $recurso->reserved_quantity, 'la reserva sigue viva');
    }

    public function test_el_ciclo_de_estados_del_controlador_solo_funciona_por_dentro(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        [, $asignacion] = $this->crearAsignacion($recurso, 10);

        // Lo que el doc describe, ejercido saltándose el FormRequest: es la única
        // forma de ver el comportamiento que la 0057 va a rediseñar.
        $this->entregarPorDentro($asignacion);

        $recurso->refresh();
        $this->assertSame(90, $recurso->stock_quantity, 'entregar descuenta');
        $this->assertSame(0, $recurso->reserved_quantity, 'y libera la reserva');
        $this->assertSame('delivered', $asignacion->fresh()->items->first()->status);

        $this->devolverPorDentro($asignacion);

        $recurso->refresh();
        $this->assertSame(100, $recurso->stock_quantity, 'devolver reintegra todo');
        $this->assertSame('returned', $asignacion->fresh()->items->first()->status);
    }

    public function test_borrar_una_asignacion_pendiente_libera_la_reserva(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        [, $asignacion] = $this->crearAsignacion($recurso, 10);

        $this->deleteJson("/api/v1/resource-allocations/{$asignacion->id}")->assertOk();

        $this->assertSame(0, $recurso->fresh()->reserved_quantity);
        $this->assertSoftDeleted('resource_allocations', ['id' => $asignacion->id]);
    }

    public function test_borrar_una_asignacion_entregada_deja_el_stock_descontado(): void
    {
        $this->autenticado();
        $recurso = $this->recurso(100);
        [, $asignacion] = $this->crearAsignacion($recurso, 10);
        $this->entregarPorDentro($asignacion);

        $this->deleteJson("/api/v1/resource-allocations/{$asignacion->id}")->assertOk();

        // HALLAZGO (🟠): borrar lo entregado no reintegra —correcto, salió del
        // almacén— pero tampoco deja rastro de que esas 10 unidades siguen
        // fuera: desaparece el único registro que decía quién las tiene. → 0057.
        $this->assertSame(90, $recurso->fresh()->stock_quantity);
        $this->assertSoftDeleted('resource_allocations', ['id' => $asignacion->id]);
    }

    public function test_una_asignacion_de_otro_tenant_no_se_ve_ni_se_toca(): void
    {
        $this->autenticado();
        $otro = Tenant::factory()->create();
        $ajena = ResourceAllocation::factory()->paraTenant($otro)->create();

        $this->getJson("/api/v1/resource-allocations/{$ajena->id}")->assertNotFound();
        $this->putJson("/api/v1/resource-allocations/{$ajena->id}", ['amount' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/resource-allocations/{$ajena->id}")->assertNotFound();

        $nombres = collect($this->getJson('/api/v1/resource-allocations')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($ajena->id, $nombres);
    }

    public function test_by_meeting_resume_lo_asignado_a_una_reunion(): void
    {
        $this->autenticado();
        $reunion = Meeting::factory()->create(['tenant_id' => $this->tenant->id]);
        $recurso = $this->recurso();

        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'meeting_id' => $reunion->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => 4]],
        ])->assertCreated();

        $respuesta = $this->getJson("/api/v1/resource-allocations/by-meeting/{$reunion->id}")->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertEquals(60000, $respuesta->json('summary.total_cost'));
        $this->assertEquals(60000, $respuesta->json('summary.grand_total'));
    }

    public function test_by_leader_resume_lo_entregado_a_una_persona(): void
    {
        $this->autenticado();
        $recurso = $this->recurso();
        $this->crearAsignacion($recurso, 2);

        $respuesta = $this->getJson("/api/v1/resource-allocations/by-leader/{$this->usuario->id}")->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertEquals(30000, $respuesta->json('summary.total_cost'));
    }

    public function test_by_leader_de_un_usuario_de_otro_tenant_no_devuelve_sus_asignaciones(): void
    {
        $this->autenticado();
        $otro = Tenant::factory()->create();
        $ajeno = User::factory()->forTenant($otro)->create();
        ResourceAllocation::factory()->paraTenant($otro, $ajeno)->create();

        // El binding del `User` también pasa por el TenantScope, así que el
        // usuario ajeno ni siquiera se resuelve: 404 antes de consultar nada.
        $this->getJson("/api/v1/resource-allocations/by-leader/{$ajeno->id}")->assertNotFound();
    }

    /** Entrega saltándose el FormRequest, que es lo que hoy bloquea el ciclo. */
    private function entregarPorDentro(ResourceAllocation $asignacion): void
    {
        foreach ($asignacion->items as $item) {
            $recurso = $item->resourceItem;
            $recurso->releaseReservedStock($item->quantity);
            $recurso->decreaseStock($item->quantity);
            $item->update(['status' => 'delivered']);
        }
        $asignacion->update(['status' => 'delivered']);
    }

    private function devolverPorDentro(ResourceAllocation $asignacion): void
    {
        foreach ($asignacion->items as $item) {
            $item->resourceItem->increaseStock($item->quantity);
            $item->update(['status' => 'returned']);
        }
        $asignacion->update(['status' => 'returned']);
    }

    public function test_los_items_no_llevan_tenant_propio(): void
    {
        $this->autenticado();
        $recurso = $this->recurso();
        [, $asignacion] = $this->crearAsignacion($recurso, 3);

        // Contexto para la 0057: `ResourceAllocationItem` no usa `HasTenant` ni
        // tiene columna `tenant_id`; cuelga de la asignación. De ahí que sus
        // rutas propias no tengan aislamiento (ver el test de seguridad).
        $item = ResourceAllocationItem::first();
        $this->assertNull($item->getAttribute('tenant_id'));
        $this->assertSame($asignacion->id, $item->resource_allocation_id);
    }
}
