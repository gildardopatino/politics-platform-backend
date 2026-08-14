<?php

namespace Tests\Feature\E14;

use App\Models\E14MetaPuesto;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * La meta de votos, fijada a mano (Spec 0064 · RF-1).
 *
 * La meta **no se deriva** de histórico ni del censo: la pone el jefe de campaña.
 * Hay una global por elección y overrides opcionales por puesto; municipio y zona
 * se agregan de sus puestos, así que no tienen tabla propia.
 *
 * Fijarla es una escritura de configuración, no una consulta: pide `manage_e14` y
 * queda auditada.
 */
class E14MetaTest extends TestCase
{
    private const LUGAR = 'COLEGIO SAN SIMON';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function evento(Tenant $tenant): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo' => 'alcaldia', 'nombre' => 'Alcaldía'],
            ['fecha' => '2027-10-31'],
        );
    }

    private function puesto(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
        ], $cambios));
    }

    // ------------------------------------------------------------ fijarla

    public function test_fija_la_meta_global_de_la_eleccion(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson('/api/v1/e14/meta', ['event' => $evento->id, 'meta_votos' => 12000])
            ->assertOk()
            ->assertJsonPath('data.meta_votos', 12000)
            ->assertJsonPath('data.electoral_event_id', $evento->id);

        $this->assertSame(12000, $evento->fresh()->meta_votos);
    }

    public function test_fija_y_actualiza_la_meta_de_un_puesto(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);
        $lugar = $this->puesto();

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => 300]],
        ])->assertOk()->assertJsonPath('data.puestos.0.meta_votos', 300);

        // El segundo envío actualiza el mismo renglón: la meta de un puesto es
        // una, no una lista de intentos.
        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => 450]],
        ])->assertOk()->assertJsonPath('data.puestos.0.meta_votos', 450);

        $this->assertSame(1, E14MetaPuesto::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_una_meta_nula_borra_el_override_del_puesto(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);
        $lugar = $this->puesto();

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => 300]],
        ])->assertOk();

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => null]],
        ])->assertOk()->assertJsonCount(0, 'data.puestos');

        $this->assertSame(0, E14MetaPuesto::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_la_meta_global_se_puede_quitar(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson('/api/v1/e14/meta', ['event' => $evento->id, 'meta_votos' => 12000])->assertOk();

        $this->putJson('/api/v1/e14/meta', ['event' => $evento->id, 'meta_votos' => null])
            ->assertOk()
            ->assertJsonPath('data.meta_votos', null);

        $this->assertNull($evento->fresh()->meta_votos);
    }

    public function test_no_tocar_una_clave_la_deja_como_estaba(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);
        $lugar = $this->puesto();

        $this->putJson('/api/v1/e14/meta', ['event' => $evento->id, 'meta_votos' => 12000])->assertOk();

        // Solo se mandan los puestos: la global no viaja, así que no se toca.
        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => 300]],
        ])->assertOk()->assertJsonPath('data.meta_votos', 12000);
    }

    // ------------------------------------------------------------- leerla

    public function test_devuelve_la_meta_con_lo_asignado_y_lo_que_falta_por_repartir(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);
        $uno = $this->puesto();
        $otro = $this->puesto(['puesto_votacion' => 'INSTITUCION EDUCATIVA SAN JOSE']);

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'meta_votos' => 12000,
            'puestos' => [
                ['voting_place_id' => $uno->id, 'meta_votos' => 300],
                ['voting_place_id' => $otro->id, 'meta_votos' => 200],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/e14/meta?event='.$evento->id)
            ->assertOk()
            ->assertJsonPath('data.meta_votos', 12000)
            ->assertJsonCount(2, 'data.puestos')
            ->assertJsonPath('meta.meta_asignada', 500)
            // Lo que la campaña todavía no repartió entre sus puestos. No es un
            // error tener menos asignado que la meta: es el estado normal.
            ->assertJsonPath('meta.sin_asignar', 11500)
            ->assertJsonPath('meta.puestos_con_meta', 2);
    }

    public function test_el_puesto_llega_con_su_nombre_para_no_mostrar_un_id_suelto(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);
        $lugar = $this->puesto();

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => 300]],
        ])->assertOk();

        $this->getJson('/api/v1/e14/meta?event='.$evento->id)
            ->assertOk()
            ->assertJsonPath('data.puestos.0.municipio', 'IBAGUE')
            ->assertJsonPath('data.puestos.0.puesto', self::LUGAR)
            ->assertJsonPath('data.puestos.0.departamento', 'TOLIMA');
    }

    public function test_sin_meta_fijada_responde_en_nulo_y_no_falla(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->getJson('/api/v1/e14/meta?event='.$evento->id)
            ->assertOk()
            ->assertJsonPath('data.meta_votos', null)
            ->assertJsonCount(0, 'data.puestos')
            ->assertJsonPath('meta.sin_asignar', null);
    }

    // -------------------------------------------------------- las puertas

    public function test_fijar_la_meta_pide_manage_e14(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $evento = $this->evento($tenant);

        $this->putJson('/api/v1/e14/meta', ['event' => $evento->id, 'meta_votos' => 12000])
            ->assertForbidden();
    }

    public function test_leer_la_meta_pide_view_e14(): void
    {
        $tenant = $this->operador([Permissions::MANAGE_E14]);
        $evento = $this->evento($tenant);

        $this->getJson('/api/v1/e14/meta?event='.$evento->id)->assertForbidden();
    }

    public function test_la_meta_negativa_no_pasa(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson('/api/v1/e14/meta', ['event' => $evento->id, 'meta_votos' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('meta_votos');
    }

    public function test_un_puesto_que_no_existe_no_pasa(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => 999999, 'meta_votos' => 300]],
        ])->assertStatus(422)->assertJsonValidationErrors('puestos.0.voting_place_id');
    }

    // ------------------------------------------------------- multi-tenant

    public function test_la_meta_de_otra_campana_ni_se_ve_ni_se_escribe(): void
    {
        $otro = Tenant::factory()->create();
        $suEvento = $this->evento($otro);
        E14MetaPuesto::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $otro->id,
            'electoral_event_id' => $suEvento->id,
            'voting_place_id' => $this->puesto()->id,
            'meta_votos' => 300,
        ]);
        $suEvento->update(['meta_votos' => 9000]);

        $mio = $this->operador();
        $miEvento = $this->evento($mio);

        // La elección ajena no existe desde aquí: es el mismo 422 del cruce.
        $this->getJson('/api/v1/e14/meta?event='.$suEvento->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');

        $this->putJson('/api/v1/e14/meta', ['event' => $suEvento->id, 'meta_votos' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');

        $this->assertSame(9000, $suEvento->fresh()->meta_votos);

        // Y la mía sale limpia, sin el puesto del otro.
        $this->getJson('/api/v1/e14/meta?event='.$miEvento->id)
            ->assertOk()
            ->assertJsonPath('data.meta_votos', null)
            ->assertJsonCount(0, 'data.puestos');
    }

    // ---------------------------------------------------------- auditoría

    public function test_fijar_la_meta_de_un_puesto_queda_auditado(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);
        $lugar = $this->puesto();

        $this->putJson('/api/v1/e14/meta', [
            'event' => $evento->id,
            'puestos' => [['voting_place_id' => $lugar->id, 'meta_votos' => 300]],
        ])->assertOk();

        $meta = E14MetaPuesto::withoutGlobalScope(TenantScope::class)->firstOrFail();

        // Cambiar la meta mueve el semáforo de todo el tablero, así que tiene
        // que saberse quién la movió. La comprobación va sobre `toAudit()` y no
        // sobre la tabla `audits` porque en consola la auditoría no persiste
        // (`audit.console`); es la misma forma que usan la 0013 y la 0057.
        $this->assertInstanceOf(\OwenIt\Auditing\Contracts\Auditable::class, $meta);

        $meta->setAuditEvent('created');
        $registro = $meta->toAudit()['new_values'];

        $this->assertArrayHasKey('meta_votos', $registro);
        $this->assertArrayHasKey('voting_place_id', $registro);
        $this->assertArrayHasKey('electoral_event_id', $registro);
    }
}
