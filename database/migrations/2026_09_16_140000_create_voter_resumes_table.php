<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hojas de vida del elector (Spec 0096).
 *
 * Varias por persona a propósito: la gente manda versiones nuevas —«ahora con el
 * curso de alturas»— y la anterior sigue siendo la que ya se envió a alguien.
 * Pisarlas convertiría una corrección en una pérdida.
 *
 * `archivo_key` es la ruta dentro del disco privado y **no sale en ninguna
 * respuesta**: la descarga va por ruta firmada (Art. VII).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained('voters')->cascadeOnDelete();

            $table->string('archivo_key');
            $table->string('nombre_original');
            $table->string('mime', 100);
            $table->unsignedBigInteger('tamano_bytes');

            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'voter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_resumes');
    }
};
