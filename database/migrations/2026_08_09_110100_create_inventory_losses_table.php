<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mermas de inventario (Spec 0057).
 *
 * Lo que no vuelve deja de ser un ajuste silencioso de stock y pasa a ser un
 * hecho con nombre: qué se perdió, cuánto, cuánto valía, quién lo tenía y en qué
 * reunión. El `unit_cost` se guarda como **fotografía** del momento del cierre:
 * si mañana sube el precio de la silla, la pérdida de ayer sigue valiendo lo que
 * valía. Por eso `value` también se guarda calculado y no se recalcula al leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_losses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('resource_item_id')->constrained('resource_items')->onDelete('cascade');
            $table->foreignId('resource_allocation_item_id')->nullable()
                ->constrained('resource_allocation_items')->nullOnDelete();
            $table->foreignId('resource_allocation_id')->nullable()
                ->constrained('resource_allocations')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('value', 15, 2)->default(0);
            $table->string('reason', 20); // perdido | dañado
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'reason']);
            $table->index('responsible_user_id');
            $table->index('meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_losses');
    }
};
