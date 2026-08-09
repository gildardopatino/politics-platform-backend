<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restaura un respaldo de `leads` tras un reseed (Spec 0059).
 *
 * El par de `leads:backup`. Lo que hace es simple —pasar el `.sql` por psql—
 * pero está aquí para que restaurar no dependa de que alguien recuerde la línea
 * de comandos con la contraseña correcta.
 */
class RestoreLeads extends Command
{
    protected $signature = 'leads:restore
                            {archivo : Nombre del archivo en storage/backups, o una ruta completa}
                            {--force : No preguntar}';

    protected $description = 'Restaura un respaldo local de leads sobre la base actual';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('La restauración usa psql: solo aplica sobre PostgreSQL.');

            return self::FAILURE;
        }

        $archivo = $this->argument('archivo');
        $ruta = is_file($archivo) ? $archivo : storage_path('backups'.DIRECTORY_SEPARATOR.$archivo);

        if (! is_file($ruta)) {
            $this->error("No existe el respaldo «{$ruta}».");

            return self::FAILURE;
        }

        $psql = config('database.psql_path') ?: 'psql';
        $conexion = config('database.connections.'.config('database.default'));
        $existentes = DB::table('leads')->count();

        // Restaurar encima de datos que ya están duplica: el respaldo trae las
        // filas con su id, y `leads` no tiene una clave natural que lo impida.
        if ($existentes > 0 && ! $this->option('force')) {
            $this->warn("La tabla «leads» ya tiene {$existentes} filas en «{$conexion['database']}».");

            if (! $this->confirm('Restaurar encima puede duplicar. ¿Seguir?', false)) {
                return self::FAILURE;
            }
        }

        $this->info('Restaurando '.basename($ruta).' en «'.$conexion['database'].'»…');

        $comando = [
            $psql,
            '--host='.$this->host($conexion['host']),
            '--port='.$conexion['port'],
            '--username='.$conexion['username'],
            '--dbname='.$conexion['database'],
            '--quiet',
            '--file='.$ruta,
        ];

        $proceso = proc_open(
            $comando,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias,
            null,
            // El entorno se hereda entero y solo se le añade la contraseña: en
            // Windows, pasar un entorno recortado deja al binario sin PATH ni
            // SystemRoot y falla sin decir por qué.
            array_merge(getenv(), ['PGPASSWORD' => (string) $conexion['password']])
        );

        if (! is_resource($proceso)) {
            $this->error('No se pudo ejecutar psql. Configura PSQL_PATH si no está en el PATH.');

            return self::FAILURE;
        }

        stream_get_contents($tuberias[1]);
        $error = stream_get_contents($tuberias[2]);
        array_map('fclose', $tuberias);
        $codigo = proc_close($proceso);

        if ($codigo !== 0) {
            $this->error('psql falló: '.trim($error));

            return self::FAILURE;
        }

        $this->info('Restaurado. Filas en «leads»: '.DB::table('leads')->count());

        return self::SUCCESS;
    }

    /**
     * `localhost` se traduce aquí a `127.0.0.1`. No es capricho: en Windows el
     * resolvedor de pg_dump/psql falla con «no se pudo traducir el nombre
     * localhost» aunque PHP conecte sin problema por el mismo nombre. Cualquier
     * otro host se pasa tal cual.
     */
    private function host(string $host): string
    {
        return $host === 'localhost' ? '127.0.0.1' : $host;
    }
}
