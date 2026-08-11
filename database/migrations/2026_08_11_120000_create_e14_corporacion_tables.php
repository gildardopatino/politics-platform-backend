<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El resultado de un acta de corporación, en dos niveles (Spec 0067 · Parte B).
 *
 * Un acta de concejo, asamblea o senado no tiene «candidatos»: tiene
 * **agrupaciones**, y dentro de cada una, votos por la lista y por cada
 * candidato con voto preferente. Son dos niveles y los dos hacen falta: el total
 * de la agrupación es lo que reparte curules por cifra repartidora, y el del
 * preferente dice quién se las lleva dentro de la lista.
 *
 * ### Por qué en tablas propias y no en `e14_resultados`
 *
 * La spec ofrecía las dos formas. Se eligió la separada por dos razones
 * concretas, ambas de corrección y no de gusto:
 *
 * 1. **`e14_resultados` tiene un único `(e14_acta_id, numero)`** que protege al
 *    uninominal de contar dos veces al mismo candidato. En corporación ese par
 *    NO es único —el preferente 1 existe en todas las listas—, así que meterlos
 *    ahí obligaba a ampliar el índice con un `lista_numero` **nullable**; y un
 *    índice único con una columna nula deja de proteger las filas uninominales
 *    en PostgreSQL, donde dos NULL se consideran distintos. Habríamos cambiado
 *    un problema de corporación por un agujero en el uninominal.
 * 2. **`e14_resultados.e14_candidate_id` apunta al tarjetón**, que es único por
 *    `(evento, cargo, numero)`. En corporación el 5 de una lista y el 5 de otra
 *    son dos personas, así que ese catálogo los fundiría en una. El acta de
 *    corporación además **no trae nombres** —solo el número—, de modo que no hay
 *    nada que catalogar aquí: la identidad es `(lista, número)` y se guarda tal
 *    cual. El catálogo de corporación, si se quiere, es cosa de la 0082.
 *
 * Resultado: `e14_resultados` no se toca, y con él el consolidado, el cruce y el
 * cuadre uninominales siguen exactamente igual.
 *
 * ### Los preferentes cuelgan de la lista, no del acta
 *
 * La FK va a `e14_lista_resultados` y no a `e14_actas` porque un preferente no
 * existe sin su lista: así el único `(lista, numero)` es natural, y borrar un
 * acta arrastra listas y preferentes en cascada sin código que lo recuerde.
 * `tenant_id` y `e14_acta_id` van denormalizados para que el consolidado pueda
 * agrupar sin encadenar dos joins y para que `TenantScope` filtre directo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e14_lista_resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('e14_acta_id')->constrained('e14_actas')->onDelete('cascade');

            // El número del tarjetón de la agrupación. No cabe en un smallint
            // con holgura: en la muestra real aparecen listas 5170, 6497 y 2642
            // junto a las de un dígito.
            $table->unsignedInteger('lista_numero');
            $table->string('lista_nombre')->nullable();

            $table->unsignedInteger('votos_solo_lista')->default(0);
            // Lo que el acta declara en «TOTAL AGRUPACIÓN POLÍTICA». Se guarda
            // tal cual, sin recalcularlo: que discrepe de la suma es justo lo
            // que detecta una casilla mal leída (self-check de RF-B3).
            $table->unsignedInteger('total_agrupacion')->default(0);

            // Hay listas «SIN VOTO PREFERENTE»: un solo renglón, sin candidatos.
            // Se distingue para que la revisión no pinte una tabla vacía donde
            // el papel no tiene ninguna.
            $table->boolean('con_voto_preferente')->default(true);

            $table->timestamps();

            $table->unique(['e14_acta_id', 'lista_numero'], 'e14_lista_resultados_unique');
            $table->index(['tenant_id', 'e14_acta_id']);
        });

        Schema::create('e14_lista_preferentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('e14_acta_id')->constrained('e14_actas')->onDelete('cascade');
            $table->foreignId('e14_lista_resultado_id')
                ->constrained('e14_lista_resultados')->onDelete('cascade');

            // Solo el número: el E-14 de corporación no imprime el nombre del
            // candidato, y dejar una columna vacía invitaría a rellenarla con lo
            // que alguien recuerde.
            $table->unsignedSmallInteger('numero');
            $table->unsignedInteger('votos')->default(0);

            $table->timestamps();

            $table->unique(['e14_lista_resultado_id', 'numero'], 'e14_lista_preferentes_unique');
            $table->index(['tenant_id', 'e14_acta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e14_lista_preferentes');
        Schema::dropIfExists('e14_lista_resultados');
    }
};
