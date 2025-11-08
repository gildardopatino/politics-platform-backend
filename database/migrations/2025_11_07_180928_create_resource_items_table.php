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
        Schema::create('resource_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Información básica del item
            $table->string('name'); // Ej: "Silla plástica", "Transporte vehicular", "Personal de apoyo"
            $table->text('description')->nullable();
            $table->enum('category', ['cash', 'furniture', 'vehicle', 'equipment', 'personnel', 'material', 'service', 'other']);
            
            // Unidad de medida y costos
            $table->string('unit')->default('unidad'); // unidad, hora, día, persona, kilómetro, etc.
            $table->decimal('unit_cost', 15, 2)->nullable(); // Costo por unidad
            $table->string('currency', 3)->default('COP'); // COP, USD, EUR
            
            // Control de inventario (opcional)
            $table->integer('stock_quantity')->nullable(); // Cantidad disponible en inventario
            $table->integer('min_stock')->nullable(); // Stock mínimo de alerta
            
            // Información adicional
            $table->string('supplier')->nullable(); // Proveedor
            $table->string('supplier_contact')->nullable(); // Contacto del proveedor
            $table->json('metadata')->nullable(); // Datos adicionales flexibles
            
            // Estado
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['tenant_id', 'category', 'is_active']);
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_items');
    }
};
