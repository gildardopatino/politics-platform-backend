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
 * Seguridad de logística — caracterización (Spec 0056, RC6).
 *
 * Dos preguntas por ruta: ¿exige su permiso `*_resources`? y ¿deja tocar lo de
 * otro tenant?
 */
class LogisticsTenantSecurityTest extends TestCase
{
    private function usuarioCon(array $permisos): User
    {
        [$user, $token] = $this->createTenantWithUser($permisos);
        $this->actingAsTenantUser($user, $token);

        return $user;
    }

    public function test_sin_permiso_de_ver_todo_el_modulo_responde_403(): void
    {
        $user = $this->usuarioCon([]);
        $recurso = ResourceItem::factory()->create(['tenant_id' => $user->tenant_id]);
        $asignacion = ResourceAllocation::factory()->paraTenant($user->tenant, $user)->create();

        $this->getJson('/api/v1/resource-items')->assertForbidden();
        $this->getJson("/api/v1/resource-items/{$recurso->id}")->assertForbidden();
        $this->getJson('/api/v1/resource-items-low-stock')->assertForbidden();
        $this->getJson('/api/v1/resource-allocations')->assertForbidden();
        $this->getJson("/api/v1/resource-allocations/{$asignacion->id}")->assertForbidden();
    }

    public function test_cada_escritura_exige_su_propio_permiso(): void
    {
        $user = $this->usuarioCon([Permissions::VIEW_RESOURCES]);
        $recurso = ResourceItem::factory()->create(['tenant_id' => $user->tenant_id]);
        $asignacion = ResourceAllocation::factory()->paraTenant($user->tenant, $user)->create();
        $item = ResourceAllocationItem::factory()->de($asignacion, $recurso, 2)->create();

        $this->postJson('/api/v1/resource-items', ['name' => 'X', 'category' => 'other', 'unit' => 'u', 'unit_cost' => 0])
            ->assertForbidden();
        $this->putJson("/api/v1/resource-items/{$recurso->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/resource-items/{$recurso->id}")->assertForbidden();

        $this->postJson('/api/v1/resource-allocations', ['leader_user_id' => $user->id])->assertForbidden();
        $this->putJson("/api/v1/resource-allocations/{$asignacion->id}", ['amount' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/resource-allocations/{$asignacion->id}")->assertForbidden();

        $this->patchJson("/api/v1/resource-allocation-items/{$item->id}/status", ['status' => 'delivered'])
            ->assertForbidden();
        $this->putJson("/api/v1/resource-allocation-items/{$item->id}", ['quantity' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/resource-allocation-items/{$item->id}")->assertForbidden();
    }

    public function test_sin_sesion_ninguna_ruta_de_logistica_responde(): void
    {
        $this->getJson('/api/v1/resource-items')->assertUnauthorized();
        $this->getJson('/api/v1/resource-allocations')->assertUnauthorized();
        $this->patchJson('/api/v1/resource-allocation-items/1/status', ['status' => 'delivered'])
            ->assertUnauthorized();
    }

    public function test_los_items_de_otro_tenant_no_se_pueden_tocar(): void
    {
        $this->usuarioCon([
            Permissions::VIEW_RESOURCES,
            Permissions::EDIT_RESOURCES,
            Permissions::DELETE_RESOURCES,
        ]);

        $otro = Tenant::factory()->create();
        $ajenoUser = User::factory()->forTenant($otro)->create();
        $recursoAjeno = ResourceItem::factory()->conStock(100)->create(['tenant_id' => $otro->id]);
        $asignacionAjena = ResourceAllocation::factory()->paraTenant($otro, $ajenoUser)->create();
        $itemAjeno = ResourceAllocationItem::factory()->de($asignacionAjena, $recursoAjeno, 5)->create();

        // HALLAZGO (🔴 fuga entre tenants — se corrige en esta spec):
        // `ResourceAllocationItem` no usa `HasTenant` ni tiene `tenant_id`, y sus
        // tres rutas resuelven el modelo por binding directo. Sin una guarda
        // explícita, cualquiera con `edit_resources` podía cambiar el estado, la
        // cantidad o borrar los ítems de otra campaña conociendo el id.
        $this->patchJson("/api/v1/resource-allocation-items/{$itemAjeno->id}/status", ['status' => 'lost'])
            ->assertNotFound();
        $this->putJson("/api/v1/resource-allocation-items/{$itemAjeno->id}", ['quantity' => 999])
            ->assertNotFound();
        $this->deleteJson("/api/v1/resource-allocation-items/{$itemAjeno->id}")
            ->assertNotFound();

        $itemAjeno->refresh();
        $this->assertSame('pending', $itemAjeno->status);
        $this->assertEquals(5, $itemAjeno->quantity);
        $this->assertDatabaseHas('resource_allocation_items', ['id' => $itemAjeno->id]);
    }

    public function test_el_super_admin_no_queda_fuera_del_modulo(): void
    {
        $this->actingAsSuperAdmin();

        // El super admin no tiene tenant, así que `EnsureTenant` no fija filtro:
        // ve el catálogo de todos. Se documenta como está.
        $this->getJson('/api/v1/resource-items')->assertOk();
    }
}
