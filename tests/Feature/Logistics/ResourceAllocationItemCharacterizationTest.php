<?php

namespace Tests\Feature\Logistics;

use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use App\Models\ResourceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Estado por ítem — caracterización (Spec 0056, RC3).
 *
 * Es el punto central de la spec: `docs/INVENTORY_SYSTEM_EXPLAINED.md` dice que
 * la devolución es «todo o nada», pero existe
 * `PATCH /resource-allocation-items/{id}/status` con estados por ítem, incluidos
 * `damaged` y `lost`. Lo que sigue documenta qué hace realmente ese endpoint.
 *
 * Resumen de lo que se comprueba abajo: **marca, no mueve**. El estado por ítem
 * es una etiqueta; no toca stock, no toca reservas y no toca el estado de la
 * asignación padre. No hay devolución parcial: hay una anotación parcial.
 */
class ResourceAllocationItemCharacterizationTest extends TestCase
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

    /** Asignación entregada de verdad: stock ya descontado, reserva liberada. */
    private function asignacionEntregada(int $stock = 100, float $cantidad = 10): array
    {
        $recurso = ResourceItem::factory()
            ->conStock($stock)
            ->create(['tenant_id' => $this->tenant->id, 'unit_cost' => 15000]);

        $asignacion = ResourceAllocation::factory()
            ->paraTenant($this->tenant, $this->usuario)
            ->conEstado('delivered')
            ->create();

        $item = ResourceAllocationItem::factory()
            ->de($asignacion, $recurso, $cantidad)
            ->conEstado('delivered')
            ->create();

        $recurso->decreaseStock((int) $cantidad);

        return [$recurso->fresh(), $asignacion, $item];
    }

    public function test_marcar_un_item_como_devuelto_no_reintegra_el_stock(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->asignacionEntregada(100, 10);
        $this->assertSame(90, $recurso->stock_quantity);

        $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", [
            'status' => 'returned',
        ])->assertOk()->assertJsonPath('data.status', 'returned');

        // HALLAZGO (🔴 funcional — eje de la 0057): el endpoint SOLO escribe el
        // estado, la fecha y quién lo recibió. El stock sigue en 90 aunque el
        // ítem diga «devuelto», y la asignación sigue «delivered». No existe la
        // devolución parcial: existe una anotación parcial que no mueve nada.
        $this->assertSame('returned', $item->fresh()->status);
        $this->assertSame(90, $recurso->fresh()->stock_quantity);
        $this->assertSame(0, $recurso->fresh()->reserved_quantity);
        $this->assertSame('delivered', $asignacion->fresh()->status);
    }

    public function test_marcar_perdido_o_danado_tampoco_mueve_nada(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->asignacionEntregada(100, 10);

        $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", [
            'status' => 'lost',
            'notes' => 'No volvieron de la vereda',
        ])->assertOk();

        // HALLAZGO (🟠): `lost` y `damaged` existen en el enum y se pueden
        // escribir, pero no descuentan, no valoran la pérdida
        // (cantidad × unit_cost) y no se lo cuentan a nadie. Son texto. → 0057.
        $this->assertSame('lost', $item->fresh()->status);
        $this->assertSame('No volvieron de la vereda', $item->fresh()->notes);
        $this->assertSame(90, $recurso->fresh()->stock_quantity);
        $this->assertSame('delivered', $asignacion->fresh()->status);
    }

    public function test_perdido_y_devuelto_se_sellan_igual_con_fecha_y_receptor(): void
    {
        $this->autenticado();
        [, , $item] = $this->asignacionEntregada();

        $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", [
            'status' => 'lost',
        ])->assertOk();

        // HALLAZGO (🟡): un ítem perdido queda con `returned_at` y
        // `returned_to_user_id` puestos, como si alguien lo hubiera recibido.
        // El campo miente sobre lo que pasó. → 0057.
        $item->refresh();
        $this->assertNotNull($item->returned_at);
        $this->assertSame($this->usuario->id, $item->returned_to_user_id);
    }

    public function test_el_estado_por_item_admite_cualquier_salto(): void
    {
        $this->autenticado();
        [, , $item] = $this->asignacionEntregada();

        // HALLAZGO (🟠): no hay máquina de estados por ítem. Se puede ir de
        // `delivered` a `pending`, o de `lost` de vuelta a `delivered`, sin
        // ninguna guarda. El único control es el enum de la columna. → 0057.
        foreach (['pending', 'lost', 'delivered', 'returned', 'pending'] as $estado) {
            $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", [
                'status' => $estado,
            ])->assertOk();

            $this->assertSame($estado, $item->fresh()->status);
        }
    }

    public function test_un_estado_fuera_del_enum_se_rechaza(): void
    {
        $this->autenticado();
        [, , $item] = $this->asignacionEntregada();

        $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", [
            'status' => 'parcial',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_marcar_entregado_sella_quien_entrego(): void
    {
        $this->autenticado();
        $recurso = ResourceItem::factory()->conStock(100)->create(['tenant_id' => $this->tenant->id]);
        $asignacion = ResourceAllocation::factory()->paraTenant($this->tenant, $this->usuario)->create();
        $item = ResourceAllocationItem::factory()->de($asignacion, $recurso, 5)->create();

        $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", [
            'status' => 'delivered',
        ])->assertOk();

        $item->refresh();
        $this->assertSame($this->usuario->id, $item->delivered_by_user_id);
        $this->assertNotNull($item->delivered_at);

        // Y otra vez: marcar «entregado» por ítem no descuenta stock ni libera
        // la reserva. Eso solo lo hace el ciclo de la asignación padre.
        $this->assertSame(100, $recurso->fresh()->stock_quantity);
    }

    public function test_editar_la_cantidad_de_un_item_reajusta_la_reserva(): void
    {
        $this->autenticado();
        $recurso = ResourceItem::factory()->conStock(100)->create([
            'tenant_id' => $this->tenant->id,
            'unit_cost' => 15000,
        ]);
        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => 10]],
        ])->assertCreated();
        $item = ResourceAllocationItem::first();

        $this->putJson("/api/v1/resource-allocation-items/{$item->id}", [
            'quantity' => 40,
        ])->assertOk();

        // Reparado por la 0057 (era el hallazgo H8): el `PUT` recalculaba el
        // total pero no tocaba la reserva —se pedían 40 y seguían apartadas 10—
        // ni comprobaba que existieran. Ahora reajusta y valida; los bordes
        // (subir, bajar, pasarse del disponible) están en `LogisticsBaseRepairTest`.
        $this->assertEquals(600000, $item->fresh()->resourceAllocation->total_cost);
        $this->assertSame(40, $recurso->fresh()->reserved_quantity);
        $this->assertSame(100, $recurso->fresh()->stock_quantity);
    }

    public function test_borrar_un_item_pendiente_deja_la_reserva_colgada(): void
    {
        $this->autenticado();
        $recurso = ResourceItem::factory()->conStock(100)->create([
            'tenant_id' => $this->tenant->id,
            'unit_cost' => 15000,
        ]);
        $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => 10]],
        ])->assertCreated();
        $item = ResourceAllocationItem::first();
        $this->assertSame(10, $recurso->fresh()->reserved_quantity);

        $this->deleteJson("/api/v1/resource-allocation-items/{$item->id}")->assertOk();

        // HALLAZGO (🔴 corrupción de stock — se corrige en esta spec): borrar el
        // ítem no libera su reserva, así que esas 10 unidades quedan reservadas
        // para siempre, sin ninguna asignación que las reclame. Cada borrado
        // vuelve el catálogo un poco más pequeño de lo que es.
        $this->assertDatabaseMissing('resource_allocation_items', ['id' => $item->id]);
        $this->assertSame(0, $recurso->fresh()->reserved_quantity);
    }

    public function test_borrar_el_ultimo_item_deja_la_asignacion_vacia_en_cero(): void
    {
        $this->autenticado();
        $recurso = ResourceItem::factory()->conStock(100)->create(['tenant_id' => $this->tenant->id]);
        $asignacion = ResourceAllocation::factory()->paraTenant($this->tenant, $this->usuario)->create();
        $item = ResourceAllocationItem::factory()->de($asignacion, $recurso, 3)->create();

        $this->deleteJson("/api/v1/resource-allocation-items/{$item->id}")->assertOk();

        // La asignación sobrevive sin ítems y con total 0: queda una entrega
        // vacía en la lista. Anotado para la 0057 (🟡).
        $this->assertEquals(0, $asignacion->fresh()->total_cost);
        $this->assertFalse($asignacion->fresh()->items()->exists());
    }
}
