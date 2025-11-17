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
        Schema::create('tenant_whatsapp_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('phone_number', 20); // Número con código de país
            $table->string('instance_name'); // Nombre de la instancia en Evolution API
            $table->text('evolution_api_key'); // API Key de Evolution API
            $table->string('evolution_api_url')->nullable(); // URL base de Evolution API (opcional, usar default)
            $table->integer('daily_message_limit')->default(1000); // Límite de mensajes por día
            $table->integer('messages_sent_today')->default(0); // Contador de mensajes enviados hoy
            $table->date('last_reset_date')->nullable(); // Última fecha de reseteo del contador
            $table->boolean('is_active')->default(true); // Si la instancia está activa
            $table->text('notes')->nullable(); // Notas opcionales
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'phone_number']); // Un número por tenant
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_whatsapp_instances');
    }
};
