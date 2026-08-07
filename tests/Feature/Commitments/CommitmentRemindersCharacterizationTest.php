<?php

namespace Tests\Feature\Commitments;

use App\Jobs\SendCommitmentReminderJob;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Priority;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use App\Models\TenantWhatsAppInstance;
use App\Models\User;
use App\Services\WhatsAppNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN de los recordatorios escalonados de compromisos (Spec 0012,
 * Fase 2).
 *
 * `CommitmentController::scheduleCommitmentReminders` —privado, y solo se
 * ejecuta en `store`— encola hasta cuatro `SendCommitmentReminderJob`:
 * `assignment` inmediato, `50_percent` y `25_percent` intermedios, y `due_date`
 * a las 08:00 del día de vencimiento.
 *
 * El tiempo va fijo en **2026-08-07 12:00** (America/Bogota) porque los `delay`
 * se calculan desde `now()`, y `due_date` es una fecha sin hora: los plazos
 * salen fraccionarios y el truncado a entero es parte del comportamiento que se
 * fija aquí.
 *
 * Nada sale a la red: `Queue::fake` para el encolado y `Http::fake` +
 * `preventStrayRequests` para la parte de WhatsApp.
 */
class CommitmentRemindersCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private string $token;

    private User $asignado;

    private Meeting $meeting;

    private Priority $prioridad;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();

        [$this->user, $this->token] = $this->createTenantWithUser(
            ['create_commitments', 'edit_commitments'],
            $this->tenant
        );

        $this->asignado = User::factory()->forTenant($this->tenant)->create(['phone' => '3001234567']);
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['title' => 'Asamblea barrial']);
        $this->prioridad = Priority::create(['name' => 'Alta', 'color' => '#fd7e14', 'order' => 3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // Qué se encola y con qué delay
    // ==================================================================

    public function test_un_plazo_holgado_encola_los_cuatro_recordatorios(): void
    {
        Queue::fake();

        // now = 2026-08-07 12:00 · vence el 27 → el plazo son 19,5 días
        // (`due_date` no tiene hora, así que cuenta hasta las 00:00 del día 27).
        $this->crearPorApi(['due_date' => '2026-08-27']);

        Queue::assertPushed(SendCommitmentReminderJob::class, 4);

        // Asignación: inmediata, sin delay.
        $this->assertRecordatorio('assignment', null);

        // 50%: now + (int)(19,5 × 0,5) = now + 9 días.
        $this->assertRecordatorio('50_percent', '2026-08-16 12:00:00');

        // 25%: now + (int)(19,5 × 0,75) = now + 14 días.
        $this->assertRecordatorio('25_percent', '2026-08-21 12:00:00');

        // Vencimiento: el propio día a las 08:00.
        $this->assertRecordatorio('due_date', '2026-08-27 08:00:00');
    }

    public function test_los_intermedios_se_miden_desde_hoy_y_se_truncan_a_dias_enteros(): void
    {
        Queue::fake();

        // 10 días y medio de plazo: 50% → (int) 5,25 = 5 días; 25% → (int) 7,875
        // = 7 días. El truncado siempre acerca los avisos al presente.
        $this->crearPorApi(['due_date' => '2026-08-18']);

        $this->assertRecordatorio('50_percent', '2026-08-12 12:00:00');
        $this->assertRecordatorio('25_percent', '2026-08-14 12:00:00');
    }

    public function test_el_recordatorio_de_vencimiento_va_a_las_ocho_de_la_manana_de_bogota(): void
    {
        Queue::fake();

        $this->crearPorApi(['due_date' => '2026-09-01']);

        Queue::assertPushed(SendCommitmentReminderJob::class, function (SendCommitmentReminderJob $job) {
            if ($job->reminderType !== 'due_date') {
                return false;
            }

            $this->assertSame('America/Bogota', $job->delay->timezoneName);
            $this->assertSame('08:00:00', $job->delay->format('H:i:s'));

            return true;
        });
    }

    public function test_los_cuatro_recordatorios_apuntan_al_mismo_compromiso(): void
    {
        Queue::fake();

        $id = $this->crearPorApi()->json('data.id');

        Queue::assertPushed(SendCommitmentReminderJob::class, function (SendCommitmentReminderJob $job) use ($id) {
            $this->assertSame($id, $job->commitment->id);

            return true;
        });
    }

    // ==================================================================
    // Casos borde del encolado
    // ==================================================================

    public function test_un_asignado_sin_telefono_no_encola_nada(): void
    {
        Queue::fake();

        $sinTelefono = User::factory()->forTenant($this->tenant)->create(['phone' => null]);

        $this->crearPorApi(['assigned_user_id' => $sinTelefono->id])
            ->assertJsonPath('whatsapp_notification_sent', false);

        Queue::assertNothingPushed();
    }

    public function test_un_plazo_de_dos_dias_o_menos_se_queda_sin_intermedios(): void
    {
        Queue::fake();

        // 1,5 días de plazo: la condición es `$totalDays > 2`.
        $this->crearPorApi(['due_date' => '2026-08-09']);

        Queue::assertPushed(SendCommitmentReminderJob::class, 2);

        $this->assertRecordatorio('assignment', null);
        $this->assertRecordatorio('due_date', '2026-08-09 08:00:00');
        $this->assertSinRecordatorio('50_percent');
        $this->assertSinRecordatorio('25_percent');
    }

    public function test_una_fecha_de_vencimiento_pasada_deja_solo_el_aviso_de_asignacion(): void
    {
        Queue::fake();

        // `due_date` solo se valida como `date`: nada impide crear un compromiso
        // que ya nació vencido.
        $this->crearPorApi(['due_date' => '2026-08-01'])->assertStatus(201);

        Queue::assertPushed(SendCommitmentReminderJob::class, 1);
        $this->assertRecordatorio('assignment', null);
    }

    public function test_un_compromiso_que_vence_hoy_no_recibe_aviso_de_vencimiento(): void
    {
        Queue::fake();

        // Las 08:00 de hoy ya pasaron (son las 12:00), así que ese recordatorio
        // se descarta. Creado antes de las 08:00 sí se habría encolado.
        $this->crearPorApi(['due_date' => '2026-08-07']);

        Queue::assertPushed(SendCommitmentReminderJob::class, 1);
        $this->assertSinRecordatorio('due_date');
    }

    public function test_hallazgo_con_plazos_cortos_los_dos_intermedios_caen_a_la_vez(): void
    {
        Queue::fake();

        // 2,5 días: 50% → (int) 1,25 = 1 día; 25% → (int) 1,875 = 1 día. Las dos
        // fechas coinciden, así que la persona recibe dos WhatsApp seguidos —uno
        // de ellos «solo queda el 25% del tiempo»— el mismo instante.
        $this->crearPorApi(['due_date' => '2026-08-10']);

        $this->assertRecordatorio('50_percent', '2026-08-08 12:00:00');
        $this->assertRecordatorio('25_percent', '2026-08-08 12:00:00');
    }

    public function test_hallazgo_actualizar_el_compromiso_no_reprograma_nada(): void
    {
        Queue::fake();

        $id = $this->crearPorApi(['due_date' => '2026-08-27'])->json('data.id');

        Queue::assertPushed(SendCommitmentReminderJob::class, 4);

        // Cambiar la fecha límite o la persona asignada no encola ni cancela
        // nada: los recordatorios viejos siguen en pie con las fechas viejas y
        // el nuevo asignado no recibe su aviso. `scheduleCommitmentReminders`
        // solo se llama desde `store`.
        $otro = User::factory()->forTenant($this->tenant)->create(['phone' => '3009998877']);

        $this->comoUsuario()->putJson("/api/v1/commitments/{$id}", [
            'due_date' => '2026-12-31',
            'assigned_user_id' => $otro->id,
        ])->assertStatus(200);

        Queue::assertPushed(SendCommitmentReminderJob::class, 4);
    }

    public function test_hallazgo_completar_el_compromiso_no_cancela_los_recordatorios(): void
    {
        Queue::fake();

        $id = $this->crearPorApi(['due_date' => '2026-08-27'])->json('data.id');

        $this->comoUsuario()->postJson("/api/v1/commitments/{$id}/complete")->assertStatus(200);

        // Siguen los cuatro en la cola. Quien evita el mensaje es el propio job,
        // que vuelve a mirar el estado al ejecutarse (ver más abajo). Encolar y
        // descartar cuatro veces es el diseño actual.
        Queue::assertPushed(SendCommitmentReminderJob::class, 4);
    }

    // ==================================================================
    // El job en ejecución (WhatsApp mockeado)
    // ==================================================================

    public function test_el_job_envia_el_whatsapp_por_evolution_y_descuenta_credito(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'ABC']], 200)]);

        $this->instanciaWhatsApp();
        $credito = $this->creditos(10);
        $compromiso = $this->crearEnBase();

        (new SendCommitmentReminderJob($compromiso, 'assignment'))
            ->handle(app(WhatsAppNotificationService::class));

        Http::assertSent(function ($request) {
            $this->assertSame('https://evolution.test/message/sendText/instancia-1', $request->url());
            // El servicio normaliza el número colombiano de 10 dígitos con el 57.
            $this->assertSame('573001234567', $request['number']);
            $this->assertStringContainsString('Nuevo Compromiso Asignado', $request['text']);
            $this->assertStringContainsString('Llevar el acta a la junta', $request['text']);

            return true;
        });

        $this->assertSame(9, $credito->fresh()->whatsapp_available);
        $this->assertSame(1, $credito->fresh()->whatsapp_used);
    }

    public function test_el_job_no_envia_nada_si_el_compromiso_ya_esta_completado(): void
    {
        Http::fake();

        $this->instanciaWhatsApp();
        $compromiso = $this->crearEnBase(['status' => 'completed']);

        (new SendCommitmentReminderJob($compromiso, 'due_date'))
            ->handle(app(WhatsAppNotificationService::class));

        Http::assertNothingSent();
    }

    public function test_el_job_no_envia_nada_si_el_asignado_se_quedo_sin_telefono(): void
    {
        Http::fake();

        $this->instanciaWhatsApp();
        $compromiso = $this->crearEnBase();

        $this->asignado->update(['phone' => null]);

        (new SendCommitmentReminderJob($compromiso, '50_percent'))
            ->handle(app(WhatsAppNotificationService::class));

        Http::assertNothingSent();
    }

    public function test_el_job_no_revienta_si_el_tenant_no_tiene_instancia_de_whatsapp(): void
    {
        Http::fake();

        $compromiso = $this->crearEnBase();

        (new SendCommitmentReminderJob($compromiso, 'assignment'))
            ->handle(app(WhatsAppNotificationService::class));

        Http::assertNothingSent();
    }

    public function test_hallazgo_el_credito_solo_se_descuenta_si_hay_fila_de_creditos(): void
    {
        // `consumeWhatsApp` solo corre si existe `tenant_messaging_credits` para
        // el tenant. Sin esa fila el mensaje sale igual y **no se cobra**: el
        // saldo no es una autorización previa, es un contador posterior.
        Http::fake(['*' => Http::response([], 200)]);

        $this->instanciaWhatsApp();
        $compromiso = $this->crearEnBase();

        $this->assertDatabaseCount('tenant_messaging_credits', 0);

        (new SendCommitmentReminderJob($compromiso, 'assignment'))
            ->handle(app(WhatsAppNotificationService::class));

        Http::assertSentCount(1);
        $this->assertDatabaseCount('tenant_messaging_credits', 0);
    }

    public function test_cada_tipo_de_recordatorio_tiene_su_propio_mensaje(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->instanciaWhatsApp();
        $compromiso = $this->crearEnBase();

        $esperado = [
            'assignment' => 'Nuevo Compromiso Asignado',
            '50_percent' => 'Recordatorio de Compromiso (50% del tiempo)',
            '25_percent' => 'Recordatorio Urgente de Compromiso (25% del tiempo)',
            'due_date' => 'COMPROMISO VENCE HOY',
        ];

        foreach ($esperado as $tipo => $titulo) {
            (new SendCommitmentReminderJob($compromiso, $tipo))
                ->handle(app(WhatsAppNotificationService::class));

            Http::assertSent(fn ($request) => str_contains($request['text'], $titulo));
        }

        Http::assertSentCount(4);
    }

    // ------------------------------------------------------------------

    private function comoUsuario(): static
    {
        return $this->actingAsTenantUser($this->user, $this->token);
    }

    /**
     * Alta por API, que es el único camino que programa recordatorios.
     *
     * @param  array<string, mixed>  $extra
     */
    private function crearPorApi(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->comoUsuario()->postJson('/api/v1/commitments', array_merge([
            'meeting_id' => $this->meeting->id,
            'assigned_user_id' => $this->asignado->id,
            'priority_id' => $this->prioridad->id,
            'description' => 'Llevar el acta a la junta',
            'due_date' => '2026-08-27',
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function crearEnBase(array $atributos = []): Commitment
    {
        return Commitment::factory()->forTenant($this->tenant)->create(array_merge([
            'meeting_id' => $this->meeting->id,
            'assigned_user_id' => $this->asignado->id,
            'priority_id' => $this->prioridad->id,
            'created_by' => $this->user->id,
            'description' => 'Llevar el acta a la junta',
            'due_date' => '2026-08-27',
        ], $atributos));
    }

    private function instanciaWhatsApp(): TenantWhatsAppInstance
    {
        return TenantWhatsAppInstance::create([
            'tenant_id' => $this->tenant->id,
            'phone_number' => '573000000000',
            'instance_name' => 'instancia-1',
            'evolution_api_key' => 'clave-de-prueba',
            'evolution_api_url' => 'https://evolution.test',
            'daily_message_limit' => 1000,
            'messages_sent_today' => 0,
            'last_reset_date' => Carbon::today(),
            'is_active' => true,
        ]);
    }

    private function creditos(int $disponibles): TenantMessagingCredit
    {
        return TenantMessagingCredit::create([
            'tenant_id' => $this->tenant->id,
            'emails_available' => 0,
            'emails_used' => 0,
            'whatsapp_available' => $disponibles,
            'whatsapp_used' => 0,
        ]);
    }

    /**
     * Un recordatorio de ese tipo se encoló, y con ese instante de entrega
     * (`null` = inmediato).
     */
    private function assertRecordatorio(string $tipo, ?string $entrega): void
    {
        Queue::assertPushed(
            SendCommitmentReminderJob::class,
            function (SendCommitmentReminderJob $job) use ($tipo, $entrega) {
                if ($job->reminderType !== $tipo) {
                    return false;
                }

                $this->assertSame(
                    $entrega,
                    $job->delay?->toDateTimeString(),
                    "El recordatorio {$tipo} no se entrega cuando se esperaba."
                );

                return true;
            }
        );
    }

    private function assertSinRecordatorio(string $tipo): void
    {
        Queue::assertNotPushed(
            SendCommitmentReminderJob::class,
            fn (SendCommitmentReminderJob $job) => $job->reminderType === $tipo
        );
    }
}
