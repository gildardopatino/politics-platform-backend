<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            RolesAndPermissionsSeeder::class,
            GeographySeeder::class,
            PrioritySeeder::class,
            // Datos de referencia que `voters` necesita (FK NOT NULL): faltaba y
            // por eso no se podía sembrar ningún votante (Spec 0003).
            TipoVotanteSeeder::class,
            DemoDataSeeder::class, // Demo data enabled
        ]);
    }
}
