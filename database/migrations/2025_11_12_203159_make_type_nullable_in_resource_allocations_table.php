<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Para PostgreSQL, necesitamos un enfoque diferente
        DB::statement("ALTER TABLE resource_allocations ALTER COLUMN type DROP NOT NULL");
        DB::statement("ALTER TABLE resource_allocations ALTER COLUMN allocation_date DROP NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE resource_allocations ALTER COLUMN type SET NOT NULL");
        DB::statement("ALTER TABLE resource_allocations ALTER COLUMN allocation_date SET NOT NULL");
    }
};
