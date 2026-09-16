<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinónimos de oficio (Spec 0094).
 *
 * «Celador» y «vigilante» son el mismo trabajo, y quien atiende la reunión
 * escribe el que oyó. Sin esta tabla la bolsa de empleo se rompe en la primera
 * búsqueda: el dato está guardado y aun así no aparece.
 *
 * `alias` es único en toda la tabla —no por oficio— a propósito: un mismo
 * sinónimo apuntando a dos oficios volvería ambigua la resolución, que es justo
 * lo que la spec no permite (Art. VI, la respuesta tiene que ser una).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupation_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occupation_id')->constrained('occupations')->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupation_aliases');
    }
};
