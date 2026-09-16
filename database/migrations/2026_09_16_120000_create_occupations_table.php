<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de oficios (Spec 0094).
 *
 * Global y sin `tenant_id`, igual que `voting_places`: un oficio es el mismo en
 * todas las campañas, y compartirlo es lo que hace que la búsqueda case. Lo que
 * es por tenant son los votantes que apuntan aquí, no el renglón.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupations', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupations');
    }
};
