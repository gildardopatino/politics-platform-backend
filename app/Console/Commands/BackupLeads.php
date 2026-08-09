<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Respalda la tabla `leads` a un archivo local (Spec 0059).
 *
 * `leads` es lo contrario de la geografía: son personas —cédula, nombre,
 * contacto, mesa de votación— y por eso **no puede vivir en el repositorio**,
 * que tiene remoto en GitHub. El volcado va a `storage/backups/`, que está en
 * `.gitignore`, y se restaura a mano después de un `migrate:fresh`.
 *
 * Usa `pg_dump --data-only` porque son millones de filas: un export por Eloquent
 * tardaría una eternidad y ocuparía varias veces más. La ruta del binario se
 * configura porque en Windows rara vez está en el PATH.
 */
class BackupLeads extends Command
{
    protected $signature = 'leads:backup
                            {--table=leads : Tabla a respaldar}
                            {--path= : Carpeta destino (por defecto storage/backups)}';

    protected $description = 'Vuelca la tabla de leads a un respaldo local (fuera del repositorio)';

    public function handle(): int
    {
        $tabla = $this->option('table');

        if (DB::getDriverName() !== 'pgsql') {
            $this->error('El respaldo usa pg_dump: solo aplica sobre PostgreSQL.');

            return self::FAILURE;
        }

        $pgDump = config('database.pg_dump_path') ?: 'pg_dump';

        if (! $this->binarioDisponible($pgDump)) {
            $this->error("No se encontró pg_dump en «{$pgDump}».");
            $this->line('Configura PG_DUMP_PATH en el .env, por ejemplo:');
            $this->line('  PG_DUMP_PATH="C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe"');

            return self::FAILURE;
        }

        $carpeta = $this->option('path') ?: storage_path('backups');

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0755, true) && ! is_dir($carpeta)) {
            $this->error("No se pudo crear {$carpeta}.");

            return self::FAILURE;
        }

        $conexion = config('database.connections.'.config('database.default'));
        $destino = rtrim($carpeta, '/\\').DIRECTORY_SEPARATOR.$tabla.'_'.now()->format('Ymd_His').'.sql';

        $filas = DB::table($tabla)->count();
        $this->info("Respaldando {$filas} filas de «{$tabla}»…");

        $comando = [
            $pgDump,
            '--host='.$this->host($conexion['host']),
            '--port='.$conexion['port'],
            '--username='.$conexion['username'],
            '--dbname='.$conexion['database'],
            '--table='.$tabla,
            '--data-only',
            '--no-owner',
            '--no-privileges',
            '--file='.$destino,
        ];

        // La contraseña va por entorno y no por argumento: los argumentos se ven
        // en la lista de procesos del sistema.
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
            $this->error('No se pudo ejecutar pg_dump.');

            return self::FAILURE;
        }

        stream_get_contents($tuberias[1]);
        $error = stream_get_contents($tuberias[2]);
        array_map('fclose', $tuberias);
        $codigo = proc_close($proceso);

        if ($codigo !== 0) {
            $this->error('pg_dump falló: '.trim($error));

            return self::FAILURE;
        }

        $this->info('Respaldo escrito en '.$destino);
        $this->line('Tamaño: '.$this->tamano(filesize($destino)));
        $this->newLine();
        $this->warn('Contiene datos personales. No lo subas al repositorio ni lo compartas.');
        $this->line('Para restaurarlo tras un migrate:fresh --seed:');
        $this->line('  php artisan leads:restore '.basename($destino));

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

    private function binarioDisponible(string $binario): bool
    {
        if (is_file($binario)) {
            return true;
        }

        $comprobar = stripos(PHP_OS_FAMILY, 'win') === 0 ? 'where' : 'which';
        exec(escapeshellcmd($comprobar).' '.escapeshellarg($binario).' 2>&1', $salida, $codigo);

        return $codigo === 0;
    }

    private function tamano(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unidad) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unidad;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
