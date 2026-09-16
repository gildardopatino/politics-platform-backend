<?php

namespace Tests\Feature\Seeders;

use App\Models\Occupation;
use App\Models\OccupationAlias;
use Database\Seeders\OccupationsSeeder;
use Tests\TestCase;

/**
 * Catálogo de oficios sembrado e idempotente (Spec 0094, Art. VIII).
 */
class OccupationsSeederTest extends TestCase
{
    public function test_siembra_el_catalogo_con_sus_alias(): void
    {
        $this->seed(OccupationsSeeder::class);

        $this->assertDatabaseHas('occupations', ['nombre' => 'Vigilante', 'activo' => true]);

        $vigilante = Occupation::where('nombre', 'Vigilante')->first();

        $this->assertDatabaseHas('occupation_aliases', [
            'alias' => 'celador',
            'occupation_id' => $vigilante->id,
        ]);
    }

    public function test_correrlo_dos_veces_no_duplica_nada(): void
    {
        $this->seed(OccupationsSeeder::class);

        $oficios = Occupation::count();
        $alias = OccupationAlias::count();

        $this->seed(OccupationsSeeder::class);

        $this->assertSame($oficios, Occupation::count());
        $this->assertSame($alias, OccupationAlias::count());
    }

    public function test_ningun_alias_apunta_a_dos_oficios(): void
    {
        // Un sinónimo ambiguo haría que la misma búsqueda devolviera cosas
        // distintas según el orden de lectura.
        $alias = [];

        foreach (OccupationsSeeder::CATALOGO as $nombre => $sinonimos) {
            foreach ($sinonimos as $sinonimo) {
                $this->assertArrayNotHasKey(
                    $sinonimo,
                    $alias,
                    "«{$sinonimo}» está en «{$nombre}» y también en «".($alias[$sinonimo] ?? '').'».'
                );

                $alias[$sinonimo] = $nombre;
            }
        }
    }
}
