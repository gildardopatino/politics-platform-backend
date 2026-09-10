<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Da de baja el secreto de los webhooks de Registraduría (Spec 0091).
 *
 * La columna la creó la Spec 0030 para autenticar a n8n, que preguntaba por los
 * votantes pendientes y escribía el puesto de vuelta. En la 0091 el flujo se
 * invierte —Laravel llama al servicio de scraping en cola— y no queda ninguna
 * ruta pública que autenticar: sin webhook, el secreto es una credencial
 * huérfana, y una credencial que nadie usa es solo superficie de ataque.
 *
 * El `down()` la recrea igual que la 0030 para que la migración sea reversible,
 * pero los secretos en sí no vuelven: eran hashes de valores que solo existieron
 * una vez en la pantalla de quien los generó.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'registraduria_secret_hash')) {
            return;
        }

        // El índice único va en su propia operación y en TODOS los motores.
        // SQLite hoy hace `ALTER TABLE ... DROP COLUMN` de verdad en vez de
        // reconstruir la tabla, así que un índice que siga apuntando a la
        // columna revienta el `DROP` («error in index ... after drop column»).
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['registraduria_secret_hash']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('registraduria_secret_hash');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'registraduria_secret_hash')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('registraduria_secret_hash', 64)
                ->nullable()
                ->after('expiration_date');

            $table->unique('registraduria_secret_hash');
        });
    }
};
