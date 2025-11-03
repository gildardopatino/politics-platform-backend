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
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('assigned_to_cedula')
                  ->nullable()
                  ->after('planner_id')
                  ->comment('Cédula del asistente al que se asigna esta reunión para jerarquía');
            
            $table->index(['tenant_id', 'assigned_to_cedula']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'assigned_to_cedula']);
            $table->dropColumn('assigned_to_cedula');
        });
    }
};
