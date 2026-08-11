<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Deja un solo candidato por campaña (Spec 0080 · RF-5).
 *
 * Es el mismo comando `e14:candidato-unico`, enganchado al sembrado para que una
 * base traída de un entorno donde estuvo la pantalla vieja quede consistente sin
 * depender de que alguien se acuerde de correrlo. Sobre datos recién sembrados no
 * hay nada que limpiar y la corrida es un no-op.
 */
class CandidatoUnicoSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('e14:candidato-unico');

        $this->command?->getOutput()->write(Artisan::output());
    }
}
