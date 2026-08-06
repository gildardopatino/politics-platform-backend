<?php

namespace Tests\Feature\Routing;

use App\Models\Tenant;
use App\Models\Voter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Red de guardia del orden de rutas (Spec 0006).
 *
 * En un mismo prefijo, una ruta literal declarada DESPUÉS de la paramétrica
 * queda muerta: Laravel resuelve primero `{param}`, intenta bindear un modelo
 * con ese literal como id y responde 404. Fue el bug de `commitments/overdue`
 * (Spec 0001).
 *
 * Cada caso comprueba dos cosas:
 *  1. Que la URI **resuelve a la acción esperada** —lo que se rompería con un
 *     reordenamiento— y no a la paramétrica del mismo prefijo.
 *  2. Que la petición real no acaba en 404.
 *
 * La segunda sola no basta: un 404 puede venir de otras causas, y un 200 podría
 * venir de la ruta equivocada.
 */
class RouteResolutionTest extends TestCase
{
    private const CEDULA_DEMO = '71000001';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // `voters/search/by-cedula` devuelve 404 legítimo si no encuentra a nadie;
        // sin este votante no se distinguiría ese 404 del de un binding fallido.
        Voter::factory()->forTenant($this->tenant)->create(['cedula' => self::CEDULA_DEMO]);
    }

    /**
     * Literales que comparten prefijo con un `apiResource` y por tanto pueden ser
     * capturadas por su `{param}`.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function literalesEnRiesgo(): array
    {
        return [
            // prefijo `commitments` — el bug original de la Spec 0001
            'commitments/overdue' => ['/api/v1/commitments/overdue', 'CommitmentController@overdue', ['view_commitments']],

            // prefijo `attendees`
            'attendees/search' => ['/api/v1/attendees/search?q=ana', 'MeetingAttendeeController@searchAll', ['view_meetings']],

            // prefijo `attendee-hierarchies`
            'attendee-hierarchies/tree' => ['/api/v1/attendee-hierarchies/tree', 'AttendeeHierarchyController@tree', ['view_meetings']],
            'attendee-hierarchies/relationships' => ['/api/v1/attendee-hierarchies/relationships', 'AttendeeHierarchyController@relationships', ['view_meetings']],
            'attendee-hierarchies/stats' => ['/api/v1/attendee-hierarchies/stats', 'AttendeeHierarchyController@stats', ['view_meetings']],

            // prefijo `geographic-contacts`
            'geographic-contacts/tree' => ['/api/v1/geographic-contacts/tree', 'GeographicContactController@tree', ['manage_liaisons']],
            'geographic-contacts/all' => ['/api/v1/geographic-contacts/all', 'GeographicContactController@all', ['manage_liaisons']],

            // prefijo `audits`
            'audits/statistics' => ['/api/v1/audits/statistics', 'AuditController@statistics', ['view_audits']],
        ];
    }

    /**
     * Literales que hoy NO están en riesgo —usan guion o segmentos de más, así
     * que no comparten prefijo con ningún `{param}`— pero cuyo endpoint conviene
     * blindar igual: renombrarlos a `recurso/literal` los pondría en riesgo.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function literalesSinRiesgo(): array
    {
        return [
            'meetings/hierarchy/tree' => ['/api/v1/meetings/hierarchy/tree', 'MeetingController@getHierarchyTree', ['view_meetings']],
            'voters/search/by-cedula' => ['/api/v1/voters/search/by-cedula?cedula=71000001', 'VoterController@searchByCedula', ['view_voters']],
            'voters-stats' => ['/api/v1/voters-stats', 'VoterController@stats', ['view_voters']],
            'voters-by-voting-place' => ['/api/v1/voters-by-voting-place', 'VoterController@byVotingPlace', ['view_voters']],
            'calls-stats' => ['/api/v1/calls-stats', 'CallController@stats', ['view_calls']],
            'surveys-active' => ['/api/v1/surveys-active', 'SurveyController@active', ['view_calls']],
            'resource-items-low-stock' => ['/api/v1/resource-items-low-stock', 'ResourceItemController@lowStock', ['view_resources']],
        ];
    }

    /**
     * @param  array<int, string>  $permisos
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('literalesEnRiesgo')]
    public function test_una_literal_en_riesgo_resuelve_a_su_accion(string $uri, string $accion, array $permisos): void
    {
        $this->assertResuelveA($uri, $accion);
        $this->assertNoDa404($uri, $permisos);
    }

    /**
     * @param  array<int, string>  $permisos
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('literalesSinRiesgo')]
    public function test_una_literal_sin_riesgo_sigue_resolviendo_a_su_accion(string $uri, string $accion, array $permisos): void
    {
        $this->assertResuelveA($uri, $accion);
        $this->assertNoDa404($uri, $permisos);
    }

    public function test_las_rutas_parametricas_del_mismo_prefijo_siguen_resolviendo(): void
    {
        // El reordenamiento no puede dejar muerta la otra mitad.
        $this->assertResuelveA('/api/v1/commitments/7', 'CommitmentController@show');
        $this->assertResuelveA('/api/v1/attendees/7', 'MeetingAttendeeController@show');
        $this->assertResuelveA('/api/v1/geographic-contacts/7', 'GeographicContactController@show');
        $this->assertResuelveA('/api/v1/audits/7', 'AuditController@show');
        $this->assertResuelveA('/api/v1/attendee-hierarchies/7', 'AttendeeHierarchyController@update', 'PUT');
    }

    public function test_ninguna_ruta_de_la_api_queda_capturada_por_otra(): void
    {
        // Barrido completo: para cada ruta, se construye una URI concreta y se
        // comprueba que el router devuelve esa misma ruta y no otra.
        $rutas = Route::getRoutes();
        $sombras = [];

        foreach ($rutas as $ruta) {
            $metodos = array_values(array_diff($ruta->methods(), ['HEAD']));

            if (! $metodos || ! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }

            $concreta = preg_replace('/\{[^}]+\??\}/', '1', $ruta->uri());

            try {
                $encontrada = $rutas->match(Request::create('/'.$concreta, $metodos[0]));
            } catch (\Throwable $e) {
                $sombras[] = $metodos[0].' '.$ruta->uri().' → '.class_basename($e);

                continue;
            }

            if ($encontrada->uri() !== $ruta->uri()) {
                $sombras[] = $metodos[0].' '.$ruta->uri().' → la captura '.$encontrada->uri();
            }
        }

        $this->assertSame([], $sombras, "Rutas capturadas por otra:\n".implode("\n", $sombras));
    }

    public function test_no_hay_rutas_duplicadas(): void
    {
        // Dos declaraciones con el mismo método+URI: gana la primera y la segunda
        // es código muerto silencioso.
        $vistas = [];
        $duplicadas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD']) as $metodo) {
                $clave = $metodo.' '.$ruta->uri();

                if (isset($vistas[$clave])) {
                    $duplicadas[] = $clave;
                }

                $vistas[$clave] = true;
            }
        }

        $this->assertSame([], $duplicadas, 'Rutas declaradas más de una vez: '.implode(', ', $duplicadas));
    }

    private function assertResuelveA(string $uri, string $accionEsperada, string $metodo = 'GET'): void
    {
        $ruta = Route::getRoutes()->match(Request::create($uri, $metodo));

        $this->assertStringEndsWith(
            $accionEsperada,
            $ruta->getActionName(),
            "{$metodo} {$uri} debería resolver a {$accionEsperada} y resuelve a {$ruta->getActionName()} ({$ruta->uri()})."
        );
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function assertNoDa404(string $uri, array $permisos): void
    {
        [$user, $token] = $this->createTenantWithUser($permisos, $this->tenant);

        $status = $this->actingAsTenantUser($user, $token)->getJson($uri)->getStatusCode();

        $this->assertNotSame(
            404,
            $status,
            "{$uri} responde 404: probablemente la captura una ruta paramétrica del mismo prefijo."
        );
    }
}
