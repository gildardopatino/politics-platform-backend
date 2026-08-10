<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El puesto canónico del acta (Spec 0062 · Parte A).
 *
 * Es la pieza que permite cruzar el escrutinio con los registrados. La cabecera
 * de la migración de la 0061 explicaba por qué el acta se enlazaba por códigos y
 * no por FK a `voting_places`: era un catálogo global de texto libre que nadie
 * garantizaba. Sigue siendo eso, pero ahora es **lo único común entre los dos
 * lados del cruce**, y por eso pasa a ser la llave:
 *
 * - el votante se registra con municipio (nombre) + puesto (nombre) + mesa sin
 *   ceros, y ya tiene `voting_place_id`;
 * - el acta trae puesto por código + lugar (nombre) + mesa con ceros.
 *
 * Cruzar por nombres sueltos daría falsos negativos con cada abreviatura. Cruzar
 * por códigos es imposible: el votante no trae el del puesto. Así que ambos lados
 * resuelven al mismo renglón del catálogo y el cruce se hace por
 * `voting_place_id` + mesa normalizada, exacto.
 *
 * Nullable a propósito: un acta recién subida no sabe de qué puesto es, y una que
 * se leyó sin `lugar` legible no tiene con qué resolverlo. Esas quedan en el
 * recuento de **cobertura** del cruce; jamás se les inventa un puesto.
 *
 * `nullOnDelete` y no `cascade`: borrar un renglón del catálogo global —que es
 * compartido entre campañas— no puede arrastrarse un acta con votos dentro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->foreignId('voting_place_id')->nullable()->after('lugar')
                ->constrained('voting_places')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->dropForeign(['voting_place_id']);
            $table->dropColumn('voting_place_id');
        });
    }
};
