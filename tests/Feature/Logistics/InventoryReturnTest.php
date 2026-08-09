<?php

namespace Tests\Feature\Logistics;

use App\Models\InventoryLoss;
use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\ResourceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Devolución parcial y merma (Spec 0057, fase 1).
 *
 * La 0056 dejó claro que el estado por ítem solo anotaba: marcar «devuelto» no
 * reintegraba, y `lost`/`damaged` no descontaban ni valían nada. Aquí el cierre
 * mueve stock de verdad por lo que vuelve, y lo que no vuelve queda registrado
 * como pérdida valorada y con responsable.
 */
class InventoryReturnTest extends TestCase
{
    private User $usuario;

    private Tenant $tenant;

    private function autenticado(array $permisos = [
        Permissions::VIEW_RESOURCES,
        Permissions::CREATE_RESOURCES,
        Permissions::EDIT_RESOURCES,
    ]): void
    {
        [$user, $token] = $this->createTenantWithUser($permisos);
        $this->actingAsTenantUser($user, $token);
        $this->usuario = $user;
        $this->tenant = $user->tenant;
    }

    /** Asignación entregada por el camino normal: stock ya descontado. */
    private function entregada(int $stock = 100, float $cantidad = 10, ?Meeting $reunion = null): array
    {
        $recurso = ResourceItem::factory()
            ->conStock($stock)
            ->create(['tenant_id' => $this->tenant->id, 'unit_cost' => 15000]);

        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'meeting_id' => $reunion?->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => $cantidad]],
        ])->assertCreated();

        $asignacion = ResourceAllocation::findOrFail($respuesta->json('data.id'));
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['status' => 'delivered'])->assertOk();

        return [$recurso->fresh(), $asignacion->fresh(), $asignacion->items->first()];
    }

    private function cerrar(ResourceAllocation $asignacion, array $items): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/resource-allocations/{$asignacion->id}/close", ['items' => $items]);
    }

    public function test_lo_devuelto_vuelve_al_stock_y_lo_perdido_no(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);
        $this->assertSame(90, $recurso->stock_quantity);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 8, 'quantity_lost' => 2],
        ])->assertOk();

        // Ocho vuelven al almacén; las dos que se quedaron en la vereda no.
        $this->assertSame(98, $recurso->fresh()->stock_quantity);
        $this->assertSame('returned', $asignacion->fresh()->status);
    }

    public function test_la_perdida_queda_valorada_y_con_responsable(): void
    {
        $this->autenticado();
        $reunion = Meeting::factory()->create(['tenant_id' => $this->tenant->id]);
        [$recurso, $asignacion, $item] = $this->entregada(100, 10, $reunion);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 8, 'quantity_lost' => 2, 'notes' => 'Se quedaron en la vereda'],
        ])->assertOk();

        $merma = InventoryLoss::firstOrFail();
        $this->assertEquals(2, $merma->quantity);
        $this->assertEquals(15000, $merma->unit_cost);
        $this->assertEquals(30000, $merma->value, 'cantidad × costo unitario del momento');
        $this->assertSame('perdido', $merma->reason);
        $this->assertSame($this->usuario->id, $merma->responsible_user_id);
        $this->assertSame($reunion->id, $merma->meeting_id);
        $this->assertSame($recurso->id, $merma->resource_item_id);
        $this->assertSame($this->tenant->id, $merma->tenant_id);
    }

    public function test_el_costo_de_la_merma_es_una_foto_no_el_precio_de_hoy(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 9, 'quantity_lost' => 1],
        ])->assertOk();

        // El catálogo sube de precio después del cierre.
        $recurso->update(['unit_cost' => 90000]);

        $merma = InventoryLoss::firstOrFail();
        $this->assertEquals(15000, $merma->unit_cost, 'lo perdido sigue valiendo lo que valía');
        $this->assertEquals(15000, $merma->value);
    }

    public function test_lo_danado_se_registra_aparte_y_tampoco_vuelve_al_stock(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 6, 'quantity_lost' => 1, 'quantity_damaged' => 3],
        ])->assertOk();

        $this->assertSame(96, $recurso->fresh()->stock_quantity, 'solo vuelven las 6 sanas');

        $razones = InventoryLoss::pluck('quantity', 'reason')->all();
        $this->assertEquals(1, $razones['perdido']);
        $this->assertEquals(3, $razones['dañado']);
        $this->assertEquals(60000, InventoryLoss::sum('value'));
    }

    public function test_devolver_todo_sigue_siendo_el_caso_normal(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 10],
        ])->assertOk();

        $this->assertSame(100, $recurso->fresh()->stock_quantity);
        $this->assertSame('returned', $asignacion->fresh()->status);
        $this->assertSame('devuelto', $item->fresh()->return_state);
        $this->assertDatabaseCount('inventory_losses', 0);
    }

    public function test_el_desglose_no_puede_pasarse_de_lo_entregado(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 8, 'quantity_lost' => 5],
        ])->assertStatus(422)->assertJsonPath('errors.items.0', 'Lo devuelto, perdido y dañado no puede superar lo entregado (10).');

        $this->assertSame(90, $recurso->fresh()->stock_quantity, 'no se movió nada');
        $this->assertSame('delivered', $asignacion->fresh()->status);
        $this->assertDatabaseCount('inventory_losses', 0);
    }

    public function test_cerrar_con_menos_de_lo_entregado_deja_el_resto_como_pendiente(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);

        // Cerrar declarando solo 6: las otras 4 no vuelven ni se declaran
        // perdidas, así que el ítem queda parcial y el stock solo sube 6.
        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 6],
        ])->assertOk();

        $this->assertSame(96, $recurso->fresh()->stock_quantity);
        $this->assertSame('parcial', $item->fresh()->return_state);
    }

    public function test_el_estado_del_item_se_deriva_del_desglose(): void
    {
        $this->autenticado();
        [, $asignacion, $item] = $this->entregada(100, 10);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_lost' => 10],
        ])->assertOk();

        $this->assertSame('perdido', $item->fresh()->return_state);
        $this->assertSame('lost', $item->fresh()->status);
    }

    public function test_solo_se_cierra_lo_que_esta_entregado(): void
    {
        $this->autenticado();
        $recurso = ResourceItem::factory()->conStock(100)->create(['tenant_id' => $this->tenant->id]);
        $respuesta = $this->postJson('/api/v1/resource-allocations', [
            'leader_user_id' => $this->usuario->id,
            'items' => [['resource_item_id' => $recurso->id, 'quantity' => 5]],
        ])->assertCreated();
        $asignacion = ResourceAllocation::findOrFail($respuesta->json('data.id'));

        $this->cerrar($asignacion, [
            ['id' => $asignacion->items->first()->id, 'quantity_returned' => 5],
        ])->assertStatus(422);
    }

    public function test_no_se_cierra_dos_veces(): void
    {
        $this->autenticado();
        [$recurso, $asignacion, $item] = $this->entregada(100, 10);

        $this->cerrar($asignacion, [['id' => $item->id, 'quantity_returned' => 10]])->assertOk();
        $this->cerrar($asignacion, [['id' => $item->id, 'quantity_returned' => 10]])->assertStatus(422);

        $this->assertSame(100, $recurso->fresh()->stock_quantity, 'el stock no sube dos veces');
    }

    public function test_un_item_de_otra_asignacion_no_entra_en_el_cierre(): void
    {
        $this->autenticado();
        [, $asignacion] = $this->entregada(100, 10);
        [, , $itemAjeno] = $this->entregada(100, 4);

        $this->cerrar($asignacion, [['id' => $itemAjeno->id, 'quantity_returned' => 1]])
            ->assertStatus(422);
    }

    public function test_cerrar_una_asignacion_de_otro_tenant_da_404(): void
    {
        $this->autenticado();
        $otro = Tenant::factory()->create();
        $ajena = ResourceAllocation::factory()->paraTenant($otro)->conEstado('delivered')->create();

        $this->cerrar($ajena, [])->assertNotFound();
    }

    public function test_cerrar_exige_permiso_de_edicion(): void
    {
        $this->autenticado([Permissions::VIEW_RESOURCES, Permissions::CREATE_RESOURCES]);
        $asignacion = ResourceAllocation::factory()
            ->paraTenant($this->tenant, $this->usuario)
            ->conEstado('delivered')
            ->create();

        $this->cerrar($asignacion, [])->assertForbidden();
    }

    public function test_las_mermas_se_consultan_por_responsable_y_por_reunion(): void
    {
        $this->autenticado();
        $reunion = Meeting::factory()->create(['tenant_id' => $this->tenant->id]);
        [, $asignacion, $item] = $this->entregada(100, 10, $reunion);

        $this->cerrar($asignacion, [
            ['id' => $item->id, 'quantity_returned' => 7, 'quantity_lost' => 3],
        ])->assertOk();

        $respuesta = $this->getJson('/api/v1/inventory-losses')->assertOk();
        $this->assertCount(1, $respuesta->json('data'));
        $this->assertEquals(45000, $respuesta->json('summary.total_value'));

        $porResponsable = $this->getJson("/api/v1/inventory-losses?filter[responsible_user_id]={$this->usuario->id}")
            ->assertOk();
        $this->assertCount(1, $porResponsable->json('data'));

        $porReunion = $this->getJson("/api/v1/inventory-losses?filter[meeting_id]={$reunion->id}")->assertOk();
        $this->assertCount(1, $porReunion->json('data'));

        $vacio = $this->getJson('/api/v1/inventory-losses?filter[meeting_id]=999999')->assertOk();
        $this->assertCount(0, $vacio->json('data'));
    }

    public function test_las_mermas_de_otro_tenant_no_se_ven(): void
    {
        $this->autenticado();
        $otro = Tenant::factory()->create();
        InventoryLoss::create([
            'tenant_id' => $otro->id,
            'resource_item_id' => ResourceItem::factory()->create(['tenant_id' => $otro->id])->id,
            'quantity' => 5,
            'unit_cost' => 1000,
            'value' => 5000,
            'reason' => 'perdido',
        ]);

        $respuesta = $this->getJson('/api/v1/inventory-losses')->assertOk();

        $this->assertCount(0, $respuesta->json('data'));
        $this->assertEquals(0, $respuesta->json('summary.total_value'));
    }
}
