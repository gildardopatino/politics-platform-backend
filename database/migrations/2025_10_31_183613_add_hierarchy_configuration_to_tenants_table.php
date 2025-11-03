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
        Schema::table('tenants', function (Blueprint $table) {
            // Configuración de jerarquía de asistentes
            $table->enum('hierarchy_mode', ['disabled', 'simple_tree', 'multiple_supervisors', 'context_based'])
                  ->default('disabled')
                  ->after('content_text_color')
                  ->comment('Modo de jerarquía: disabled=sin jerarquía, simple_tree=árbol simple, multiple_supervisors=múltiples supervisores, context_based=por contexto');
            
            $table->boolean('auto_assign_hierarchy')
                  ->default(false)
                  ->after('hierarchy_mode')
                  ->comment('Si debe crear automáticamente jerarquías al asignar reuniones por cédula');
            
            $table->enum('hierarchy_conflict_resolution', ['last_assignment', 'most_active', 'manual_review'])
                  ->default('last_assignment')
                  ->after('auto_assign_hierarchy')
                  ->comment('Cómo resolver conflictos: last_assignment=última asignación prevalece, most_active=supervisor más activo, manual_review=requiere revisión manual');
            
            $table->boolean('require_hierarchy_config')
                  ->default(true)
                  ->after('hierarchy_conflict_resolution')
                  ->comment('Si requiere configurar jerarquías antes de crear reuniones');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'hierarchy_mode',
                'auto_assign_hierarchy', 
                'hierarchy_conflict_resolution',
                'require_hierarchy_config'
            ]);
        });
    }
};
