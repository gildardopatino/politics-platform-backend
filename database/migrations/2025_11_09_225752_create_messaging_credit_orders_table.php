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
        Schema::create('messaging_credit_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Usuario que hizo la compra
            
            // Detalles de la orden
            $table->enum('type', ['email', 'whatsapp']);
            $table->integer('quantity'); // Cantidad de créditos
            $table->decimal('unit_price', 10, 2); // Precio unitario al momento de compra
            $table->decimal('total_amount', 12, 2); // Total a pagar
            $table->string('currency', 3)->default('COP'); // Moneda
            
            // MercadoPago
            $table->string('payment_method')->nullable(); // Método de pago usado
            $table->string('payment_provider')->default('mercadopago'); // Proveedor de pago
            $table->string('payment_id')->nullable(); // ID de pago de MercadoPago
            $table->string('preference_id')->nullable(); // ID de preferencia de MercadoPago
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->string('payment_status')->nullable(); // Estado del pago en MercadoPago
            $table->json('payment_details')->nullable(); // Detalles del pago
            
            // Timestamps y cumplimiento
            $table->timestamp('processed_at')->nullable(); // Cuando se procesó el pago
            $table->timestamp('expires_at')->nullable(); // Cuando expira la orden
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Índices
            $table->index('tenant_id');
            $table->index('status');
            $table->index('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messaging_credit_orders');
    }
};
