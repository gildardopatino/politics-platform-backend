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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Información personal
            $table->string('cedula')->index();
            $table->string('nombre1')->nullable();
            $table->string('nombre2')->nullable();
            $table->string('apellido1')->nullable();
            $table->string('apellido2')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            
            // Información de contacto y ubicación
            $table->string('barrio_otro')->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono')->nullable();
            
            // Información electoral/votación
            $table->string('puesto_votacion')->nullable();
            $table->string('departamento_votacion')->nullable();
            $table->string('municipio_votacion')->nullable();
            $table->string('zona_votacion')->nullable();
            $table->string('locality_name')->nullable();
            $table->text('direccion_votacion')->nullable();
            
            // Coordenadas geográficas
            $table->decimal('latitud', 10, 8)->nullable();
            $table->decimal('longitud', 11, 8)->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para búsqueda
            $table->index(['tenant_id', 'cedula']);
            $table->index(['tenant_id', 'nombre1', 'apellido1']);
            $table->index(['tenant_id', 'telefono']);
            $table->index(['municipio_votacion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
