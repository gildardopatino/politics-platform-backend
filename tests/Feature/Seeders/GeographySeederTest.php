<?php

namespace Tests\Feature\Seeders;

use App\Models\Barrio;
use App\Models\Commune;
use App\Models\Corregimiento;
use App\Models\Department;
use App\Models\Municipality;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El seeder de geografía (Spec 0059).
 *
 * La geografía real —con los `path` de los SVG que dibuja el mapa— vive en un
 * fixture versionado, porque `migrate:fresh --seed` la borraba y el seeder solo
 * sabía crear dos departamentos de juguete. Estas pruebas fijan que el reseed la
 * repone, que se puede repetir sin duplicar y que sigue funcionando cuando el
 * fixture no está.
 */
class GeographySeederTest extends TestCase
{
    private function sembrarCon(array $fixture): void
    {
        $ruta = $this->rutaTemporal();
        file_put_contents($ruta, json_encode($fixture));

        try {
            $this->seed(GeographySeeder::class);
        } finally {
            @unlink($ruta);
        }
    }

    private function rutaTemporal(): string
    {
        $ruta = database_path('seeders/data/geography.testing.json');
        config()->set('geography.fixture', $ruta);

        return $ruta;
    }

    private function fixtureMinimo(): array
    {
        return [
            'departments' => [
                ['codigo' => '73', 'nombre' => 'Tolima', 'latitude' => '4.4389', 'longitude' => '-75.2322', 'path' => null],
            ],
            'municipalities' => [
                [
                    'department_codigo' => '73',
                    'codigo' => '73001',
                    'nombre' => 'Ibagué',
                    'latitude' => '4.4389',
                    'longitude' => '-75.2322',
                    'path' => 'm 10,10 c 1,1 2,2 3,3 z',
                ],
            ],
            'communes' => [
                ['municipality_codigo' => '73001', 'codigo' => '01', 'nombre' => 'Comuna 1', 'path' => 'm 1,1 z'],
            ],
            'corregimientos' => [
                ['municipality_codigo' => '73001', 'codigo' => 'C01', 'nombre' => 'Toche', 'path' => null],
            ],
            'barrios' => [
                ['municipality_codigo' => '73001', 'commune_codigo' => '01', 'codigo' => 'B01', 'nombre' => 'El Centro', 'path' => null],
            ],
            'veredas' => [
                ['municipality_codigo' => '73001', 'corregimiento_codigo' => 'C01', 'codigo' => 'V01', 'nombre' => 'La Aldea', 'path' => null],
            ],
        ];
    }

    public function test_el_reseed_repone_la_geografia_con_su_contorno(): void
    {
        $this->sembrarCon($this->fixtureMinimo());

        $municipio = Municipality::where('codigo', '73001')->firstOrFail();

        $this->assertSame('Ibagué', $municipio->nombre);
        // El `path` es lo que dibuja el mapa: si no vuelve, la pantalla de
        // Geografía queda en blanco aunque los nombres estén.
        $this->assertNotEmpty(DB::table('municipalities')->where('codigo', '73001')->value('path'));
    }

    public function test_las_relaciones_se_rehacen_por_codigo_no_por_id(): void
    {
        // Un id de otra base no significa nada aquí; el fixture referencia al
        // padre por su código y el seeder lo resuelve contra lo que acaba de
        // insertar.
        Department::create(['codigo' => '99', 'nombre' => 'Relleno']);

        $this->sembrarCon($this->fixtureMinimo());

        $tolima = Department::where('codigo', '73')->firstOrFail();
        $ibague = Municipality::where('codigo', '73001')->firstOrFail();
        $comuna = Commune::where('codigo', '01')->firstOrFail();
        $corregimiento = Corregimiento::where('codigo', 'C01')->firstOrFail();

        $this->assertSame($tolima->id, $ibague->department_id);
        $this->assertSame($ibague->id, $comuna->municipality_id);
        $this->assertSame($ibague->id, $corregimiento->municipality_id);
        $this->assertSame($comuna->id, Barrio::where('codigo', 'B01')->value('commune_id'));
        $this->assertSame(
            $corregimiento->id,
            DB::table('veredas')->where('codigo', 'V01')->value('corregimiento_id')
        );
    }

    public function test_sembrar_dos_veces_no_duplica_ni_pierde_el_contorno(): void
    {
        $this->sembrarCon($this->fixtureMinimo());
        $this->sembrarCon($this->fixtureMinimo());

        $this->assertSame(1, Municipality::where('codigo', '73001')->count());
        $this->assertSame(1, Department::where('codigo', '73')->count());
        $this->assertNotEmpty(DB::table('municipalities')->where('codigo', '73001')->value('path'));
    }

    public function test_un_fixture_actualizado_corrige_lo_que_ya_estaba(): void
    {
        $this->sembrarCon($this->fixtureMinimo());

        $nuevo = $this->fixtureMinimo();
        $nuevo['municipalities'][0]['nombre'] = 'Ibagué (corregido)';
        $nuevo['municipalities'][0]['path'] = 'm 99,99 z';

        $this->sembrarCon($nuevo);

        $municipio = DB::table('municipalities')->where('codigo', '73001')->first();
        $this->assertSame('Ibagué (corregido)', $municipio->nombre);
        $this->assertSame('m 99,99 z', $municipio->path);
    }

    public function test_sin_fixture_cae_al_demo_minimo_y_no_revienta(): void
    {
        // Es lo que corre en las pruebas de otras specs y en cualquier entorno
        // que clone el repo sin el archivo: tiene que quedar algo con lo que
        // trabajar, no una excepción.
        config()->set('geography.fixture', database_path('seeders/data/no-existe.json'));

        $this->seed(GeographySeeder::class);

        $this->assertGreaterThan(0, Department::count());
        $this->assertGreaterThan(0, Municipality::count());
    }

    public function test_el_fixture_del_repositorio_trae_la_geografia_real(): void
    {
        $ruta = database_path('seeders/data/geography.json');
        $this->assertFileExists($ruta, 'el fixture versionado tiene que estar en el repo');

        $fixture = json_decode(file_get_contents($ruta), true);

        $this->assertNotEmpty($fixture['municipalities']);
        $this->assertGreaterThan(
            0,
            collect($fixture['municipalities'])->filter(fn ($m) => ! empty($m['path']))->count(),
            'sin paths el mapa no dibuja nada'
        );

        // Y que siga sin llevar datos de personas: es la condición para poder
        // versionarlo (Spec 0059).
        $claves = collect($fixture)
            ->only(['departments', 'municipalities', 'communes', 'corregimientos', 'barrios', 'veredas'])
            ->flatten(1)
            ->flatMap(fn ($fila) => array_keys($fila))
            ->unique()
            ->values()
            ->all();

        $permitidas = [
            'codigo', 'nombre', 'latitud', 'longitud', 'latitude', 'longitude', 'path',
            'department_codigo', 'municipality_codigo', 'commune_codigo', 'corregimiento_codigo',
        ];

        $this->assertEmpty(
            array_diff($claves, $permitidas),
            'el fixture ganó columnas nuevas: revisar que no sean datos de personas'
        );
    }
}
