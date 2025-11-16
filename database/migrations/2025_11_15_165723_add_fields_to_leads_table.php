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
        Schema::table('leads', function (Blueprint $table) {
             $table->string('genero')->nullable();
             $table->string('tipo_documento')->nullable();
             $table->string('ips_primaria')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
             $table->dropColumn('genero');
             $table->dropColumn('tipo_documento');
             $table->dropColumn('ips_primaria');
        });
    }
};
