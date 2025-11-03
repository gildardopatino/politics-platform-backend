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
        Schema::create('attendee_hierarchies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Asistente (subordinado)
            $table->string('attendee_cedula');
            $table->string('attendee_name')->nullable();
            $table->string('attendee_email')->nullable();
            $table->string('attendee_phone')->nullable();
            
            // Supervisor
            $table->string('supervisor_cedula');
            $table->string('supervisor_name')->nullable();
            
            // Metadatos de la relación
            $table->integer('relationship_strength')->default(1)->comment('Fortaleza de la relación basada en cantidad de reuniones');
            $table->date('last_interaction')->nullable()->comment('Fecha de la última reunión juntos');
            $table->string('context')->nullable()->comment('Contexto o región donde aplica esta relación');
            $table->boolean('is_primary')->default(false)->comment('Si es la relación supervisora principal');
            $table->boolean('is_active')->default(true);
            
            // Auditoría
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            
            // Índices
            $table->index(['tenant_id', 'attendee_cedula']);
            $table->index(['tenant_id', 'supervisor_cedula']);
            $table->index(['tenant_id', 'attendee_cedula', 'is_primary']);
            $table->unique(['tenant_id', 'attendee_cedula', 'supervisor_cedula', 'context'], 'unique_hierarchy_relation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendee_hierarchies');
    }
};
