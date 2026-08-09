<?php

namespace Tests\Unit\Services;

use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocodeResult;
use App\Services\Geocoding\NominatimGeocoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Geocodificación con Nominatim (Spec 0055).
 *
 * Nominatim es el servicio de OpenStreetMap y sustituye a la API de Google: no
 * pide clave, pero sí exige portarse bien —identificarse, no pasar de una
 * petición por segundo y no repetir consultas—, y eso es justo lo que estas
 * pruebas fijan junto con el mapeo de la respuesta.
 */
class NominatimGeocoderTest extends TestCase
{
    private const URL = 'https://nominatim.openstreetmap.org';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.geocoding.nominatim_url', self::URL);
        config()->set('services.geocoding.user_agent', 'SuiteElectoral/1.0');
        config()->set('services.geocoding.contact_email', 'soporte@suite-electoral.co');
        config()->set('services.geocoding.cache_ttl', 3600);
    }

    private function geocoder(): NominatimGeocoder
    {
        return app(NominatimGeocoder::class);
    }

    private function respuesta(array $resultados): void
    {
        Http::fake([self::URL.'/search*' => Http::response($resultados, 200)]);
    }

    public function test_el_contenedor_resuelve_el_proveedor_configurado(): void
    {
        $this->assertInstanceOf(NominatimGeocoder::class, app(Geocoder::class));
    }

    public function test_devuelve_las_coordenadas_del_primer_resultado(): void
    {
        $this->respuesta([
            [
                'lat' => '4.4389',
                'lon' => '-75.2322',
                'display_name' => 'Ibagué, Tolima, Colombia',
            ],
            [
                'lat' => '10.0',
                'lon' => '-70.0',
                'display_name' => 'Otro sitio que no queremos',
            ],
        ]);

        $resultado = $this->geocoder()->resolve('Calle 15 #3-40, Ibagué');

        $this->assertInstanceOf(GeocodeResult::class, $resultado);
        $this->assertSame(4.4389, $resultado->latitude);
        $this->assertSame(-75.2322, $resultado->longitude);
        $this->assertSame('Ibagué, Tolima, Colombia', $resultado->formattedAddress);
    }

    public function test_pide_resultados_de_colombia_y_se_identifica(): void
    {
        $this->respuesta([['lat' => '1', 'lon' => '2', 'display_name' => 'X']]);

        $this->geocoder()->resolve('Parque Murillo Toro');

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_starts_with($url, self::URL.'/search')
                && str_contains($url, 'countrycodes=co')
                && str_contains($url, 'format=jsonv2')
                && str_contains($url, 'limit=1')
                && str_contains($url, 'accept-language=es')
                && str_contains($request->header('User-Agent')[0], 'SuiteElectoral/1.0')
                && str_contains($request->header('User-Agent')[0], 'soporte@suite-electoral.co');
        });
    }

    public function test_sin_resultados_devuelve_null(): void
    {
        $this->respuesta([]);

        $this->assertNull($this->geocoder()->resolve('Dirección que no existe en ninguna parte'));
    }

    public function test_una_respuesta_incompleta_no_se_toma_como_valida(): void
    {
        $this->respuesta([['display_name' => 'Sitio sin coordenadas']]);

        $this->assertNull($this->geocoder()->resolve('Sitio raro'));
    }

    public function test_el_error_del_proveedor_sube_como_excepcion(): void
    {
        Http::fake([self::URL.'/search*' => Http::response('boom', 500)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $this->geocoder()->resolve('Calle 15 #3-40, Ibagué');
    }

    public function test_la_caida_de_red_sube_como_excepcion(): void
    {
        Http::fake(fn () => throw new ConnectionException('sin red'));

        $this->expectException(ConnectionException::class);

        $this->geocoder()->resolve('Calle 15 #3-40, Ibagué');
    }

    public function test_la_misma_direccion_no_se_consulta_dos_veces(): void
    {
        Cache::flush();
        $this->respuesta([['lat' => '4.4', 'lon' => '-75.2', 'display_name' => 'Ibagué']]);

        $primero = $this->geocoder()->resolve('Calle 15 #3-40, Ibagué');
        $segundo = $this->geocoder()->resolve('  calle 15 #3-40, IBAGUÉ  ');

        Http::assertSentCount(1);
        $this->assertEquals($primero, $segundo);
    }

    public function test_tambien_recuerda_que_una_direccion_no_existe(): void
    {
        Cache::flush();
        $this->respuesta([]);

        $this->assertNull($this->geocoder()->resolve('Dirección inventada'));
        $this->assertNull($this->geocoder()->resolve('Dirección inventada'));

        // Sin esto, una dirección mal escrita pediría a Nominatim una vez por
        // cada intento del usuario, que es justo lo que su política prohíbe.
        Http::assertSentCount(1);
    }
}
