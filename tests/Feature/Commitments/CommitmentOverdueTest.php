<?php

namespace Tests\Feature\Commitments;

use App\Models\Commitment;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regresión del bug de orden de rutas: `Route::apiResource('commitments')` se
 * declaraba antes que `/commitments/overdue`, así que `{commitment}` capturaba
 * "overdue" y el endpoint respondía 404 (Spec 0001).
 *
 * El caso "sin permiso `view_commitments`" NO se prueba aquí: el backend no
 * aplica `permission:` en las rutas. Está caracterizado en
 *
 * @see \Tests\Feature\Authorization\PermissionEnforcementCharacterizationTest
 */
class CommitmentOverdueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tiempo fijo: las fechas de vencimiento deben ser deterministas.
        Carbon::setTestNow('2026-08-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_devuelve_200_con_solo_los_compromisos_vencidos_del_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $vencido = Commitment::factory()->forTenant($tenantA)->overdue()->create([
            'description' => 'Compromiso vencido de A',
        ]);
        $vigente = Commitment::factory()->forTenant($tenantA)->create([
            'description' => 'Compromiso vigente de A',
            'due_date' => now()->addWeek()->toDateString(),
        ]);
        $vencidoOtroTenant = Commitment::factory()->forTenant($tenantB)->overdue()->create([
            'description' => 'Compromiso vencido de B',
        ]);

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $tenantA);

        $response = $this->actingAsTenantUser($user, $token)->getJson('/api/v1/commitments/overdue');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page']]);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($vencido->id), 'Debe incluir el compromiso vencido del tenant.');
        $this->assertFalse($ids->contains($vigente->id), 'No debe incluir compromisos no vencidos.');
        $this->assertFalse($ids->contains($vencidoOtroTenant->id), 'No debe incluir compromisos de otro tenant.');
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_ignora_los_compromisos_ya_completados(): void
    {
        $tenant = Tenant::factory()->create();

        Commitment::factory()->forTenant($tenant)->overdue()->completed()->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments/overdue')
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_sin_compromisos_vencidos_devuelve_200_con_data_vacia(): void
    {
        $tenant = Tenant::factory()->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments/overdue')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/commitments/overdue')->assertStatus(401);
    }

    public function test_las_rutas_de_commitments_con_parametro_siguen_funcionando(): void
    {
        $tenant = Tenant::factory()->create();
        $commitment = Commitment::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments', 'edit_commitments', 'delete_commitments'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/commitments/{$commitment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $commitment->id);

        $this->actingAsTenantUser($user, $token)
            ->putJson("/api/v1/commitments/{$commitment->id}", ['status' => 'in_progress'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAsTenantUser($user, $token)
            ->deleteJson("/api/v1/commitments/{$commitment->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('commitments', ['id' => $commitment->id]);
    }
}
