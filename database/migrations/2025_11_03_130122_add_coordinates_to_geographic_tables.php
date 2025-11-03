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
        Schema::table('municipalities', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('codigo');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('communes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('codigo');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('barrios', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('codigo');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('corregimientos', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('codigo');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('veredas', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('codigo');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('communes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('barrios', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('corregimientos', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('veredas', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
