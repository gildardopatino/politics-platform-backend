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
        // Agregar campo path a barrios
        Schema::table('barrios', function (Blueprint $table) {
            $table->text('path')->nullable()->after('longitude');
        });

        // Agregar campo path a corregimientos
        Schema::table('corregimientos', function (Blueprint $table) {
            $table->text('path')->nullable()->after('longitude');
        });

        // Agregar campo path a veredas
        Schema::table('veredas', function (Blueprint $table) {
            $table->text('path')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('barrios', function (Blueprint $table) {
            $table->dropColumn('path');
        });

        Schema::table('corregimientos', function (Blueprint $table) {
            $table->dropColumn('path');
        });

        Schema::table('veredas', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
