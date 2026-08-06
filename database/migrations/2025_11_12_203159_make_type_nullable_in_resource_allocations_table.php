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
        // Para PostgreSQL, necesitamos un enfoque diferente
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resource_allocations ALTER COLUMN type DROP NOT NULL');
            DB::statement('ALTER TABLE resource_allocations ALTER COLUMN allocation_date DROP NOT NULL');

            return;
        }

        // SQLite (pruebas) no soporta ALTER COLUMN: se reconstruye vía Blueprint.
        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->string('type')->nullable()->change();
            $table->date('allocation_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resource_allocations ALTER COLUMN type SET NOT NULL');
            DB::statement('ALTER TABLE resource_allocations ALTER COLUMN allocation_date SET NOT NULL');

            return;
        }

        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->string('type')->nullable(false)->change();
            $table->date('allocation_date')->nullable(false)->change();
        });
    }
};
