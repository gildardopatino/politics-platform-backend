<?php

namespace Tests\Feature;

use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Priority;
use App\Models\Tenant;
use App\Models\Voter;
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

    // ------------------------------------------------------------------
    // Regresión Spec 0004 — acceso por id a recursos de otro tenant.
    //
    // El binding implícito debe resolverse DESPUÉS de que `tenant` fije
    // `current_tenant_id`, para que `TenantScope` filtre y `firstOrFail`
    // devuelva 404 en vez de entregar el modelo ajeno al controlador.
    // ------------------------------------------------------------------

    public function test_leer_por_id_una_reunion_de_otro_tenant_devuelve_404(): void
    {
        [$user, $token, $meetingB] = $this->usuarioDeAyReunionDeB();

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$meetingB->id}")
            ->assertStatus(404);
    }

    public function test_actualizar_por_id_una_reunion_de_otro_tenant_devuelve_404_y_no_la_modifica(): void
    {
        [$user, $token, $meetingB] = $this->usuarioDeAyReunionDeB();

        $this->actingAsTenantUser($user, $token)
            ->putJson("/api/v1/meetings/{$meetingB->id}", ['title' => 'Secuestrada'])
            ->assertStatus(404);

        $this->assertDatabaseHas('meetings', [
            'id' => $meetingB->id,
            'title' => 'Reunión de B',
        ]);
    }

    public function test_borrar_por_id_una_reunion_de_otro_tenant_devuelve_404_y_no_la_borra(): void
    {
        [$user, $token, $meetingB] = $this->usuarioDeAyReunionDeB();

        $this->actingAsTenantUser($user, $token)
            ->deleteJson("/api/v1/meetings/{$meetingB->id}")
            ->assertStatus(404);

        $this->assertNotSoftDeleted('meetings', ['id' => $meetingB->id]);
    }

    public function test_acceder_por_id_a_un_compromiso_de_otro_tenant_devuelve_404(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $commitmentB = Commitment::factory()->forTenant($tenantB)->create([
            'description' => 'Compromiso de B',
        ]);

        [$user, $token] = $this->createTenantWithUser(
            ['view_commitments', 'edit_commitments', 'delete_commitments'],
            $tenantA
        );

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/commitments/{$commitmentB->id}")
            ->assertStatus(404);

        $this->actingAsTenantUser($user, $token)
            ->putJson("/api/v1/commitments/{$commitmentB->id}", ['description' => 'Secuestrado'])
            ->assertStatus(404);

        $this->actingAsTenantUser($user, $token)
            ->deleteJson("/api/v1/commitments/{$commitmentB->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('commitments', [
            'id' => $commitmentB->id,
            'description' => 'Compromiso de B',
        ]);
        $this->assertNotSoftDeleted('commitments', ['id' => $commitmentB->id]);
    }

    public function test_acceder_por_id_a_un_votante_de_otro_tenant_devuelve_404(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $voterB = Voter::factory()->forTenant($tenantB)->create(['nombres' => 'Beatriz']);

        [$user, $token] = $this->createTenantWithUser(['ver_electores'], $tenantA);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/voters/{$voterB->id}")
            ->assertStatus(404);

        $this->actingAsTenantUser($user, $token)
            ->putJson("/api/v1/voters/{$voterB->id}", [
                'cedula' => $voterB->cedula,
                'nombres' => 'Secuestrada',
                'apellidos' => $voterB->apellidos,
            ])
            ->assertStatus(404);

        $this->actingAsTenantUser($user, $token)
            ->deleteJson("/api/v1/voters/{$voterB->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('voters', [
            'id' => $voterB->id,
            'nombres' => 'Beatriz',
        ]);
        $this->assertNotSoftDeleted('voters', ['id' => $voterB->id]);
    }

    // ------------------------------------------------------------------
    // Casos positivos: el arreglo no puede romper el acceso legítimo.
    // ------------------------------------------------------------------

    public function test_un_usuario_si_accede_por_id_a_sus_propios_recursos(): void
    {
        $tenant = Tenant::factory()->create();

        $meeting = Meeting::factory()->forTenant($tenant)->create();
        $commitment = Commitment::factory()->forTenant($tenant)->create();
        $voter = Voter::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser(
            ['view_meetings', 'view_commitments', 'ver_electores'],
            $tenant
        );

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$meeting->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $meeting->id);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/commitments/{$commitment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $commitment->id);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/voters/{$voter->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $voter->id);
    }

    public function test_el_super_admin_global_si_accede_por_id_a_recursos_de_cualquier_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $meetingA = Meeting::factory()->forTenant($tenantA)->create();
        $meetingB = Meeting::factory()->forTenant($tenantB)->create();

        $this->actingAsSuperAdmin();

        $this->getJson("/api/v1/meetings/{$meetingA->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $meetingA->id);

        $this->getJson("/api/v1/meetings/{$meetingB->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $meetingB->id);
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

    /**
     * Usuario del tenant A (con permisos de meetings) + una reunión del tenant B.
     *
     * @return array{0: \App\Models\User, 1: string, 2: \App\Models\Meeting}
     */
    private function usuarioDeAyReunionDeB(): array
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $meetingB = Meeting::factory()->forTenant($tenantB)->create(['title' => 'Reunión de B']);

        [$user, $token] = $this->createTenantWithUser(
            ['view_meetings', 'edit_meetings', 'delete_meetings'],
            $tenantA
        );

        return [$user, $token, $meetingB];
    }
}
