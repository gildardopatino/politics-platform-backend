<?php

namespace Database\Seeders;

use App\Models\TipoVotante;
use Illuminate\Database\Seeder;

class TipoVotanteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotente (firstOrCreate por descripción): `voters.tipo_votante_id` es
     * NOT NULL con FK a esta tabla y default 1, así que sin estas filas no se
     * puede crear ningún votante.
     */
    public function run(): void
    {
        foreach (['Elector', 'Presidente', 'Lider', 'Normal'] as $descripcion) {
            TipoVotante::firstOrCreate(['descripcion' => $descripcion]);
        }
    }
}
