<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultados electorales reales, leídos de las actas E-14 (Spec 0061 · Parte A).
 *
 * Un acta E-14 es el papel que firman los jurados al cerrar una mesa. Lo que se
 * guarda aquí no es una estimación ni una encuesta: es el escrutinio, y por eso
 * la tabla lleva **las tres cifras del cuadre** —lo que suman las casillas, lo
 * que el acta declara y lo que había en la urna— en vez de solo el total. Sin
 * las tres no se puede distinguir «leí mal» de «el jurado sumó mal», que es
 * justo la distinción que decide si un resultado se publica.
 *
 * ### Por qué mesa/puesto van por código y no por FK
 *
 * `voting_places` existe, pero es un catálogo **global sin `tenant_id`**, que se
 * llena solo cuando el webhook de Registraduría reporta un puesto, con tres
 * cadenas de texto libre como clave natural y sin relación con
 * `departments`/`municipalities`. Y **mesa no existe como entidad**: es una
 * columna de texto en `voters`/`leads`. Colgar el escrutinio de ahí sería
 * apoyarlo en datos que ningún proceso garantiza. Se enlaza por códigos, que es
 * la alternativa que la propia spec contempla; cuando exista un catálogo de
 * mesas de verdad, añadir la FK es aditivo.
 *
 * ### Los dos índices únicos
 *
 * - por **mesa** (tenant, evento, tipo, zona, puesto, mesa): una mesa se
 *   escruta una vez. Reenviarla actualiza la fila, no crea otra.
 * - por **hash del archivo**: el mismo PDF no puede quedar archivado bajo dos
 *   mesas distintas. Si pasa, es un error de radicación y conviene que reviente
 *   temprano en vez de duplicar votos en el consolidado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electoral_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('nombre');
            $table->date('fecha')->nullable();
            $table->string('tipo', 20); // alcaldia | concejo | gobernacion | ...
            $table->timestamps();

            // Un evento por (tenant, tipo, nombre): es lo que permite que la
            // ingesta lo resuelva sola sin crear duplicados en cada acta.
            $table->unique(['tenant_id', 'tipo', 'nombre'], 'electoral_events_unique');
        });

        Schema::create('e14_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('electoral_event_id')->constrained('electoral_events')->onDelete('cascade');
            $table->unsignedSmallInteger('numero'); // el del tarjetón
            $table->string('nombre');
            $table->string('agrupacion')->nullable();
            $table->string('cargo', 20);
            $table->timestamps();

            $table->unique(['electoral_event_id', 'cargo', 'numero'], 'e14_candidates_unique');
            $table->index(['tenant_id', 'electoral_event_id']);
        });

        Schema::create('e14_actas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('electoral_event_id')->constrained('electoral_events')->onDelete('cascade');
            $table->string('tipo', 20); // alcaldia | concejo

            // Ubicación de la mesa, por códigos (ver cabecera).
            $table->string('departamento_code', 10)->nullable();
            $table->string('municipio_code', 10)->nullable();
            $table->string('zona', 10);
            $table->string('puesto', 10);
            $table->string('mesa', 10);
            $table->string('lugar')->nullable(); // el nombre del puesto tal como sale impreso

            $table->string('archivo_nombre')->nullable();
            $table->string('archivo_hash', 64)->nullable();

            // procesada | inconsistente | revision_manual
            $table->string('estado', 20)->default('revision_manual');

            $table->unsignedInteger('suma_calculada')->default(0);
            $table->unsignedInteger('suma_declarada')->default(0);
            $table->unsignedInteger('votos_urna')->default(0);
            $table->unsignedInteger('votantes_e11')->default(0);
            // Puede ser negativa (más votos que votantes registrados): eso es
            // una anomalía grave y tiene que poder representarse, no truncarse.
            $table->integer('dif_nivelacion')->default(0);

            $table->unsignedInteger('votos_blanco')->default(0);
            $table->unsignedInteger('votos_nulos')->default(0);
            $table->unsignedInteger('votos_no_marcados')->default(0);

            $table->string('fuente', 10)->default('vision'); // vision | manual
            $table->decimal('confianza', 5, 2)->nullable();  // 0..100, si el lector la reporta
            $table->text('observacion')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'electoral_event_id', 'tipo', 'zona', 'puesto', 'mesa'],
                'e14_actas_mesa_unique'
            );
            $table->unique(['tenant_id', 'archivo_hash'], 'e14_actas_hash_unique');
            $table->index(['tenant_id', 'estado']);
            $table->index(['tenant_id', 'puesto']);
            $table->index(['tenant_id', 'zona']);
        });

        Schema::create('e14_resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('e14_acta_id')->constrained('e14_actas')->onDelete('cascade');
            $table->foreignId('e14_candidate_id')->nullable()
                ->constrained('e14_candidates')->nullOnDelete();
            $table->unsignedSmallInteger('numero');
            // Lo que el lector transcribió, aunque el catálogo diga otra cosa:
            // si la visión leyó mal un nombre, queda el rastro.
            $table->string('nombre')->nullable();
            $table->unsignedInteger('votos')->default(0);
            $table->timestamps();

            $table->unique(['e14_acta_id', 'numero'], 'e14_resultados_unique');
            $table->index(['tenant_id', 'e14_candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e14_resultados');
        Schema::dropIfExists('e14_actas');
        Schema::dropIfExists('e14_candidates');
        Schema::dropIfExists('electoral_events');
    }
};
