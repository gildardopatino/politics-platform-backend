<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GeographySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ejemplo: Departamentos de Colombia
        $antioquia = \App\Models\Department::create([
            'codigo' => '05',
            'nombre' => 'Antioquia',
            'latitud' => 6.2518,
            'longitud' => -75.5636,
        ]);

        $cundinamarca = \App\Models\Department::create([
            'codigo' => '25',
            'nombre' => 'Cundinamarca',
            'latitud' => 5.0263,
            'longitud' => -73.9950,
        ]);

        // Municipios de Antioquia
        $medellin = \App\Models\Municipality::create([
            'department_id' => $antioquia->id,
            'codigo' => '05001',
            'nombre' => 'Medellín',
            'latitud' => 6.2476,
            'longitud' => -75.5658,
        ]);

        // Municipios de Cundinamarca
        $bogota = \App\Models\Municipality::create([
            'department_id' => $cundinamarca->id,
            'codigo' => '11001',
            'nombre' => 'Bogotá D.C.',
            'latitud' => 4.7110,
            'longitud' => -74.0721,
        ]);

        // Comunas de Medellín (ejemplo)
        $comuna1 = \App\Models\Commune::create([
            'municipality_id' => $medellin->id,
            'codigo' => '01',
            'nombre' => 'Popular',
            'latitud' => 6.2997,
            'longitud' => -75.5519,
        ]);

        // Barrios de Comuna 1 (solo commune_id, sin municipality_id)
        \App\Models\Barrio::create([
            'commune_id' => $comuna1->id,
            'codigo' => '0101',
            'nombre' => 'Santo Domingo Savio No.1',
            'latitud' => 6.3032,
            'longitud' => -75.5499,
        ]);

        \App\Models\Barrio::create([
            'commune_id' => $comuna1->id,
            'codigo' => '0102',
            'nombre' => 'Santo Domingo Savio No.2',
            'latitud' => 6.3012,
            'longitud' => -75.5479,
        ]);
        
        // Barrio directo del municipio (sin comuna)
        \App\Models\Barrio::create([
            'municipality_id' => $medellin->id,
            'codigo' => '9999',
            'nombre' => 'Barrio El Centro',
            'latitud' => 6.2442,
            'longitud' => -75.5812,
        ]);
        
        // Corregimientos de Medellín (zona rural)
        $corregimiento1 = \App\Models\Corregimiento::create([
            'municipality_id' => $medellin->id,
            'codigo' => 'C01',
            'nombre' => 'San Sebastián de Palmitas',
            'latitud' => 6.2875,
            'longitud' => -75.6580,
        ]);
        
        // Veredas del corregimiento
        \App\Models\Vereda::create([
            'municipality_id' => $medellin->id,
            'corregimiento_id' => $corregimiento1->id,
            'codigo' => 'V001',
            'nombre' => 'La Aldea',
            'latitud' => 6.2885,
            'longitud' => -75.6590,
        ]);

        // Add more geography data as needed
    }
}
