<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fixture de geografía (Spec 0059)
    |--------------------------------------------------------------------------
    |
    | De aquí carga `GeographySeeder` el árbol real con los `path` de los SVG.
    | Se genera con `php artisan geo:export`. Si el archivo no existe, el seeder
    | cae a una geografía mínima de demostración.
    |
    */

    'fixture' => env('GEOGRAPHY_FIXTURE', database_path('seeders/data/geography.json')),
];
