<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voting_places', function (Blueprint $table) {
            $table->id();
            $table->string('departamento_votacion');
            $table->string('municipio_votacion');
            $table->string('puesto_votacion');
            $table->text('direccion_votacion')->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Índice único por departamento + municipio + puesto
            $table->unique(['departamento_votacion', 'municipio_votacion', 'puesto_votacion'], 'voting_place_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voting_places');
    }
};
