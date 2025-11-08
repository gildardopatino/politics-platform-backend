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
        Schema::create('resource_allocation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_allocation_id')->constrained('resource_allocations')->onDelete('cascade');
            $table->foreignId('resource_item_id')->constrained('resource_items')->onDelete('cascade');
            
            // Detalles de la asignación
            $table->decimal('quantity', 10, 2); // Cantidad asignada (puede ser decimal para cosas como km, horas, etc.)
            $table->decimal('unit_cost', 15, 2); // Costo unitario al momento de la asignación
            $table->decimal('subtotal', 15, 2); // quantity * unit_cost
            
            // Información adicional específica del item
            $table->text('notes')->nullable(); // Notas específicas de este item
            $table->json('metadata')->nullable(); // Datos adicionales (ej: placa del vehículo, nombre de la persona, etc.)
            
            // Control de entrega
            $table->enum('status', ['pending', 'delivered', 'returned', 'damaged', 'lost'])->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('delivered_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('returned_to_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            
            $table->index(['resource_allocation_id', 'status']);
            $table->index('resource_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_allocation_items');
    }
};
