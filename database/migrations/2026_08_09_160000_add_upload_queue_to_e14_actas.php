<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El acta pasa a tener vida antes de leerse (Spec 0071).
 *
 * En la 0061 un acta nacía ya leída: el lector transcribía el PDF en su máquina
 * y publicaba el resultado. Ahora el PDF se sube desde el navegador y se lee
 * después, en otro proceso, así que la fila existe mucho antes de que nadie sepa
 * de qué mesa es.
 *
 * De ahí los tres cambios:
 *
 * 1. **Zona, puesto y mesa se vuelven opcionales.** Son datos que están dentro
 *    del papel; exigirlos al subirlo obligaría a que alguien los tecleara a mano
 *    justo antes de que una máquina los lea, que es el trabajo que se está
 *    tratando de evitar. El índice único por mesa sigue en pie: en PostgreSQL y
 *    en SQLite varios nulos no colisionan entre sí, así que las actas sin leer
 *    conviven y la unicidad empieza a aplicar en cuanto el worker dice de qué
 *    mesa era.
 *
 * 2. **Tres estados nuevos** antes de los tres que ya había: `cargada` (subida,
 *    sin encolar), `pendiente` (encolada) y `procesando` (reclamada por un
 *    worker). El estado por defecto pasa a ser `cargada`, que es como nace un
 *    acta ahora.
 *
 * 3. **CHECK de verdad sobre `tipo` y `estado`.** Hasta ahora eran `string(20)`
 *    sin restricción: cualquier cosa entraba. Con la cola de por medio, un
 *    estado mal escrito no da un error visible sino un acta que no la reclama
 *    nadie y se queda ahí para siempre. Se declara por driver, como el resto de
 *    los CHECK del repo (Specs 0038 y 0057): PostgreSQL lo aplica y SQLite —donde
 *    corren las pruebas— lo ignora, así que la validación de entrada sigue siendo
 *    la que atrapa el caso en las pruebas.
 */
return new class extends Migration
{
    private const TIPOS = ['alcaldia', 'gobernacion', 'concejo', 'senado', 'asamblea_departamental'];

    private const ESTADOS = [
        'cargada', 'pendiente', 'procesando',
        'procesada', 'inconsistente', 'revision_manual',
    ];

    private const ESTADOS_ANTES = ['procesada', 'inconsistente', 'revision_manual'];

    public function up(): void
    {
        Schema::table('e14_actas', function (Blueprint $table) {
            // El lote de carga: agrupa los PDFs que entraron juntos, para que el
            // panel pueda decir «de los 120 que subiste, van 87».
            $table->uuid('upload_batch_id')->nullable()->after('archivo_hash');
            // Ruta del PDF en el disco. La URL firmada se emite al vuelo desde
            // aquí; guardar una URL sería guardar algo que caduca.
            $table->string('archivo_path')->nullable()->after('upload_batch_id');
            // Cuándo la reclamó un worker. Sin esto, un worker que se cae deja el
            // acta en `procesando` y nadie vuelve a mirarla.
            $table->timestamp('claimed_at')->nullable()->after('processed_at');
            $table->unsignedTinyInteger('intentos')->default(0)->after('claimed_at');

            $table->index(['tenant_id', 'upload_batch_id']);
            // El índice del reclamo: la consulta del worker filtra por estado y
            // tipo y pide la más vieja.
            $table->index(['tenant_id', 'estado', 'tipo']);
        });

        Schema::table('e14_actas', function (Blueprint $table) {
            $table->string('zona', 10)->nullable()->change();
            $table->string('puesto', 10)->nullable()->change();
            $table->string('mesa', 10)->nullable()->change();
        });

        $this->fijarCheck('tipo', self::TIPOS, 'alcaldia');
        $this->fijarCheck('estado', self::ESTADOS, 'cargada');
    }

    public function down(): void
    {
        // Las actas que aún no se leyeron no tienen equivalente en el esquema
        // anterior: sin mesa no caben en una columna obligatoria.
        DB::table('e14_actas')->whereIn('estado', ['cargada', 'pendiente', 'procesando'])->delete();
        DB::table('e14_actas')->whereNotIn('tipo', ['alcaldia'])->update(['tipo' => 'alcaldia']);

        $this->fijarCheck('estado', self::ESTADOS_ANTES, 'revision_manual');
        $this->quitarCheck('tipo');

        Schema::table('e14_actas', function (Blueprint $table) {
            $table->string('zona', 10)->nullable(false)->change();
            $table->string('puesto', 10)->nullable(false)->change();
            $table->string('mesa', 10)->nullable(false)->change();
        });

        Schema::table('e14_actas', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'upload_batch_id']);
            $table->dropIndex(['tenant_id', 'estado', 'tipo']);
            $table->dropColumn(['upload_batch_id', 'archivo_path', 'claimed_at', 'intentos']);
        });
    }

    /**
     * @param  array<int, string>  $valores
     */
    private function fijarCheck(string $columna, array $valores, string $porDefecto): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $lista = implode(', ', array_map(fn (string $valor) => "'{$valor}'", $valores));

            DB::statement("ALTER TABLE e14_actas DROP CONSTRAINT IF EXISTS e14_actas_{$columna}_check");
            DB::statement("ALTER TABLE e14_actas ADD CONSTRAINT e14_actas_{$columna}_check CHECK ({$columna} IN ({$lista}))");
            DB::statement("ALTER TABLE e14_actas ALTER COLUMN {$columna} SET DEFAULT '{$porDefecto}'");

            return;
        }

        Schema::table('e14_actas', function (Blueprint $table) use ($columna, $valores, $porDefecto) {
            $table->enum($columna, $valores)->default($porDefecto)->change();
        });
    }

    private function quitarCheck(string $columna): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE e14_actas DROP CONSTRAINT IF EXISTS e14_actas_{$columna}_check");

            return;
        }

        Schema::table('e14_actas', function (Blueprint $table) use ($columna) {
            $table->string($columna, 20)->change();
        });
    }
};
