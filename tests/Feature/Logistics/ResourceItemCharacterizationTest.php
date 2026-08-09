<?php

namespace Tests\Feature\Logistics;

use App\Models\ResourceItem;
use App\Models\Tenant;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Catálogo de recursos — caracterización (Spec 0056).
 *
 * Fija cómo se comporta HOY `resource-items`, no cómo debería. Lo que parezca un
 * error se marca con `HALLAZGO` y se decide en la 0057.
 */
class ResourceItemCharacterizationTest extends TestCase
{
    private function autenticado(array $permisos = [Permissions::VIEW_RESOURCES]): array
    {
        [$user, $token] = $this->createTenantWithUser($permisos);
        $this->actingAsTenantUser($user, $token);

        return [$user, $user->tenant];
    }

    public function test_el_catalogo_solo_lista_los_recursos_del_tenant(): void
    {
        [, $tenant] = $this->autenticado();
        ResourceItem::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Silla propia']);
        ResourceItem::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'name' => 'Silla ajena']);

        $respuesta = $this->getJson('/api/v1/resource-items')->assertOk();

        $nombres = collect($respuesta->json('data'))->pluck('name');
        $this->assertContains('Silla propia', $nombres);
        $this->assertNotContains('Silla ajena', $nombres);
    }

    public function test_disponible_es_stock_menos_reservado(): void
    {
        [, $tenant] = $this->autenticado();
        $recurso = ResourceItem::factory()->conStock(100, 30)->create(['tenant_id' => $tenant->id]);

        $this->assertSame(70, $recurso->available_quantity);

        $this->getJson("/api/v1/resource-items/{$recurso->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 100)
            ->assertJsonPath('data.reserved_quantity', 30)
            ->assertJsonPath('data.available_quantity', 70);
    }

    public function test_un_recurso_sin_rastreo_se_considera_infinito(): void
    {
        [, $tenant] = $this->autenticado();
        $recurso = ResourceItem::factory()->noRastreable()->create(['tenant_id' => $tenant->id]);

        // HALLAZGO (🟡): `available_quantity` devuelve PHP_INT_MAX para lo no
        // rastreable. En JSON sale como 9223372036854775807, un número que el
        // panel muestra tal cual. Lo suyo sería `null`. → 0057.
        $this->assertSame(PHP_INT_MAX, $recurso->available_quantity);
        $this->assertTrue($recurso->hasAvailableStock(999999));
    }

    public function test_stock_bajo_es_menor_o_igual_al_minimo_y_tiene_su_propio_endpoint(): void
    {
        [, $tenant] = $this->autenticado();
        $bajo = ResourceItem::factory()->conStock(5, 0, 10)->create(['tenant_id' => $tenant->id, 'name' => 'Casi sin sillas']);
        $justo = ResourceItem::factory()->conStock(10, 0, 10)->create(['tenant_id' => $tenant->id, 'name' => 'Justo en el mínimo']);
        $sobrado = ResourceItem::factory()->conStock(50, 0, 10)->create(['tenant_id' => $tenant->id, 'name' => 'De sobra']);

        $this->assertTrue($bajo->is_low_stock);
        $this->assertTrue($justo->is_low_stock, 'el mínimo cuenta como stock bajo');
        $this->assertFalse($sobrado->is_low_stock);

        $nombres = collect($this->getJson('/api/v1/resource-items-low-stock')->assertOk()->json('data'))->pluck('name');
        $this->assertContains('Casi sin sillas', $nombres);
        $this->assertContains('Justo en el mínimo', $nombres);
        $this->assertNotContains('De sobra', $nombres);
    }

    public function test_un_recurso_sin_minimo_nunca_es_stock_bajo(): void
    {
        [, $tenant] = $this->autenticado();
        $recurso = ResourceItem::factory()->create([
            'tenant_id' => $tenant->id,
            'stock_quantity' => 0,
            'min_stock' => null,
        ]);

        // HALLAZGO (🟡): sin `min_stock` no hay alerta aunque el stock esté en
        // cero. El catálogo puede quedarse vacío sin avisar. → 0057.
        $this->assertFalse($recurso->is_low_stock);
    }

    public function test_el_efectivo_creado_por_la_api_nace_marcado_como_inventario(): void
    {
        [, $tenant] = $this->autenticado([
            Permissions::VIEW_RESOURCES,
            Permissions::CREATE_RESOURCES,
            Permissions::EDIT_RESOURCES,
        ]);

        $respuesta = $this->postJson('/api/v1/resource-items', [
            'name' => 'Efectivo para gastos',
            'category' => 'cash',
            'unit' => 'COP',
            'unit_cost' => 1,
            'is_inventory_tracked' => false,
        ])->assertCreated();

        // HALLAZGO (🟠): `is_inventory_tracked` no está en las reglas del
        // FormRequest, ni al crear ni al editar, así que `validated()` lo
        // descarta y el ítem nace con el default de la columna: `true`. La
        // migración apagó el flag para los `cash` que ya existían, pero uno
        // nuevo creado por la API queda como inventario aunque sea dinero — y
        // no hay forma de corregirlo por API. → 0057.
        $this->assertSame('cash', $respuesta->json('data.category'));
        $this->assertDatabaseHas('resource_items', [
            'id' => $respuesta->json('data.id'),
            'tenant_id' => $tenant->id,
            'is_inventory_tracked' => true,
        ]);

        $this->putJson("/api/v1/resource-items/{$respuesta->json('data.id')}", [
            'is_inventory_tracked' => false,
        ])->assertOk();

        $this->assertDatabaseHas('resource_items', [
            'id' => $respuesta->json('data.id'),
            'is_inventory_tracked' => true,
        ]);
    }

    public function test_crear_exige_unidad_y_costo_unitario(): void
    {
        $this->autenticado([Permissions::VIEW_RESOURCES, Permissions::CREATE_RESOURCES]);

        // HALLAZGO (🟡): `unit_cost` es obligatorio incluso para lo que no tiene
        // costo conocido; obliga a inventar un 0. → 0057.
        $this->postJson('/api/v1/resource-items', [
            'name' => 'Carpa 3x3',
            'category' => 'furniture',
        ])->assertStatus(422)->assertJsonValidationErrors(['unit', 'unit_cost']);
    }

    public function test_crear_pone_moneda_y_activo_por_defecto(): void
    {
        $this->autenticado([Permissions::VIEW_RESOURCES, Permissions::CREATE_RESOURCES]);

        $respuesta = $this->postJson('/api/v1/resource-items', [
            'name' => 'Carpa 3x3',
            'category' => 'furniture',
            'unit' => 'unidad',
            'unit_cost' => 90000,
        ])->assertCreated();

        $this->assertSame('COP', $respuesta->json('data.currency'));
        $this->assertTrue($respuesta->json('data.is_active'));
    }

    public function test_editar_y_borrar_un_recurso_de_otro_tenant_responde_404(): void
    {
        $this->autenticado([
            Permissions::VIEW_RESOURCES,
            Permissions::EDIT_RESOURCES,
            Permissions::DELETE_RESOURCES,
        ]);
        $ajeno = ResourceItem::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->getJson("/api/v1/resource-items/{$ajeno->id}")->assertNotFound();
        $this->putJson("/api/v1/resource-items/{$ajeno->id}", ['name' => 'Secuestrada'])->assertNotFound();
        $this->deleteJson("/api/v1/resource-items/{$ajeno->id}")->assertNotFound();

        $this->assertDatabaseHas('resource_items', ['id' => $ajeno->id, 'name' => $ajeno->name, 'deleted_at' => null]);
    }

    public function test_borrar_es_en_blando(): void
    {
        [, $tenant] = $this->autenticado([Permissions::VIEW_RESOURCES, Permissions::DELETE_RESOURCES]);
        $recurso = ResourceItem::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/resource-items/{$recurso->id}")->assertOk();

        $this->assertSoftDeleted('resource_items', ['id' => $recurso->id]);
    }

    public function test_borrar_un_recurso_no_mira_si_esta_asignado(): void
    {
        [, $tenant] = $this->autenticado([Permissions::VIEW_RESOURCES, Permissions::DELETE_RESOURCES]);
        $recurso = ResourceItem::factory()->conStock(50, 10)->create(['tenant_id' => $tenant->id]);
        $asignacion = \App\Models\ResourceAllocation::factory()->paraTenant($tenant)->create();
        \App\Models\ResourceAllocationItem::factory()->de($asignacion, $recurso, 10)->create();

        $this->deleteJson("/api/v1/resource-items/{$recurso->id}")->assertOk();

        // HALLAZGO (🟡): se borra en blando un recurso con reserva viva. El ítem
        // de la asignación queda apuntando a un recurso borrado y la reserva de
        // 10 unidades no se libera. → 0057.
        $this->assertSoftDeleted('resource_items', ['id' => $recurso->id]);
        $this->assertSame(10, $recurso->fresh()->reserved_quantity);
    }
}
