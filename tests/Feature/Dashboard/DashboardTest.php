<?php

namespace Tests\Feature\Dashboard;

use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El dashboard agrupa por año/mes. Lo hacía con `EXTRACT(YEAR FROM ...)`, que
 * SQLite no entiende, así que respondía 500 en la suite y no se podía cubrir
 * (Spec 0019 / issue 0020).
 */
class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_el_dashboard_responde_200_con_su_estructura(): void
    {
        $tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($tenant)->create(['starts_at' => now()->subMonth()]);
        Commitment::factory()->forTenant($tenant)->overdue()->create();

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/dashboard')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'totals' => ['meetings', 'attendees', 'commitments', 'campaigns', 'users'],
                    'commitments_by_priority',
                    'meetings_by_month',
                    'attendees_by_month',
                    'top_meetings_by_attendees',
                ],
            ]);
    }

    public function test_agrupa_las_reuniones_por_año_y_mes(): void
    {
        $tenant = Tenant::factory()->create();

        Meeting::factory()->forTenant($tenant)->create(['starts_at' => '2026-07-10 09:00:00']);
        Meeting::factory()->forTenant($tenant)->create(['starts_at' => '2026-07-20 09:00:00']);
        Meeting::factory()->forTenant($tenant)->create(['starts_at' => '2026-08-02 09:00:00']);

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $porMes = collect(
            $this->actingAsTenantUser($user, $token)
                ->getJson('/api/v1/dashboard')
                ->assertStatus(200)
                ->json('data.meetings_by_month')
        );

        $julio = $porMes->firstWhere('month', 7);
        $agosto = $porMes->firstWhere('month', 8);

        $this->assertNotNull($julio, 'Debe agrupar julio.');
        $this->assertSame(2026, $julio['year']);
        $this->assertSame(2, (int) $julio['total']);

        $this->assertNotNull($agosto, 'Debe agrupar agosto.');
        $this->assertSame(1, (int) $agosto['total']);
    }

    public function test_solo_agrega_datos_del_tenant_del_usuario(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Meeting::factory()->forTenant($tenantA)->create(['starts_at' => now()->subMonth()]);
        Meeting::factory()->forTenant($tenantB)->create(['starts_at' => now()->subMonth()]);
        Meeting::factory()->forTenant($tenantB)->create(['starts_at' => now()->subMonth()]);

        [$user, $token] = $this->createTenantWithUser([], $tenantA);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.totals.meetings', 1);
    }

    public function test_el_calendario_sigue_respondiendo(): void
    {
        $tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/calendar')
            ->assertStatus(200);
    }
}
