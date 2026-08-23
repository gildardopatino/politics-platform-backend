<?php

namespace Tests\Unit\Services\Registraduria;

use App\Services\Registraduria\RegistraduriaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cliente del servicio propio de Registraduría (Spec 0091, Parte B).
 *
 * Sustituye a n8n: ahora Laravel llama **de salida**. Estas pruebas fijan el
 * mapeo del contrato de la Parte A (`estado` → resultado tipado), que el
 * servicio apagado no salga a la red, y que la cédula no aparezca en los logs
 * (Art. VII).
 */
class RegistraduriaClientTest extends TestCase
{
    private const URL = 'http://127.0.0.1:8100';

    private const CEDULA = '14398737';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.registraduria.url', self::URL);
        config()->set('services.registraduria.token', 'token-de-prueba');
        config()->set('services.registraduria.timeout', 30);
    }

    private function cliente(): RegistraduriaClient
    {
        return app(RegistraduriaClient::class);
    }

    private function datosDeMuestra(): array
    {
        return [[
            'NUIP' => self::CEDULA,
            'DEPARTAMENTO' => 'TOLIMA',
            'MUNICIPIO' => 'IBAGUE',
            'PUESTO' => 'INSTITUCION EDUCATIVA SAN SIMON',
            'DIRECCION' => 'CALLE 60 CON CARRERA 5',
            'MESA' => '12',
        ]];
    }

    public function test_encontrado_devuelve_los_datos_del_puesto(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'encontrado',
                'via' => 'stealth',
                'datos' => $this->datosDeMuestra(),
            ], 200),
        ]);

        $resultado = $this->cliente()->consultar(self::CEDULA);

        $this->assertTrue($resultado->esEncontrado());
        $this->assertSame('stealth', $resultado->via);
        $this->assertSame($this->datosDeMuestra(), $resultado->datos);
    }

    public function test_manda_el_token_y_el_documento_al_servicio(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'encontrado', 'via' => '2captcha', 'datos' => $this->datosDeMuestra(),
            ], 200),
        ]);

        $this->cliente()->consultar(self::CEDULA);

        Http::assertSent(function ($peticion) {
            return $peticion->url() === self::URL.'/api/consultar'
                && $peticion->method() === 'POST'
                && $peticion['documento'] === self::CEDULA
                && $peticion->hasHeader('Authorization', 'Bearer token-de-prueba');
        });
    }

    public function test_no_encontrado_no_es_un_fallo(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'no_encontrado', 'via' => 'stealth', 'datos' => [],
            ], 200),
        ]);

        $resultado = $this->cliente()->consultar(self::CEDULA);

        $this->assertTrue($resultado->esNoEncontrado());
        $this->assertFalse($resultado->haFallado());
        $this->assertSame([], $resultado->datos);
    }

    public function test_captcha_fallido_es_un_fallo_reintentable(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'captcha_fallido', 'via' => null, 'datos' => [],
            ], 502),
        ]);

        $resultado = $this->cliente()->consultar(self::CEDULA);

        $this->assertTrue($resultado->haFallado());
        $this->assertStringContainsString('captcha_fallido', (string) $resultado->motivo);
    }

    public function test_un_timeout_del_servicio_es_un_fallo(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $resultado = $this->cliente()->consultar(self::CEDULA);

        $this->assertTrue($resultado->haFallado());
        $this->assertFalse($resultado->esEncontrado());
    }

    public function test_un_401_del_servicio_es_un_fallo(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response(['detalle' => 'Token ausente o inválido.'], 401),
        ]);

        $this->assertTrue($this->cliente()->consultar(self::CEDULA)->haFallado());
    }

    public function test_sin_servicio_configurado_no_sale_a_la_red(): void
    {
        config()->set('services.registraduria.url', null);
        Http::fake();

        $resultado = $this->cliente()->consultar(self::CEDULA);

        $this->assertTrue($resultado->haFallado());
        Http::assertNothingSent();
    }

    public function test_una_cedula_vacia_no_sale_a_la_red(): void
    {
        Http::fake();

        $resultado = $this->cliente()->consultar('   ');

        $this->assertTrue($resultado->haFallado());
        Http::assertNothingSent();
    }

    public function test_normaliza_la_cedula_antes_de_consultar(): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response([
                'estado' => 'encontrado', 'via' => 'stealth', 'datos' => $this->datosDeMuestra(),
            ], 200),
        ]);

        $this->cliente()->consultar(' 1.439.873-7 ');

        Http::assertSent(fn ($peticion) => $peticion['documento'] === self::CEDULA);
    }

    public function test_la_cedula_nunca_se_registra_en_claro(): void
    {
        $registrado = [];
        Log::listen(function ($mensaje) use (&$registrado) {
            $registrado[] = $mensaje->message.' '.json_encode($mensaje->context);
        });

        Http::fake(function () {
            throw new ConnectionException('falló al consultar '.self::CEDULA);
        });

        $resultado = $this->cliente()->consultar(self::CEDULA);

        $this->assertTrue($resultado->haFallado());
        $this->assertNotEmpty($registrado, 'el fallo debería quedar registrado');

        foreach ($registrado as $linea) {
            $this->assertStringNotContainsString(self::CEDULA, $linea);
        }

        $this->assertStringNotContainsString(self::CEDULA, (string) $resultado->motivo);
    }
}
