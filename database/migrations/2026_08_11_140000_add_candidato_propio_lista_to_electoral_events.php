<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lista del candidato propio, en corporación (Spec 0082).
 *
 * En alcaldía o gobernación «mi candidato» es un número del tarjetón y con eso
 * basta. En concejo, asamblea o senado no: el candidato es una persona **dentro
 * de una lista**, y su identidad es el par `(número de lista, número de
 * preferencia)`. El número suelto que guardaba la 0080 no alcanza — el cruce de
 * la 0083 tiene que contar la fila `(mi lista, mi preferente)` del E-14, y con
 * solo el preferente sumaría el 5 de todas las listas a la vez.
 *
 * Por eso la columna es **nullable** y no tiene defecto: `null` significa
 * literalmente «este candidato no está dentro de ninguna lista», que es lo que
 * pasa en uninominal. Así el contrato de la 0080 queda intacto y los lectores
 * del cruce (0062), el déficit (0076) y el rendimiento (0063) siguen leyendo
 * `candidato_propio_numero` sin enterarse de que existe una dimensión más.
 *
 * Se reutiliza `candidato_propio_numero` para el preferente en vez de añadir una
 * segunda columna de número: es el mismo concepto —cuál de las filas del acta es
 * la mía— contado al nivel que corresponda, y duplicarlo obligaría a preguntar
 * cuál de las dos vale en cada lectura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electoral_events', function (Blueprint $table) {
            // `unsignedInteger` y no un smallint: los números de lista llegan a
            // cinco cifras (5170, 6497, 2642 en la muestra real de la 0067).
            $table->unsignedInteger('candidato_propio_lista_numero')
                ->nullable()
                ->after('candidato_propio_numero');
        });
    }

    public function down(): void
    {
        Schema::table('electoral_events', function (Blueprint $table) {
            $table->dropColumn('candidato_propio_lista_numero');
        });
    }
};
