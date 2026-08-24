<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las actas que no eran de esta elección (Spec 0093 · RF-B6).
 *
 * Un tenant sirve a **una** elección, así que un acta de otra no se procesa: se
 * borra en duro —fila y archivo, como la 0077— para que no ensucie el
 * escrutinio. Borrar en silencio, en cambio, sería inexplicable: alguien sube
 * ochenta PDFs, aparecen setenta y seis actas y nadie sabe qué pasó con las
 * otras cuatro. Esta tabla es la respuesta a esa pregunta.
 *
 * **Sin PII.** Solo metadatos del archivo y las dos elecciones: el nombre del
 * PDF, su hash, qué se detectó, qué se esperaba y el motivo en español. Nada del
 * contenido del acta se guarda — el acta rechazada no se leyó.
 *
 * El **único por `(tenant_id, archivo_hash)`** es lo que hace idempotente el
 * rechazo (Art. VIII): el mismo PDF vuelto a cargar y vuelto a rechazar
 * actualiza su renglón en vez de acumular uno por intento. El hash puede ser
 * nulo —un acta sin archivo—, y varios nulos no colisionan ni en PostgreSQL ni
 * en SQLite, así que esos casos conviven.
 *
 * `tenant_id` va denormalizado para que `TenantScope` filtre directo, como en el
 * resto de las tablas del E-14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e14_actas_rechazadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            // Nullable y `nullOnDelete`: el rechazo sobrevive a que se borre la
            // elección, porque explica algo que pasó aunque la elección ya no
            // esté. Puede faltar de origen si el acta se rechaza antes de que se
            // le resolviera evento.
            $table->foreignId('electoral_event_id')->nullable()
                ->constrained('electoral_events')->nullOnDelete();

            $table->string('archivo_nombre')->nullable();
            $table->string('archivo_hash', 64)->nullable();

            // `null` = «no se pudo leer el encabezado». Se guarda igual cuando
            // el rechazo viene de otro sitio, para que el renglón se explique
            // solo sin consultar el código.
            $table->string('eleccion_detectada', 30)->nullable();
            $table->string('eleccion_esperada', 30)->nullable();

            // En español y ya redactado: es lo que el panel muestra tal cual
            // (Art. IX). No un código de error que alguien tenga que traducir.
            $table->string('motivo', 500);

            // Solo `created_at`: un rechazo es un hecho con fecha, no una fila
            // que se edite.
            $table->timestamp('created_at')->nullable();

            // El panel los lee del más reciente al más viejo, de un tenant.
            $table->index(['tenant_id', 'created_at']);
            $table->unique(['tenant_id', 'archivo_hash'], 'e14_rechazos_archivo_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e14_actas_rechazadas');
    }
};
