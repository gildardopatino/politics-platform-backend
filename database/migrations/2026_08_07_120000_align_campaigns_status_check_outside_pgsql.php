<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paridad del CHECK de `campaigns.status` fuera de PostgreSQL (Spec 0012 → 0013).
 *
 * `2025_10_30_173908_update_campaigns_status_enum` añadió `pending` al enum,
 * pero **solo para pgsql**: en SQLite —que es donde corren las pruebas— la
 * columna se quedó con el juego original (`draft, scheduled, sending, sent,
 * failed`). Como `CampaignService` crea las campañas con `status = 'pending'`,
 * el alta reventaba contra el CHECK en pruebas y no en producción: la suite no
 * podía cubrir el flujo real de campañas.
 *
 * Es el mismo problema de portabilidad que la Spec 0001 mitigó en las
 * migraciones y la 0019 en los controllers. **No cambia producción**: en pgsql
 * esta migración no hace nada, porque allí el estado ya estaba admitido.
 */
return new class extends Migration
{
    private const ESTADOS = ['draft', 'pending', 'scheduled', 'sending', 'sent', 'failed'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->enum('status', self::ESTADOS)->default('draft')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed'])
                ->default('draft')
                ->change();
        });
    }
};
