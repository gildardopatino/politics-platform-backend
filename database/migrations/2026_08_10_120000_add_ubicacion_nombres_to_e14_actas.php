<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los nombres de la ubicación del acta (Spec 0074).
 *
 * El encabezado del E-14 trae departamento y municipio **con código y nombre**
 * («29 - TOLIMA», «001 - IBAGUE»), y el lector ya transcribía las dos cosas. El
 * backend, en cambio, solo guardaba los códigos: quien abría el panel veía «29 /
 * 001» y tenía que saberse de memoria el DIVIPOLA para entenderlo.
 *
 * Se guardan como texto y no como FK a `departments`/`municipalities` por la
 * misma razón que los códigos (ver la cabecera de la migración 0061): es lo que
 * dice **el papel**, y el papel manda aunque venga con una tilde de más o un
 * nombre viejo. Enlazar al catálogo es aditivo cuando exista uno completo.
 *
 * `lugar` —el nombre impreso del puesto de votación— ya existía desde la 0061;
 * lo que faltaba era que alguien lo persistiera y lo mostrara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->string('departamento')->nullable()->after('departamento_code');
            $table->string('municipio')->nullable()->after('municipio_code');
        });
    }

    public function down(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->dropColumn(['departamento', 'municipio']);
        });
    }
};
