<?php

namespace Tests\Unit\Services\Registraduria;

use App\Services\Registraduria\RegistraduriaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El cliente del servicio de Registraduría (Spec 0091, Parte B).
 *
 * Es la única pieza que conoce el contrato del servicio Python, así que aquí se
 * fija lo que el resto del sistema da por hecho: que los tres desenlaces llegan
 * distinguidos —`no_encontrado` **no** es un fallo, o el Job lo reintentaría
 * pagando 2Captcha por volver a oír que no existe— y que la cédula no sale
 * escrita en ningún log.
 *
 * Sin red: `Http::fake` en todos los casos.
 */
class RegistraduriaClientTest extends TestCase
{
    private const URL = 'http://127.0.0.1:8100';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.registraduria.url', self::URL);
        config()->set('services.registraduria.token', 'token-de-prueba');
        config()->set('services.registraduria.timeout', 320);

        Http::preventStrayRequests();
    }

    private function cliente(): RegistraduriaClient
    {
        return app(RegistraduriaClient::class);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function fila(array $cambios = []): array
    {
        return array_replace([
            'NUIP' => '14398737',
            'DEPARTAMENTO' => 'TOLIMA',
            'MUNICIPIO' => 'IBAGUE',
            'PUESTO' => 'COLEGIO SAN SIMON',
            'DIRECCION' => 'CALLE 60 CON CARRERA 5',
            'MESA' => '12',
        ], $cambios);
    }

    public function test_encontrado_traduce_la_fila_a_columnas_del_votante(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'encontrado',
                'via' => 'stealth',
                'datos' => [$this->fila()],
            ]),
        ]);

        $resultado = $this->cliente()->consultar('14398737');

        $this->assertTrue($resultado->esEncontrado());
        $this->assertSame('stealth', $resultado->via);
        $this->assertSame([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
            'direccion_votacion' => 'CALLE 60 CON CARRERA 5',
            'mesa_votacion' => '12',
        ], $resultado->datos);
    }

    public function test_la_cedula_viaja_normalizada_y_con_el_token(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'encontrado', 'via' => 'stealth', 'datos' => [$this->fila()],
            ]),
        ]);

        // El servicio espera solo dígitos; la base tiene cédulas capturadas con
        // puntos y guiones desde antes de esta spec.
        $this->cliente()->consultar('1.439.873-7');

        Http::assertSent(fn (Request $peticion) => $peticion->url() === self::URL.'/api/consultar'
            && $peticion['documento'] === '14398737'
            && $peticion->hasHeader('Authorization', 'Bearer token-de-prueba'));
    }

    public function test_no_encontrado_no_es_un_fallo(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'no_encontrado', 'via' => 'stealth', 'datos' => [],
            ]),
        ]);

        $resultado = $this->cliente()->consultar('14398737');

        // La distinción es de coste: reintentar esto es pagar por confirmar que
        // la Registraduría no tiene la cédula.
        $this->assertTrue($resultado->esNoEncontrado());
        $this->assertFalse($resultado->esFallo());
        $this->assertSame([], $resultado->datos);
    }

    public function test_captcha_fallido_es_fallo_reintentable(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'captcha_fallido', 'via' => null, 'datos' => [],
            ], 502),
        ]);

        $resultado = $this->cliente()->consultar('14398737');

        $this->assertTrue($resultado->esFallo());
        $this->assertSame('captcha_fallido', $resultado->motivo);
    }

    public function test_el_techo_de_tiempo_del_servicio_es_fallo_reintentable(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $resultado = $this->cliente()->consultar('14398737');

        $this->assertTrue($resultado->esFallo());
        $this->assertSame('sin_conexion', $resultado->motivo);
    }

    public function test_token_rechazado_es_fallo_y_no_se_confunde_con_no_encontrado(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response(['detalle' => 'Token ausente o inválido.'], 401),
        ]);

        $resultado = $this->cliente()->consultar('14398737');

        // Sin cuerpo con `estado`, queda el código: un 401 mal leído como
        // «no encontrado» dejaría a toda la campaña sin puesto en silencio.
        $this->assertTrue($resultado->esFallo());
        $this->assertSame('http_401', $resultado->motivo);
    }

    public function test_encontrado_sin_datos_se_trata_como_fallo(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response(['estado' => 'encontrado', 'via' => 'stealth', 'datos' => []]),
        ]);

        $resultado = $this->cliente()->consultar('14398737');

        // El servicio contradiciéndose. Mejor reintentar que escribirle nulos
        // encima a un votante.
        $this->assertTrue($resultado->esFallo());
        $this->assertSame('respuesta_sin_datos', $resultado->motivo);
    }

    public function test_sin_url_configurada_no_sale_a_la_red(): void
    {
        config()->set('services.registraduria.url', null);

        $resultado = $this->cliente()->consultar('14398737');

        $this->assertFalse($this->cliente()->configurado());
        $this->assertTrue($resultado->esFallo());
        $this->assertSame('servicio_no_configurado', $resultado->motivo);
        // `preventStrayRequests` ya lo garantizaría, pero se deja explícito.
        Http::assertNothingSent();
    }

    public function test_una_cedula_sin_digitos_no_llega_a_consultarse(): void
    {
        $resultado = $this->cliente()->consultar('   ');

        $this->assertTrue($resultado->esFallo());
        $this->assertSame('documento_vacio', $resultado->motivo);
        Http::assertNothingSent();
    }

    public function test_la_cedula_se_enmascara_para_los_logs(): void
    {
        // Art. VII: los logs sirven para seguir un caso, no para llevarse la
        // base de datos.
        $this->assertSame('14****37', RegistraduriaClient::enmascarar('14398737'));
        $this->assertSame('****', RegistraduriaClient::enmascarar('1234'));
        $this->assertSame('12*45', RegistraduriaClient::enmascarar('12345'));
    }
}
