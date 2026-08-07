<?php

namespace App\Jobs\Campaigns;

use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Campaign $campaign
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CampaignService $campaignService): void
    {
        // Allow processing of pending and scheduled campaigns
        if (! in_array($this->campaign->status, ['pending', 'scheduled'])) {
            return;
        }

        $this->campaign->update([
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        $batchSize = config('campaign.batch_size', 100);
        $sentCount = 0;
        $failedCount = 0;

        // `chunkById` y no `chunk`: este bucle SACA cada fila del conjunto que
        // está recorriendo (deja de estar `pending` en cuanto se envía). `chunk`
        // pagina con OFFSET, así que a partir del segundo lote el
        // desplazamiento se comía justo las filas que faltaban y quedaban
        // destinatarios sin enviar mientras la campaña se cerraba como `sent`
        // (hallazgo 🔴 de la Spec 0013, corregido por la 0037). `chunkById`
        // avanza por `id > último visto`, que no depende de cuántas filas siguen
        // cumpliendo el filtro.
        $this->campaign->recipients()
            ->where('status', 'pending')
            ->chunkById($batchSize, function ($recipients) use ($campaignService, &$sentCount, &$failedCount) {
                foreach ($recipients as $recipient) {
                    $success = $campaignService->sendToRecipient($recipient);

                    if ($success) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                    }

                    $this->campaign->update([
                        'sent_count' => $sentCount,
                        'failed_count' => $failedCount,
                    ]);
                }

                // Rate limiting: wait 1 second between batches
                sleep(1);
            });

        // Solo se da por enviada si no quedó nadie sin intentar. Enviar y fallar
        // son desenlaces; seguir `pending` es que el recorrido no terminó, y en
        // ese caso la campaña se queda en `sending` para que se note.
        if (! $this->campaign->recipients()->where('status', 'pending')->exists()) {
            $this->campaign->update([
                'status' => 'sent',
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->campaign->update([
            'status' => 'failed',
        ]);
    }
}
