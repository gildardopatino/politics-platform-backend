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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo', 10)->unique();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('codigo');
        });

        // Municipalities (Municipios/Ciudades)
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->string('nombre');
            $table->string('codigo', 10)->unique();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'codigo']);
        });

        // Communes (Comunas - solo en ciudades grandes como Ibagué, Medellín, etc)
        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained('municipalities')->onDelete('cascade');
            $table->string('nombre');
            $table->string('codigo', 10)->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('municipality_id');
        });

        // Barrios (zona urbana)
        // Si tiene commune_id: pertenece a una comuna (la comuna ya tiene municipality_id)
        // Si NO tiene commune_id: debe tener municipality_id (barrio directo del municipio)
        Schema::create('barrios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->nullable()->constrained('municipalities')->onDelete('cascade');
            $table->foreignId('commune_id')->nullable()->constrained('communes')->onDelete('cascade');
            $table->string('codigo', 10)->nullable();
            $table->string('nombre');
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'commune_id']);
        });

        // Corregimientos (zona rural - dependen del municipio)
        Schema::create('corregimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained('municipalities')->onDelete('cascade');
            $table->string('codigo', 10)->nullable();
            $table->string('nombre');
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('municipality_id');
        });

        // Veredas (zona rural - pueden pertenecer a corregimiento o directamente a municipio)
        Schema::create('veredas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained('municipalities')->onDelete('cascade');
            $table->foreignId('corregimiento_id')->nullable()->constrained('corregimientos')->onDelete('cascade');
            $table->string('codigo', 10)->nullable();
            $table->string('nombre');
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'corregimiento_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('veredas');
        Schema::dropIfExists('corregimientos');
        Schema::dropIfExists('barrios');
        Schema::dropIfExists('communes');
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('departments');
    }
};
