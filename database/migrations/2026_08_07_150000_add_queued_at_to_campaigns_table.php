<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `campaigns.queued_at`: cuándo se encoló el envío (Spec 0038).
 *
 * El alta ya despacha `SendCampaignJob` y deja la campaña en `pending`, que es
 * justo el estado que `POST /campaigns/{id}/send` exigía: pulsar «enviar»
 * encolaba un **segundo** job de la misma campaña y, si el primero aún no había
 * corrido, la gente recibía el mensaje dos veces (hallazgo de la 0013).
 *
 * El estado no basta para saberlo —`pending` significa «aún no ha empezado», no
 * «nadie la ha encolado»—, así que se marca el despacho con su propia fecha.
 *
 * **Backfill:** toda campaña que existe salió de `CampaignService::createCampaign`,
 * que despacha siempre; se les pone `queued_at = created_at` para que `send` no
 * las vuelva a encolar. Las que estén en `draft` no pasaron por ahí y se quedan
 * sin marcar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('scheduled_at');
        });

        DB::table('campaigns')
            ->whereNull('queued_at')
            ->where('status', '<>', 'draft')
            ->update(['queued_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('queued_at');
        });
    }
};
