<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Priority;
use App\Models\Tenant;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_un_usuario_solo_ve_las_reuniones_de_su_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['nombre' => 'Candidato A']);
        $tenantB = Tenant::factory()->create(['nombre' => 'Candidato B']);

        $meetingA = Meeting::factory()->forTenant($tenantA)->create(['title' => 'Reunión de A']);
        $meetingB = Meeting::factory()->forTenant($tenantB)->create(['title' => 'Reunión de B']);

        [$userA, $tokenA] = $this->createTenantWithUser(['view_meetings'], $tenantA);

        $response = $this->actingAsTenantUser($userA, $tokenA)->getJson('/api/v1/meetings');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($meetingA->id), 'El usuario de A debería ver la reunión de A.');
        $this->assertFalse($ids->contains($meetingB->id), 'El usuario de A NO debe ver la reunión de B.');
        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * CARACTERIZACIÓN — documenta un comportamiento actual DEFECTUOSO.
     *
     * `SubstituteBindings` viene del grupo de middleware `api` y se ejecuta ANTES
     * de las middleware de ruta `jwt.auth`/`tenant` (ninguna está en
     * `$middlewarePriority`). Cuando Laravel resuelve el binding implícito todavía
     * no existe `current_tenant_id`, así que `TenantScope` no filtra y el modelo
     * de otro tenant se entrega al controlador: fuga cross-tenant en `show`.
     *
     * Fuera del alcance de la Spec 0001 (auditoría de rutas). Cuando se corrija,
     * esta prueba fallará: cámbiala a esperar 404.
     */
    public function test_caracteriza_que_el_binding_implicito_expone_reuniones_de_otro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $meetingB = Meeting::factory()->forTenant($tenantB)->create();

        [$userA, $tokenA] = $this->createTenantWithUser(['view_meetings'], $tenantA);

        $response = $this->actingAsTenantUser($userA, $tokenA)
            ->getJson("/api/v1/meetings/{$meetingB->id}");

        // Comportamiento actual (bug conocido): devuelve 200 con datos de B.
        $response->assertStatus(200)->assertJsonPath('data.id', $meetingB->id);
    }

    public function test_un_compromiso_creado_por_un_usuario_hereda_su_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $meetingA = Meeting::factory()->forTenant($tenantA)->create();
        $priority = Priority::create(['name' => 'Alta', 'order' => 1]);

        [$userA, $tokenA] = $this->createTenantWithUser(['create_commitments'], $tenantA);

        $response = $this->actingAsTenantUser($userA, $tokenA)->postJson('/api/v1/commitments', [
            'meeting_id' => $meetingA->id,
            'assigned_user_id' => $userA->id,
            'priority_id' => $priority->id,
            'description' => 'Entregar el informe de la reunión',
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'pending',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('commitments', [
            'id' => $response->json('data.id'),
            'tenant_id' => $tenantA->id,
        ]);
    }

    public function test_el_super_admin_global_ve_datos_de_todos_los_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $meetingA = Meeting::factory()->forTenant($tenantA)->create();
        $meetingB = Meeting::factory()->forTenant($tenantB)->create();

        $this->actingAsSuperAdmin();

        $response = $this->getJson('/api/v1/meetings');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($meetingA->id));
        $this->assertTrue($ids->contains($meetingB->id));
    }
}
