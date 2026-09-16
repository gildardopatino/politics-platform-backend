<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué oficio y en qué calidad (Spec 0094).
 *
 * `relacion` distingue «lo busca» de «tiene experiencia en él», que es lo que
 * hace útil la bolsa: quien pregunta por una vacante quiere a los primeros, y
 * quien arma un equipo quiere a los segundos. Sin la distinción, las dos
 * consultas devuelven la misma lista y ninguna sirve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_occupations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained('voters')->cascadeOnDelete();
            $table->foreignId('occupation_id')->constrained('occupations')->cascadeOnDelete();
            $table->string('relacion', 20);
            $table->timestamps();

            $table->unique(['voter_id', 'occupation_id', 'relacion'], 'voter_occupations_unique');
            $table->index(['tenant_id', 'occupation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_occupations');
    }
};
