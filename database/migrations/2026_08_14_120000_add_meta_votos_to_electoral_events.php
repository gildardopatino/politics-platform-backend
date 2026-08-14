<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La meta de votos de la campaña, global por elección (Spec 0064 · RF-1).
 *
 * Es un número **puesto a mano** por el jefe de campaña, no derivado: ni del
 * histórico de la Registraduría ni del censo del puesto (los dos quedaron como
 * gancho para más adelante). Por eso vive en la elección y no se calcula en
 * ningún sitio.
 *
 * **Nullable y sin defecto**: `null` significa «todavía no la fijaron», que no es
 * lo mismo que una meta de cero. La proyección lo distingue —sin meta no hay
 * avance que calcular ni semáforo que encender— y un `0` por defecto habría
 * pintado de rojo a toda campaña recién creada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electoral_events', function (Blueprint $table) {
            // Votos, no personas: una meta de senado se cuenta en cientos de
            // miles y un smallint se quedaría corto.
            $table->unsignedInteger('meta_votos')
                ->nullable()
                ->after('candidato_propio_agrupacion');
        });
    }

    public function down(): void
    {
        Schema::table('electoral_events', function (Blueprint $table) {
            $table->dropColumn('meta_votos');
        });
    }
};
