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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('reports_to')->constrained('departments')->onDelete('set null');
            $table->foreignId('municipality_id')->nullable()->after('department_id')->constrained('municipalities')->onDelete('set null');
            $table->foreignId('commune_id')->nullable()->after('municipality_id')->constrained('communes')->onDelete('set null');
            $table->foreignId('barrio_id')->nullable()->after('commune_id')->constrained('barrios')->onDelete('set null');
            $table->foreignId('corregimiento_id')->nullable()->after('barrio_id')->constrained('corregimientos')->onDelete('set null');
            $table->foreignId('vereda_id')->nullable()->after('corregimiento_id')->constrained('veredas')->onDelete('set null');
            
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'municipality_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['municipality_id']);
            $table->dropForeign(['commune_id']);
            $table->dropForeign(['barrio_id']);
            $table->dropForeign(['corregimiento_id']);
            $table->dropForeign(['vereda_id']);
            
            $table->dropColumn([
                'department_id',
                'municipality_id',
                'commune_id',
                'barrio_id',
                'corregimiento_id',
                'vereda_id'
            ]);
        });
    }
};
