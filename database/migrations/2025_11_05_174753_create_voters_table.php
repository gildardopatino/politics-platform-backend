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
        Schema::create('voters', function (Blueprint $table) {
            $table->id();
            
            // Tenant
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Información Personal
            $table->string('cedula', 20)->index();
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('email')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion')->nullable(); // Domicilio del votante
            
            // Ubicación del votante (donde vive)
            $table->foreignId('barrio_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('corregimiento_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('vereda_id')->nullable()->constrained()->onDelete('set null');
            
            // Primera reunión donde se registró
            $table->foreignId('meeting_id')->nullable()->constrained()->onDelete('set null');
            
            // Información del Puesto de Votación (texto libre de registraduría)
            $table->string('departamento_votacion')->nullable();
            $table->string('municipio_votacion')->nullable();
            $table->string('puesto_votacion')->nullable();
            $table->string('direccion_puesto')->nullable();
            $table->string('mesa_votacion', 20)->nullable();
            
            // Flag para indicar que este votante tiene múltiples registros con datos diferentes
            $table->boolean('has_multiple_records')->default(false);
            
            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->unique(['tenant_id', 'cedula']);
            $table->index(['nombres', 'apellidos']);
            $table->index('has_multiple_records');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voters');
    }
};
