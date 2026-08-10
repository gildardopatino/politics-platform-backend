<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El candidato propio de la campaña, por elección (Spec 0062 · Parte 0).
 *
 * En clave SaaS cada tenant es la campaña de **un** candidato a **un** cargo, y
 * ese candidato es **una fila del E-14**. Sin decir cuál, el escrutinio se puede
 * consolidar pero no se puede cruzar: «registrados vs votos» necesita saber de
 * quién son los votos.
 *
 * Va en `electoral_events` y no en `tenants` porque una campaña puede tener más
 * de una elección cargada (la de alcaldía y la de concejo del mismo día, o la de
 * 2027 y la de 2031) y el número del tarjetón es distinto en cada una.
 *
 * Se guardan también el nombre y la agrupación —denormalizados, como los nombres
 * de la ubicación en la 0074— para poder mostrar «4 · JORGE BOLÍVAR (Partido X)»
 * antes de que exista la primera acta, que es cuando todavía no hay catálogo
 * `e14_candidates` de dónde sacarlos. El número es la llave; los otros dos son
 * para que alguien entienda qué está mirando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electoral_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('candidato_propio_numero')->nullable()->after('tipo');
            $table->string('candidato_propio_nombre')->nullable()->after('candidato_propio_numero');
            $table->string('candidato_propio_agrupacion')->nullable()->after('candidato_propio_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('electoral_events', function (Blueprint $table) {
            $table->dropColumn([
                'candidato_propio_numero',
                'candidato_propio_nombre',
                'candidato_propio_agrupacion',
            ]);
        });
    }
};
