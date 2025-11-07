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
        // Agregar campo path a departments
        Schema::table('departments', function (Blueprint $table) {
            $table->text('path')->nullable()->after('longitud');
        });

        // Agregar campo path a municipalities
        Schema::table('municipalities', function (Blueprint $table) {
            $table->text('path')->nullable()->after('longitud');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('path');
        });

        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
