<?php

namespace Tests\Feature\VoterProfiles;

use Database\Seeders\OccupationsSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El SQL manual de producción y las migraciones dicen lo mismo (Spec 0094 §7).
 *
 * En producción el esquema se aplica a mano y las migraciones solo se marcan
 * como corridas. Si las dos entregas se separan, la base de producción y la de
 * la suite dejan de ser la misma y nadie se entera hasta que algo falla allá.
 */
class SqlManualTest extends TestCase
{
    private const MIGRACIONES = [
        '2026_09_16_120000_create_occupations_table',
        '2026_09_16_120100_create_occupation_aliases_table',
        '2026_09_16_120200_create_voter_profiles_table',
        '2026_09_16_120300_create_voter_occupations_table',
    ];

    public function test_las_cuatro_migraciones_existen(): void
    {
        foreach (self::MIGRACIONES as $migracion) {
            $this->assertFileExists(database_path("migrations/{$migracion}.php"));
        }
    }

    public function test_las_cuatro_tablas_quedan_creadas(): void
    {
        foreach (['occupations', 'occupation_aliases', 'voter_profiles', 'voter_occupations'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
        }
    }

    public function test_el_sql_manual_marca_las_migraciones_como_aplicadas(): void
    {
        $sql = $this->sql();

        $this->assertStringContainsString('INSERT INTO migrations', $sql);

        foreach (self::MIGRACIONES as $migracion) {
            $this->assertStringContainsString(
                "'{$migracion}'",
                $sql,
                "El SQL manual no marca `{$migracion}` como aplicada: un `migrate` posterior intentaría recrear la tabla."
            );
        }
    }

    public function test_el_sql_manual_crea_las_cuatro_tablas(): void
    {
        $sql = $this->sql();

        foreach (['occupations', 'occupation_aliases', 'voter_profiles', 'voter_occupations'] as $tabla) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS {$tabla}", $sql);
        }
    }

    public function test_el_sql_manual_trae_el_mismo_catalogo_que_el_seeder(): void
    {
        $sql = $this->sql();

        foreach (OccupationsSeeder::CATALOGO as $nombre => $alias) {
            $this->assertStringContainsString("'{$nombre}'", $sql, "Falta el oficio «{$nombre}» en el SQL manual.");

            foreach ($alias as $sinonimo) {
                $this->assertStringContainsString(
                    "'{$sinonimo}'",
                    $sql,
                    "Falta el sinónimo «{$sinonimo}» en el SQL manual."
                );
            }
        }
    }

    private function sql(): string
    {
        $ruta = database_path('sql/0094-perfil-laboral.sql');

        $this->assertFileExists($ruta);

        return file_get_contents($ruta);
    }
}
