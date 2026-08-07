<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Secreto por tenant para los webhooks de Registraduría (Spec 0030).
 *
 * Se guarda el **SHA-256** del secreto, no el secreto: si alguien lee la tabla
 * no puede firmar peticiones. Es indexable, así que la búsqueda por cabecera es
 * una consulta directa y no un recorrido de todos los tenants — el mismo
 * planteamiento que usan los tokens de API de Laravel.
 *
 * Nullable: quien no lo haya generado no puede sincronizar, que es el estado
 * seguro por defecto. Se genera con `php artisan registraduria:secret {tenant}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('registraduria_secret_hash', 64)
                ->nullable()
                ->after('expiration_date');

            // Único: un hash identifica a un solo tenant. Índice, además, porque
            // es por donde entra cada petición del webhook.
            $table->unique('registraduria_secret_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // SQLite no soporta DROP INDEX sobre una columna que se va a
            // eliminar en la misma operación; allí el índice se va con la
            // reconstrucción de la tabla.
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropUnique(['registraduria_secret_hash']);
            }

            $table->dropColumn('registraduria_secret_hash');
        });
    }
};
