<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La meta por puesto de votación (Spec 0064 · RF-1).
 *
 * Un **override opcional** sobre la meta global: la campaña que sabe que el
 * colegio grande tiene que darle 500 votos lo escribe aquí. No hay fila para el
 * puesto sin meta —ausencia significa «no la fijaron», y el panel lo dice— en vez
 * de sembrar un cero que se leería como objetivo.
 *
 * **No hay tabla de municipio ni de zona**: esas metas se **agregan** de sus
 * puestos. Una meta directa de municipio conviviendo con la de sus puestos
 * obligaría a decidir cuál gana cuando no cuadran, y esa pregunta no tiene
 * respuesta buena. Si algún día hace falta, es una tabla análoga a esta.
 *
 * `tenant_id` va denormalizado —se deriva de la elección— para que `TenantScope`
 * filtre directo, como en el resto de las tablas del E-14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e14_meta_puesto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('electoral_event_id')->constrained('electoral_events')->onDelete('cascade');
            // Si el puesto sale del catálogo, su meta se va con él: una meta
            // colgando de un puesto que ya no existe no la puede leer nadie.
            $table->foreignId('voting_place_id')->constrained('voting_places')->onDelete('cascade');

            $table->unsignedInteger('meta_votos');

            $table->timestamps();

            // La meta de un puesto es **una**. Sin esto, dos escrituras seguidas
            // dejarían dos renglones y la agregación de municipio contaría doble.
            $table->unique(['electoral_event_id', 'voting_place_id'], 'e14_meta_puesto_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e14_meta_puesto');
    }
};
