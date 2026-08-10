<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Este nombre de puesto es en realidad aquel» (Spec 0062 · Parte A).
 *
 * La normalización une lo que se puede unir sin adivinar —mayúsculas, acentos,
 * espacios—, pero «COL. SAN SIMON» y «COLEGIO SAN SIMON» siguen siendo dos cosas
 * hasta que una persona diga que son la misma. Cuando lo dice, la decisión se
 * guarda aquí.
 *
 * ### Por qué una tabla y no repuntar las filas y ya
 *
 * Repuntar los votantes y las actas que ya existen es la mitad del trabajo: la
 * siguiente acta que llegue con la grafía absorbida volvería a resolver al
 * renglón viejo y desharía la fusión en silencio. El alias hace que la decisión
 * valga también para lo que llegue después.
 *
 * ### Por qué es por tenant
 *
 * `voting_places` es un catálogo **global** compartido entre campañas: fusionar
 * dos de sus renglones de verdad —borrando uno— cambiaría los datos de otros
 * tenants sin que nadie se lo haya pedido, y eso es exactamente lo que el
 * Artículo III prohíbe. Así que el catálogo global no se toca: lo que se guarda es
 * «en esta campaña, este nombre va a este puesto», y lo que se reapunta son las
 * filas del tenant (sus votantes y sus actas).
 *
 * La llave es el **nombre normalizado** (`clave` = `norm(municipio)|norm(puesto)`)
 * y no un id, porque el problema es de nombres: hay grafías que no llegaron a
 * tener renglón en el catálogo, y tienen que poder fusionarse igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e14_puesto_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');

            // `norm(municipio)|norm(puesto)` — lo que el resolver calcula.
            $table->string('clave');

            // A qué puesto del catálogo va ese nombre.
            $table->foreignId('voting_place_id')->constrained('voting_places')->cascadeOnDelete();

            // El nombre tal como venía, para que la auditoría y la pantalla de
            // conciliación puedan decir **qué** se fusionó y no solo su hash.
            $table->string('municipio')->nullable();
            $table->string('puesto')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'clave'], 'e14_puesto_alias_unique');
            $table->index(['tenant_id', 'voting_place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e14_puesto_alias');
    }
};
