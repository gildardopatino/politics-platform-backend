<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «TOTAL VOTOS INCINERADOS» de la nivelación de la mesa (Spec 0088).
 *
 * Es la tercera casilla del bloque «NIVELACIÓN DE LA MESA», junto a los
 * sufragantes del E-11 y los votos en la urna. Cuando en la urna hay **más**
 * votos que sufragantes, los jurados extraen al azar el excedente y lo incineran
 * sin abrirlo; la regla de la Registraduría es exacta: `urna − incinerados =
 * sufragantes`.
 *
 * Sin esta columna el servidor revalidaba el cuadre contra la urna **cruda** y
 * descuadraba en falso toda acta con incineración. El dato ya lo leía la visión,
 * así que lo único que faltaba era dónde guardarlo.
 *
 * `default(0)` y no nullable: cero es el caso normal —la inmensa mayoría de las
 * mesas no incineran nada— y un nulo obligaría a distinguir «no se incineró» de
 * «no se leyó» en cada comparación del cuadre, para la misma aritmética.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->unsignedInteger('votos_incinerados')->default(0)->after('votos_urna');
        });
    }

    public function down(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->dropColumn('votos_incinerados');
        });
    }
};
