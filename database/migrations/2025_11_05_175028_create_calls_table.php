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
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            
            // Tenant
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Relaciones
            $table->foreignId('voter_id')->constrained()->onDelete('cascade');
            $table->foreignId('survey_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Quien hizo la llamada
            
            // Información de la Llamada
            $table->timestamp('call_date');
            $table->integer('duration_seconds')->nullable();
            $table->enum('status', [
                'completed',      // Completada con éxito
                'no_answer',      // No contestó
                'busy',           // Ocupado
                'rejected',       // Rechazó la llamada
                'wrong_number',   // Número equivocado
                'voicemail'       // Buzón de voz
            ])->default('completed');
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Índices
            $table->index(['voter_id', 'call_date']);
            $table->index(['user_id', 'call_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
