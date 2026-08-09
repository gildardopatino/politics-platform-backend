<?php

namespace Tests\Feature\Geocode;

use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocodeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Contrato de `POST /api/v1/geocode` (Spec 0055).
 *
 * El proveedor cambió de Google a Nominatim, pero el frontend no se entera: el
 * buscador de direcciones de `MeetingForm` sigue mandando `{address}` y
 * esperando `{data:{latitude,longitude,formatted_address,original_address}}`.
 * Esta prueba es lo que impide que el cambio de proveedor mueva el contrato.
 */
class GeocodeEndpointTest extends TestCase
{
    private function autenticar(): void
    {
        [$user, $token] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $token);
    }

    private function fingirProveedor(): Mockery\MockInterface
    {
        $doble = Mockery::mock(Geocoder::class);
        $this->app->instance(Geocoder::class, $doble);

        return $doble;
    }

    public function test_devuelve_la_misma_forma_que_esperaba_el_frontend(): void
    {
        $this->autenticar();
        $this->fingirProveedor()
            ->shouldReceive('resolve')
            ->once()
            ->with('Calle 15 #3-40, Ibagué')
            ->andReturn(new GeocodeResult(4.4389, -75.2322, 'Ibagué, Tolima, Colombia'));

        $respuesta = $this->postJson('/api/v1/geocode', ['address' => 'Calle 15 #3-40, Ibagué']);

        $respuesta->assertOk()->assertExactJson([
            'data' => [
                'latitude' => 4.4389,
                'longitude' => -75.2322,
                'formatted_address' => 'Ibagué, Tolima, Colombia',
                'original_address' => 'Calle 15 #3-40, Ibagué',
            ],
        ]);
    }

    public function test_sin_resultados_responde_404_con_la_direccion_pedida(): void
    {
        $this->autenticar();
        $this->fingirProveedor()->shouldReceive('resolve')->once()->andReturnNull();

        $this->postJson('/api/v1/geocode', ['address' => 'Dirección inventada'])
            ->assertNotFound()
            ->assertJson([
                'message' => 'No se pudo geocodificar la dirección.',
                'address' => 'Dirección inventada',
            ]);
    }

    public function test_un_fallo_del_proveedor_responde_500_sin_filtrar_detalles(): void
    {
        $this->autenticar();
        Log::spy();
        $this->fingirProveedor()
            ->shouldReceive('resolve')
            ->once()
            ->andThrow(new ConnectionException('nominatim.openstreetmap.org: connection refused'));

        $respuesta = $this->postJson('/api/v1/geocode', ['address' => 'Calle 15 #3-40, Ibagué']);

        $respuesta->assertStatus(500)
            ->assertJson(['message' => 'Error al procesar la geocodificación.']);

        // El detalle interno se registra, pero no viaja al cliente: antes iba en
        // el cuerpo de la respuesta.
        $this->assertStringNotContainsString('connection refused', $respuesta->getContent());
        $this->assertStringNotContainsString('nominatim', mb_strtolower($respuesta->getContent()));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_la_direccion_es_obligatoria(): void
    {
        $this->autenticar();

        $this->postJson('/api/v1/geocode', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
    }

    public function test_sin_sesion_no_se_geocodifica(): void
    {
        $this->postJson('/api/v1/geocode', ['address' => 'Calle 15 #3-40, Ibagué'])
            ->assertUnauthorized();
    }
}
