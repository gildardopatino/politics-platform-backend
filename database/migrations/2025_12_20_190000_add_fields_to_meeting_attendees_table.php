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
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->string('genero')->nullable()->after('email');
            $table->date('fecha_nacimiento')->nullable()->after('genero');
            $table->string('referido_por')->nullable()->after('fecha_nacimiento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->dropColumn(['genero', 'fecha_nacimiento', 'referido_por']);
        });
    }
};
