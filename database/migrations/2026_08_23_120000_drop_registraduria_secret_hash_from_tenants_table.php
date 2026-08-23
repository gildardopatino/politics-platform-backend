<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retira el secreto del webhook de Registraduría (Spec 0091).
 *
 * La columna la creó la Spec 0030 para autenticar a n8n, que preguntaba por los
 * votantes sin puesto y escribía el resultado. Con la 0091 el flujo se invirtió:
 * es Laravel quien llama de salida al servicio propio, con un token compartido
 * en `.env`, así que no queda nadie que firme peticiones con ese secreto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'registraduria_secret_hash')) {
            return;
        }

        // El índice se suelta en su propia operación y ANTES que la columna: en
        // SQLite el `drop column` reconstruye la tabla y el índice huérfano hace
        // fallar la migración («1 error in index ... after drop column»).
        if (Schema::hasIndex('tenants', 'tenants_registraduria_secret_hash_unique')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropUnique('tenants_registraduria_secret_hash_unique');
            });
        }

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
