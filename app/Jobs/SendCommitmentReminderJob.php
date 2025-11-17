<?php

namespace App\Jobs;

use App\Models\Commitment;
use App\Models\TenantMessagingCredit;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendCommitmentReminderJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Commitment $commitment,
        public string $reminderType // 'assignment', '50_percent', '25_percent', 'due_date'
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(WhatsAppNotificationService $whatsappService): void
    {
        // Verificar que el compromiso no esté completado
        $this->commitment->refresh();
        
        if ($this->commitment->status === 'completed') {
            Log::info('Commitment reminder skipped - already completed', [
                'commitment_id' => $this->commitment->id,
                'reminder_type' => $this->reminderType,
            ]);
            return;
        }

        // Verificar que el usuario asignado tenga teléfono
        if (!$this->commitment->assignedUser || !$this->commitment->assignedUser->phone) {
            Log::warning('Commitment reminder skipped - no phone number', [
                'commitment_id' => $this->commitment->id,
                'assigned_user_id' => $this->commitment->assigned_user_id,
            ]);
            return;
        }

        // Construir mensaje según el tipo de recordatorio
        $message = $this->buildMessage();

        // Enviar WhatsApp
        $success = $whatsappService->sendMessage(
            $this->commitment->assignedUser->phone,
            $message,
            config('services.n8n.auth_token')
        );

        if ($success) {
            // Descontar crédito de WhatsApp
            $tenantCredit = TenantMessagingCredit::where('tenant_id', $this->commitment->tenant_id)->first();
            if ($tenantCredit) {
                $reference = "Commitment {$this->reminderType} reminder for commitment #{$this->commitment->id} to user #{$this->commitment->assigned_user_id}";
                $tenantCredit->consumeWhatsApp(1, $reference);
            }

            Log::info('Commitment reminder sent successfully', [
                'commitment_id' => $this->commitment->id,
                'assigned_user_id' => $this->commitment->assigned_user_id,
                'reminder_type' => $this->reminderType,
            ]);
        } else {
            Log::warning('Failed to send commitment reminder', [
                'commitment_id' => $this->commitment->id,
                'assigned_user_id' => $this->commitment->assigned_user_id,
                'reminder_type' => $this->reminderType,
            ]);
        }
    }

    /**
     * Build WhatsApp message based on reminder type
     */
    private function buildMessage(): string
    {
        $dueDate = \Carbon\Carbon::parse($this->commitment->due_date)->format('d/m/Y');
        $daysRemaining = \Carbon\Carbon::parse($this->commitment->due_date)->diffInDays(now(), false);
        $daysRemainingAbs = abs($daysRemaining);

        $message = "";

        switch ($this->reminderType) {
            case 'assignment':
                $message = "📋 *Nuevo Compromiso Asignado*\n\n";
                $message .= "*Descripción:* {$this->commitment->description}\n";
                $message .= "*Fecha límite:* {$dueDate}\n";
                
                if ($this->commitment->meeting) {
                    $message .= "*Reunión:* {$this->commitment->meeting->title}\n";
                }
                
                if ($this->commitment->priority) {
                    $message .= "*Prioridad:* {$this->commitment->priority->name}\n";
                }
                
                if ($this->commitment->notes) {
                    $message .= "*Notas:* {$this->commitment->notes}\n";
                }
                
                $message .= "\n¡Tienes {$daysRemainingAbs} días para completarlo!";
                break;

            case '50_percent':
                $message = "⏰ *Recordatorio de Compromiso (50% del tiempo)*\n\n";
                $message .= "*Descripción:* {$this->commitment->description}\n";
                $message .= "*Fecha límite:* {$dueDate}\n";
                $message .= "*Días restantes:* {$daysRemainingAbs}\n\n";
                $message .= "Ya pasó el 50% del tiempo. ¡No olvides completar este compromiso!";
                break;

            case '25_percent':
                $message = "⚠️ *Recordatorio Urgente de Compromiso (25% del tiempo)*\n\n";
                $message .= "*Descripción:* {$this->commitment->description}\n";
                $message .= "*Fecha límite:* {$dueDate}\n";
                $message .= "*Días restantes:* {$daysRemainingAbs}\n\n";
                $message .= "⚠️ Solo queda el 25% del tiempo. ¡Es momento de actuar!";
                break;

            case 'due_date':
                $message = "🚨 *COMPROMISO VENCE HOY*\n\n";
                $message .= "*Descripción:* {$this->commitment->description}\n";
                $message .= "*Fecha límite:* {$dueDate} (HOY)\n\n";
                $message .= "🚨 Este compromiso vence hoy. Por favor, complétalo lo antes posible.";
                break;
        }

        return $message;
    }
}
