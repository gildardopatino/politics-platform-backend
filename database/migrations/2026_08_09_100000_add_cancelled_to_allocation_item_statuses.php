<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cancelled` en los estados de los ítems de una asignación (Spec 0057).
 *
 * Al cancelar una asignación pendiente, el controlador marca sus ítems como
 * `cancelled`, un valor que el CHECK de la columna nunca admitió. En SQLite
 * —donde corren las pruebas— eso pasa desapercibido porque el motor no aplica la
 * restricción; en PostgreSQL habría reventado con 500 la primera vez que alguien
 * cancelara algo. Es el mismo agujero que la Spec 0038 encontró en campañas, y
 * no salió antes porque hasta la 0057 el ciclo de estados no era alcanzable por
 * la API (hallazgo H3 de la 0056).
 *
 * Va por driver como el resto de las migraciones que tocan CHECKs (Spec 0001).
 */
return new class extends Migration
{
    private const ESTADOS = ['pending', 'delivered', 'returned', 'damaged', 'lost', 'cancelled'];

    private const ESTADOS_ANTES = ['pending', 'delivered', 'returned', 'damaged', 'lost'];

    public function up(): void
    {
        $this->fijarEstados(self::ESTADOS);
    }

    public function down(): void
    {
        DB::table('resource_allocation_items')->where('status', 'cancelled')->update(['status' => 'pending']);

        $this->fijarEstados(self::ESTADOS_ANTES);
    }

    /**
     * @param  array<int, string>  $estados
     */
    private function fijarEstados(array $estados): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $lista = implode(', ', array_map(fn (string $estado) => "'{$estado}'", $estados));

            DB::statement('ALTER TABLE resource_allocation_items DROP CONSTRAINT IF EXISTS resource_allocation_items_status_check');
            DB::statement("ALTER TABLE resource_allocation_items ADD CONSTRAINT resource_allocation_items_status_check CHECK (status IN ({$lista}))");

            return;
        }

        Schema::table('resource_allocation_items', function (Blueprint $table) use ($estados) {
            $table->enum('status', $estados)->default('pending')->change();
        });
    }
};
