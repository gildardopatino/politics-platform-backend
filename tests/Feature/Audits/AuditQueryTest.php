<?php

namespace Tests\Feature\Audits;

use App\Models\Meeting;
use App\Models\ResourceItem;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * La auditoría filtra por tenant leyendo el JSON de `new_values`/`old_values`.
 * Lo hacía con `::jsonb->>'...'`, exclusivo de PostgreSQL, así que respondía 500
 * fuera de él (Spec 0019 / issue 0020).
 */
class AuditQueryTest extends TestCase
{
    public function test_el_listado_de_auditorias_responde_200(): void
    {
        $tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser(['view_audits'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/audits')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_las_estadisticas_de_auditoria_responden_200(): void
    {
        $tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser(['view_audits'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/audits/statistics')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['total_audits', 'by_event', 'by_model']]);
    }

    public function test_la_auditoria_no_mezcla_registros_de_otro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Meeting::factory()->forTenant($tenantA)->create(['title' => 'Reunión de A']);
        Meeting::factory()->forTenant($tenantB)->create(['title' => 'Reunión de B']);
        Meeting::factory()->forTenant($tenantB)->create(['title' => 'Otra de B']);

        [$user, $token] = $this->createTenantWithUser(['view_audits'], $tenantA);

        $auditorias = $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/audits')
            ->assertStatus(200)
            ->json('data');

        $tenantsVistos = collect($auditorias)
            ->pluck('new_values.tenant_id')
            ->filter()
            ->unique()
            ->values();

        $this->assertTrue(
            $tenantsVistos->isEmpty() || $tenantsVistos->every(fn ($id) => (int) $id === $tenantA->id),
            'El filtro por tenant de la auditoría no debe dejar pasar registros ajenos.'
        );
    }

    public function test_la_busqueda_de_items_de_recurso_responde_200(): void
    {
        // Mismo problema de portabilidad: usaba ILIKE, exclusivo de PostgreSQL.
        $tenant = Tenant::factory()->create();

        ResourceItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Silla plástica',
            'category' => 'furniture',
            'unit' => 'unidad',
        ]);

        [$user, $token] = $this->createTenantWithUser(['view_resources'], $tenant);

        $response = $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/resource-items?search=silla')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'), 'La búsqueda debe ignorar mayúsculas.');
    }
}
