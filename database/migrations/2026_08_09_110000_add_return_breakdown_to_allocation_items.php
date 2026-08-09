<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desglose de la devolución por ítem (Spec 0057).
 *
 * Hasta ahora un ítem solo tenía un estado, y la devolución era todo o nada: al
 * cerrar la asignación se reintegraba el 100 % aunque de la vereda hubieran
 * vuelto ocho sillas de diez. Lo que faltaba no era un estado más, sino las
 * cantidades: cuánto volvió, cuánto se perdió y cuánto volvió roto.
 *
 * El estado del ítem se deriva de estas tres (`devuelto`, `parcial`, `perdido`,
 * `dañado`), así que no hay que mantenerlo a mano ni puede contradecirlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_allocation_items', function (Blueprint $table) {
            $table->decimal('quantity_returned', 10, 2)->default(0)->after('quantity');
            $table->decimal('quantity_lost', 10, 2)->default(0)->after('quantity_returned');
            $table->decimal('quantity_damaged', 10, 2)->default(0)->after('quantity_lost');
            $table->timestamp('closed_at')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('resource_allocation_items', function (Blueprint $table) {
            $table->dropColumn(['quantity_returned', 'quantity_lost', 'quantity_damaged', 'closed_at']);
        });
    }
};
