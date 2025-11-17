<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resource_items', function (Blueprint $table) {
            $table->boolean('is_inventory_tracked')->default(true)->after('is_active');
        });
        
        // Actualizar recursos de efectivo (cash) para que NO controlen inventario
        DB::statement("UPDATE resource_items SET is_inventory_tracked = false WHERE category = 'cash'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_items', function (Blueprint $table) {
            $table->dropColumn('is_inventory_tracked');
        });
    }
};
