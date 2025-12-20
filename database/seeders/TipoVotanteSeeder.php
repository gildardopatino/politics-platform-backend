<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoVotanteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        DB::table('tipo_votante')->insert([
            ['id' => 1, 'descripcion' => 'Elector', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'descripcion' => 'Presidente', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'descripcion' => 'Lider', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'descripcion' => 'Normal', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
