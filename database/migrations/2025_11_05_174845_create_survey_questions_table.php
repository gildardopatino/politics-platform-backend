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
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            
            // Relación con Encuesta
            $table->foreignId('survey_id')->constrained()->onDelete('cascade');
            
            // Contenido de la Pregunta
            $table->text('question_text');
            $table->enum('question_type', ['multiple_choice', 'yes_no', 'text', 'scale'])->default('text');
            
            // Opciones (JSON) - Para multiple_choice: ["Opción A", "Opción B", "Opción C"]
            // Para scale: {"min": 1, "max": 5, "labels": {"1": "Muy malo", "5": "Excelente"}}
            $table->json('options')->nullable();
            
            // Orden y Configuración
            $table->integer('order')->default(0);
            $table->boolean('is_required')->default(false);
            
            $table->timestamps();
            
            // Índices
            $table->index(['survey_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_questions');
    }
};
