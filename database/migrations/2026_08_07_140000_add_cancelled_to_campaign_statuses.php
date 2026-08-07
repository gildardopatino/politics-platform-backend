<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cancelled` en los estados de campañas y destinatarios (Spec 0038).
 *
 * `CampaignController@cancel` escribía `status = 'cancelled'`, un valor que el
 * CHECK de la columna no admitía: el endpoint respondía 500 y no había forma de
 * cancelar una campaña (hallazgo de la caracterización 0013). Cancelar además
 * tiene que poder marcar los destinatarios que aún no salieron, y su enum
 * tampoco contemplaba ese desenlace.
 *
 * Va por driver como el resto de las migraciones que tocan CHECKs (Spec 0001):
 * en PostgreSQL se reescribe la restricción con SQL crudo —que es lo único que
 * entiende— y fuera de él se redeclara la columna con el schema builder, que
 * recrea la tabla. Los dos caminos dejan **el mismo** juego de estados, que es
 * justo lo que faltaba en la migración de 2025_10_30 y hubo que arreglar en la
 * 0013.
 */
return new class extends Migration
{
    private const CAMPANNAS = ['draft', 'pending', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];

    private const CAMPANNAS_ANTES = ['draft', 'pending', 'scheduled', 'sending', 'sent', 'failed'];

    private const DESTINATARIOS = ['pending', 'sent', 'failed', 'bounced', 'cancelled'];

    private const DESTINATARIOS_ANTES = ['pending', 'sent', 'failed', 'bounced'];

    public function up(): void
    {
        $this->fijarEstados('campaigns', self::CAMPANNAS, 'draft');
        $this->fijarEstados('campaign_recipients', self::DESTINATARIOS, 'pending');
    }

    public function down(): void
    {
        // Nadie puede quedarse con un estado que la restricción anterior no
        // admite, o la vuelta atrás falla.
        DB::table('campaigns')->where('status', 'cancelled')->update(['status' => 'failed']);
        DB::table('campaign_recipients')->where('status', 'cancelled')->update(['status' => 'pending']);

        $this->fijarEstados('campaigns', self::CAMPANNAS_ANTES, 'draft');
        $this->fijarEstados('campaign_recipients', self::DESTINATARIOS_ANTES, 'pending');
    }

    /**
     * @param  array<int, string>  $estados
     */
    private function fijarEstados(string $tabla, array $estados, string $porDefecto): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $lista = implode(', ', array_map(fn (string $estado) => "'{$estado}'", $estados));

            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_status_check");
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_status_check CHECK (status IN ({$lista}))");

            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($estados, $porDefecto) {
            $table->enum('status', $estados)->default($porDefecto)->change();
        });
    }
};
