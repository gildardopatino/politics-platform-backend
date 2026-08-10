<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las constancias de los jurados (Spec 0073).
 *
 * La segunda página del E-14 trae un bloque manuscrito donde los jurados anotan
 * lo que pasó en la mesa, y muy a menudo **ahí está la explicación del acta que
 * no cuadra**: que se recontaron los votos, que una casilla se corrigió, que
 * apareció un tarjetón de más. Hasta ahora el lector solo miraba la primera
 * página, así que quien revisaba una mesa inconsistente veía la discrepancia
 * pero no el motivo que los jurados habían escrito a mano al lado.
 *
 * Se guardan **tal cual**, sin interpretar: es texto libre escrito a la una de
 * la mañana. Y son informativos: no entran en el cuadre. Un acta no cuadra
 * menos porque alguien explique por qué no cuadra.
 *
 * `hubo_recuento` es nullable a propósito, y son tres estados y no dos: sí, no,
 * y «no se pudo leer». Tratar lo ilegible como un «no» sería inventarse el dato
 * más interesante del bloque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->boolean('hubo_recuento')->nullable()->after('observacion');
            $table->text('constancias')->nullable()->after('hubo_recuento');
            $table->string('recuento_solicitado_por')->nullable()->after('constancias');
            $table->string('recuento_representacion')->nullable()->after('recuento_solicitado_por');
        });
    }

    public function down(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            $table->dropColumn([
                'hubo_recuento',
                'constancias',
                'recuento_solicitado_por',
                'recuento_representacion',
            ]);
        });
    }
};
