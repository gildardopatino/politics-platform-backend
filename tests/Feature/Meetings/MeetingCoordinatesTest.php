<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * Las coordenadas de una reunión, de extremo a extremo (Spec 0058).
 *
 * El mapa del panel no reflejaba las ubicaciones y la sospecha inicial apuntaba
 * al backend. No era: el bug estaba en el frontend, que pedía los datos bajo una
 * clave que nadie invalidaba. Estas pruebas dejan esa mitad blindada —guardar y
 * devolver lat/lng— para que la próxima vez la búsqueda empiece en el sitio
 * correcto.
 */
class MeetingCoordinatesTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'hierarchy_mode' => 'disabled',
            'require_hierarchy_config' => false,
        ]);
    }

    /** @param array<int, string> $permisos */
    private function comoUsuarioCon(array $permisos): static
    {
        [$usuario, $token] = $this->createTenantWithUser($permisos, $this->tenant);

        return $this->actingAsTenantUser($usuario, $token);
    }

    public function test_editar_una_reunion_guarda_sus_coordenadas_y_las_devuelve(): void
    {
        $reunion = Meeting::factory()->forTenant($this->tenant)->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $respuesta = $this->comoUsuarioCon(['edit_meetings'])
            ->patchJson("/api/v1/meetings/{$reunion->id}", [
                'latitude' => 4.4389,
                'longitude' => -75.2322,
            ]);

        $respuesta->assertOk();
        $this->assertEquals(4.4389, $respuesta->json('data.latitude'));
        $this->assertEquals(-75.2322, $respuesta->json('data.longitude'));

        $this->assertEquals(4.4389, $reunion->fresh()->latitude);
        $this->assertEquals(-75.2322, $reunion->fresh()->longitude);
    }

    public function test_el_listado_devuelve_las_coordenadas_que_el_mapa_necesita(): void
    {
        Meeting::factory()->forTenant($this->tenant)->create([
            'title' => 'Con ubicación',
            'latitude' => 4.4389,
            'longitude' => -75.2322,
        ]);
        Meeting::factory()->forTenant($this->tenant)->create([
            'title' => 'Sin ubicación',
            'latitude' => null,
            'longitude' => null,
        ]);

        $respuesta = $this->comoUsuarioCon(['view_meetings'])->getJson('/api/v1/meetings')->assertOk();

        $porTitulo = collect($respuesta->json('data'))->keyBy('title');

        $this->assertEquals(4.4389, $porTitulo['Con ubicación']['latitude']);
        $this->assertEquals(-75.2322, $porTitulo['Con ubicación']['longitude']);
        $this->assertNull($porTitulo['Sin ubicación']['latitude']);
        $this->assertNull($porTitulo['Sin ubicación']['longitude']);
    }

    public function test_las_coordenadas_viajan_como_texto_por_el_cast_decimal(): void
    {
        Meeting::factory()->forTenant($this->tenant)->create([
            'latitude' => 4.4389,
            'longitude' => -75.2322,
        ]);

        $respuesta = $this->comoUsuarioCon(['view_meetings'])->getJson('/api/v1/meetings')->assertOk();

        // `decimal:7` serializa a cadena, no a número. Es la razón de que el
        // filtro del mapa tenga que parsear en vez de confiar en el tipo, y por
        // eso queda fijado aquí: si algún día cambia el cast, el panel se entera
        // por esta prueba y no por un mapa vacío.
        $this->assertIsString($respuesta->json('data.0.latitude'));
        $this->assertSame('4.4389000', $respuesta->json('data.0.latitude'));
        $this->assertSame('-75.2322000', $respuesta->json('data.0.longitude'));
    }

    public function test_una_coordenada_fuera_de_rango_se_rechaza(): void
    {
        $reunion = Meeting::factory()->forTenant($this->tenant)->create();

        $this->comoUsuarioCon(['edit_meetings'])
            ->patchJson("/api/v1/meetings/{$reunion->id}", ['latitude' => 120, 'longitude' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitude');

        $this->assertNull($reunion->fresh()->latitude);
    }

    public function test_se_puede_quitar_la_ubicacion_de_una_reunion(): void
    {
        $reunion = Meeting::factory()->forTenant($this->tenant)->create([
            'latitude' => 4.4389,
            'longitude' => -75.2322,
        ]);

        $this->comoUsuarioCon(['edit_meetings'])
            ->patchJson("/api/v1/meetings/{$reunion->id}", ['latitude' => null, 'longitude' => null])
            ->assertOk();

        $this->assertNull($reunion->fresh()->latitude);
        $this->assertNull($reunion->fresh()->longitude);
    }

    public function test_no_se_editan_las_coordenadas_de_otra_campana(): void
    {
        $otro = Tenant::factory()->create();
        $ajena = Meeting::factory()->forTenant($otro)->create(['latitude' => null]);

        $this->comoUsuarioCon(['edit_meetings'])
            ->patchJson("/api/v1/meetings/{$ajena->id}", ['latitude' => 4.4389, 'longitude' => -75.2322])
            ->assertNotFound();

        $this->assertNull($ajena->fresh()->latitude);
    }
}
