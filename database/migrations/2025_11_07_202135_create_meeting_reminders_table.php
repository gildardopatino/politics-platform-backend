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
        Schema::create('meeting_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('meeting_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            
            // Fecha y hora del recordatorio (debe ser al menos 5 horas antes de la reunión)
            $table->timestamp('reminder_datetime');
            
            // Destinatarios del recordatorio (array de user_ids)
            $table->json('recipients'); // [{user_id: 1, phone: '3001234567', name: 'Juan'}, ...]
            
            // Estado del recordatorio
            $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'cancelled'])->default('pending');
            
            // ID del job programado (para poder cancelarlo si es necesario)
            $table->string('job_id')->nullable();
            
            // Metadata adicional
            $table->text('message')->nullable(); // Mensaje personalizado opcional
            $table->json('metadata')->nullable(); // Datos adicionales
            
            // Estadísticas de envío
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['tenant_id', 'meeting_id']);
            $table->index(['status', 'reminder_datetime']);
            $table->index('job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_reminders');
    }
};
