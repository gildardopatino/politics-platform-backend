<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil laboral del votante (Spec 0094).
 *
 * Uno por votante: lo que se captura en la reunión es el estado actual de esa
 * persona, no un histórico. Por eso `voter_id` es único y el endpoint hace
 * upsert en vez de crear filas.
 *
 * `autoriza_tratamiento_datos` + `autorizado_at` existen por la Ley 1581: la
 * situación laboral es dato personal y guardar **cuándo** se autorizó es parte
 * de poder demostrar que se autorizó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voter_id')->unique()->constrained('voters')->cascadeOnDelete();

            $table->boolean('busca_empleo')->default(false);
            $table->string('disponibilidad')->nullable();
            $table->string('nivel_educativo')->nullable();
            $table->unsignedSmallInteger('anios_experiencia')->nullable();
            $table->text('notas')->nullable();

            $table->boolean('autoriza_tratamiento_datos')->default(false);
            $table->timestamp('autorizado_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El filtro más usado de la bolsa: «quién busca empleo en esta campaña».
            $table->index(['tenant_id', 'busca_empleo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_profiles');
    }
};
