<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * Los comandos de respaldo de leads (Spec 0059).
 *
 * El respaldo en sí solo tiene sentido contra PostgreSQL —usa `pg_dump` sobre
 * millones de filas—, así que lo que se puede fijar en la suite (SQLite) es que
 * no haga nada raro fuera de su terreno y que avise en vez de reventar.
 */
class LeadsBackupCommandTest extends TestCase
{
    public function test_el_respaldo_se_niega_fuera_de_postgres(): void
    {
        // La suite corre en SQLite: sin esta guarda el comando intentaría lanzar
        // pg_dump contra un archivo `:memory:`.
        $this->artisan('leads:backup')
            ->expectsOutputToContain('pg_dump')
            ->assertFailed();
    }

    public function test_la_restauracion_se_niega_fuera_de_postgres(): void
    {
        $this->artisan('leads:restore', ['archivo' => 'cualquiera.sql'])
            ->expectsOutputToContain('psql')
            ->assertFailed();
    }

    public function test_la_carpeta_de_respaldos_esta_ignorada_por_git(): void
    {
        // Es la línea que impide que 3,9 millones de cédulas acaben en un
        // repositorio con remoto público. Si alguien la quita, esto lo dice.
        $gitignore = file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('storage/backups', $gitignore);
    }
}
