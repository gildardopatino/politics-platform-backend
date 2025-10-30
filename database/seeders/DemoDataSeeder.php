<?php

namespace Database\Seeders;

use App\Models\Barrio;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Commitment;
use App\Models\Commune;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Municipality;
use App\Models\MeetingAttendee;
use App\Models\MeetingTemplate;
use App\Models\Priority;
use App\Models\ResourceAllocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Crear Tenants de prueba
        $tenant1 = Tenant::create([
            'slug' => 'alcaldia-medellin',
            'nombre' => 'Alcaldía de Medellín',
            'tipo_cargo' => 'Alcaldia',
            'identificacion' => '1234567890',
            'phone_contacto' => '6044448500',
            'email_contacto' => 'contacto@medellin.gov.co',
            'metadata' => [
                'ciudad' => 'Medellín',
                'periodo' => '2024-2027'
            ]
        ]);

        $tenant2 = Tenant::create([
            'slug' => 'gobernacion-antioquia',
            'nombre' => 'Gobernación de Antioquia',
            'tipo_cargo' => 'Gobernacion',
            'identificacion' => '0987654321',
            'phone_contacto' => '6043859000',
            'email_contacto' => 'contacto@antioquia.gov.co',
            'metadata' => [
                'departamento' => 'Antioquia',
                'periodo' => '2024-2027'
            ]
        ]);

        // 2. Crear Usuarios de prueba
        $admin1 = User::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Carlos Rodríguez',
            'email' => 'carlos@alcaldiamedellin.gov.co',
            'password' => Hash::make('password123'),
            'phone' => '3001234567',
            'is_team_leader' => true,
            'is_super_admin' => false,
        ]);
        $admin1->assignRole('admin');

        $coordinator1 = User::create([
            'tenant_id' => $tenant1->id,
            'name' => 'María García',
            'email' => 'maria@alcaldiamedellin.gov.co',
            'password' => Hash::make('password123'),
            'phone' => '3002345678',
            'is_team_leader' => true,
            'is_super_admin' => false,
            'reports_to' => $admin1->id,
        ]);
        $coordinator1->assignRole('coordinator');

        $user1 = User::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Juan Pérez',
            'email' => 'juan@alcaldiamedellin.gov.co',
            'password' => Hash::make('password123'),
            'phone' => '3003456789',
            'is_team_leader' => false,
            'is_super_admin' => false,
            'reports_to' => $coordinator1->id,
        ]);
        $user1->assignRole('operator');

        $user2 = User::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Ana Martínez',
            'email' => 'ana@alcaldiamedellin.gov.co',
            'password' => Hash::make('password123'),
            'phone' => '3004567890',
            'is_team_leader' => false,
            'is_super_admin' => false,
            'reports_to' => $coordinator1->id,
        ]);
        $user2->assignRole('viewer');

        $admin2 = User::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Luis González',
            'email' => 'luis@gobantioquia.gov.co',
            'password' => Hash::make('password123'),
            'phone' => '3005678901',
            'is_team_leader' => true,
            'is_super_admin' => false,
        ]);
        $admin2->assignRole('admin');

        // 3. Obtener geografía existente
        $antioquia = Department::where('codigo', '05')->first();
        $medellin = Municipality::where('codigo', '05001')->first();
        $comuna1 = Commune::where('codigo', '01')->first();
        $barrio1 = Barrio::where('codigo', '0101')->first();

        // 4. Crear Templates de Reuniones
        $template1 = MeetingTemplate::create([
            'tenant_id' => $tenant1->id,
            'created_by' => $admin1->id,
            'name' => 'Reunión Comunitaria',
            'description' => 'Plantilla para reuniones con la comunidad',
            'fields' => [
                'agenda' => ['Bienvenida', 'Presentación de proyectos', 'Q&A', 'Cierre'],
                'duracion_estimada' => '2 horas'
            ]
        ]);

        $template2 = MeetingTemplate::create([
            'tenant_id' => $tenant1->id,
            'created_by' => $admin1->id,
            'name' => 'Reunión de Coordinación',
            'description' => 'Plantilla para reuniones internas del equipo',
            'fields' => [
                'agenda' => ['Revisión de avances', 'Planificación', 'Asignación de tareas'],
                'duracion_estimada' => '1 hora'
            ]
        ]);

        // Obtener datos de geografía para reuniones
        $departmentMedellin = Department::where('codigo', '05')->first();
        $municipalityMedellin = Municipality::where('codigo', '05001')->first();
        $commune = Commune::where('codigo', '01')->first();
        $barrio1 = Barrio::where('codigo', '0101')->first();

        // Crear reuniones
        $meeting1 = Meeting::create([
            'tenant_id' => $tenant1->id,
            'planner_user_id' => $coordinator1->id,
            'title' => 'Reunión Comunitaria - Comuna 1',
            'description' => 'Socialización de proyectos de infraestructura para la Comuna 1',
            'starts_at' => now()->addDays(7)->setTime(10, 0),
            'lugar_nombre' => 'Calle 106 # 51-20',
            'direccion' => 'Barrio Santo Domingo, Comuna 1',
            'department_id' => $departmentMedellin->id,
            'municipality_id' => $municipalityMedellin->id,
            'commune_id' => $commune->id,
            'barrio_id' => $barrio1->id,
            'latitude' => 6.3032,
            'longitude' => -75.5499,
            'status' => 'scheduled',
        ]);

        $meeting2 = Meeting::create([
            'tenant_id' => $tenant1->id,
            'planner_user_id' => $coordinator1->id,
            'title' => 'Reunión Comunitaria - Centro',
            'description' => 'Reunión informativa sobre servicios de salud',
            'starts_at' => now()->subDays(2)->setTime(15, 0),
            'ends_at' => now()->subDays(2)->setTime(17, 0),
            'lugar_nombre' => 'Plaza Mayor',
            'direccion' => 'Centro, Medellín',
            'department_id' => $departmentMedellin->id,
            'municipality_id' => $municipalityMedellin->id,
            'latitude' => 6.2518,
            'longitude' => -75.5636,
            'status' => 'completed',
        ]);

        $meeting3 = Meeting::create([
            'tenant_id' => $tenant2->id,
            'planner_user_id' => $admin2->id,
            'title' => 'Reunión de Coordinación Departamental',
            'description' => 'Coordinación de estrategias departamentales',
            'starts_at' => now()->addDays(3)->setTime(14, 0),
            'lugar_nombre' => 'Gobernación de Antioquia',
            'direccion' => 'Centro Administrativo La Alpujarra, Medellín',
            'department_id' => $departmentMedellin->id,
            'municipality_id' => $municipalityMedellin->id,
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'status' => 'scheduled',
        ]);

        // 5.1. Generar códigos QR para todas las reuniones
        $qrCodeService = app(\App\Services\QRCodeService::class);
        
        foreach ([$meeting1, $meeting2, $meeting3] as $meeting) {
            $qrData = $qrCodeService->generateForMeeting(
                $meeting->id,
                $meeting->tenant->slug
            );
            $meeting->update(['qr_code' => $qrData['code']]);
            $this->command->info("QR generado para reunión #{$meeting->id}: {$qrData['code']}");
        }

        // 6. Crear Asistentes
        MeetingAttendee::create([
            'meeting_id' => $meeting2->id,
            'created_by' => $coordinator1->id,
            'cedula' => '43123456',
            'nombres' => 'Pedro',
            'apellidos' => 'Ramírez',
            'telefono' => '3101234567',
            'email' => 'pedro.ramirez@example.com',
            'checked_in' => true,
            'checked_in_at' => now()->subDays(1)->addHours(1),
        ]);

        MeetingAttendee::create([
            'meeting_id' => $meeting2->id,
            'created_by' => $coordinator1->id,
            'cedula' => '52234567',
            'nombres' => 'Laura',
            'apellidos' => 'Gómez',
            'telefono' => '3112345678',
            'email' => 'laura.gomez@example.com',
            'checked_in' => true,
            'checked_in_at' => now()->subDays(1)->addHours(1),
        ]);

        MeetingAttendee::create([
            'meeting_id' => $meeting2->id,
            'created_by' => $coordinator1->id,
            'cedula' => '1098765432',
            'nombres' => 'Jorge',
            'apellidos' => 'Hernández',
            'telefono' => '3123456789',
            'checked_in' => false,
        ]);

        // 7. Crear Campañas
        $campaign1 = Campaign::create([
            'tenant_id' => $tenant1->id,
            'created_by' => $coordinator1->id,
            'title' => 'Invitación Reunión Comuna 1',
            'message' => 'Te invitamos a la reunión comunitaria el próximo sábado. Conoce los nuevos proyectos para tu barrio.',
            'channel' => 'both',
            'filter_json' => [
                'commune_id' => $commune->id,
                'age_range' => [18, 65]
            ],
            'scheduled_at' => now()->addDays(5),
            'status' => 'scheduled',
        ]);

        $campaign2 = Campaign::create([
            'tenant_id' => $tenant1->id,
            'created_by' => $coordinator1->id,
            'title' => 'Recordatorio Reunión Centro',
            'message' => 'Recordamos que mañana tenemos reunión sobre movilidad urbana. ¡Tu opinión cuenta!',
            'channel' => 'sms',
            'filter_json' => [
                'municipality_id' => $municipalityMedellin->id
            ],
            'scheduled_at' => now()->subDays(2),
            'sent_at' => now()->subDays(2),
            'status' => 'sent',
        ]);

        // 8. Crear Destinatarios de Campaña
        CampaignRecipient::create([
            'campaign_id' => $campaign2->id,
            'recipient_type' => 'phone',
            'recipient_value' => '3101234567',
            'status' => 'sent',
            'sent_at' => now()->subDays(2)->addMinutes(5),
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign2->id,
            'recipient_type' => 'phone',
            'recipient_value' => '3112345678',
            'status' => 'sent',
            'sent_at' => now()->subDays(2)->addMinutes(10),
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign2->id,
            'recipient_type' => 'phone',
            'recipient_value' => '3123456789',
            'status' => 'failed',
            'error_message' => 'Número no válido',
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign1->id,
            'recipient_type' => 'email',
            'recipient_value' => 'vecino1@example.com',
            'status' => 'pending',
        ]);

        // 9. Obtener Prioridades
        $prioridadAlta = Priority::where('name', 'Alta')->first();
        $prioridadMedia = Priority::where('name', 'Media')->first();
        $prioridadBaja = Priority::where('name', 'Baja')->first();

        // 10. Crear Compromisos
        Commitment::create([
            'tenant_id' => $tenant1->id,
            'meeting_id' => $meeting2->id,
            'assigned_user_id' => $user1->id,
            'priority_id' => $prioridadAlta->id,
            'description' => 'Elaborar informe de asistencia de la reunión',
            'due_date' => now()->addDays(3),
            'status' => 'in_progress',
            'notes' => 'Incluir análisis demográfico de asistentes',
            'created_by' => $coordinator1->id,
        ]);

        Commitment::create([
            'tenant_id' => $tenant1->id,
            'meeting_id' => $meeting2->id,
            'assigned_user_id' => $user2->id,
            'priority_id' => $prioridadAlta->id,
            'description' => 'Enviar acta de la reunión a todos los asistentes',
            'due_date' => now()->addDays(2),
            'status' => 'completed',
            'notes' => 'Enviado por correo electrónico',
            'created_by' => $coordinator1->id,
        ]);

        Commitment::create([
            'tenant_id' => $tenant1->id,
            'meeting_id' => $meeting1->id,
            'assigned_user_id' => $user1->id,
            'priority_id' => $prioridadMedia->id,
            'description' => 'Coordinar logística del evento (sillas, sonido, refrigerio)',
            'due_date' => now()->addDays(5),
            'status' => 'pending',
            'created_by' => $coordinator1->id,
        ]);

        Commitment::create([
            'tenant_id' => $tenant1->id,
            'meeting_id' => $meeting3->id,
            'assigned_user_id' => $coordinator1->id,
            'priority_id' => $prioridadBaja->id,
            'description' => 'Preparar presentación de resultados del trimestre',
            'due_date' => now()->addDays(1),
            'status' => 'pending',
            'created_by' => $admin1->id,
        ]);

        Commitment::create([
            'tenant_id' => $tenant1->id,
            'meeting_id' => $meeting2->id,
            'assigned_user_id' => $user2->id,
            'priority_id' => $prioridadMedia->id,
            'description' => 'Subir fotos del evento a redes sociales',
            'due_date' => now()->subDays(2),
            'status' => 'pending',
            'created_by' => $coordinator1->id,
        ]); // Este queda como vencido

        // 11. Crear Asignaciones de Recursos
        ResourceAllocation::create([
            'tenant_id' => $tenant1->id,
            'assigned_to_user_id' => $coordinator1->id,
            'assigned_by_user_id' => $admin1->id,
            'leader_user_id' => $coordinator1->id,
            'type' => 'cash',
            'amount' => 2500000,
            'details' => [
                'meeting_id' => $meeting1->id,
                'description' => 'Presupuesto para refrigerios y materiales'
            ],
            'allocation_date' => now()->subDays(5),
            'notes' => 'Para reunión comunitaria Comuna 1',
        ]);

        ResourceAllocation::create([
            'tenant_id' => $tenant1->id,
            'assigned_to_user_id' => $coordinator1->id,
            'assigned_by_user_id' => $admin1->id,
            'leader_user_id' => $coordinator1->id,
            'type' => 'material',
            'details' => [
                'meeting_id' => $meeting1->id,
                'items' => ['Carpas', 'Sillas', 'Equipo de sonido']
            ],
            'allocation_date' => now()->subDays(5),
            'notes' => 'Logística reunión Comuna 1',
        ]);

        ResourceAllocation::create([
            'tenant_id' => $tenant1->id,
            'assigned_to_user_id' => $coordinator1->id,
            'assigned_by_user_id' => $admin1->id,
            'leader_user_id' => $coordinator1->id,
            'type' => 'cash',
            'amount' => 1800000,
            'details' => [
                'meeting_id' => $meeting2->id,
                'description' => 'Presupuesto para publicidad y logística'
            ],
            'allocation_date' => now()->subDays(10),
            'status' => 'delivered',
        ]);

        ResourceAllocation::create([
            'tenant_id' => $tenant1->id,
            'assigned_to_user_id' => $coordinator1->id,
            'assigned_by_user_id' => $admin1->id,
            'leader_user_id' => $coordinator1->id,
            'type' => 'service',
            'amount' => 500000,
            'details' => [
                'meeting_id' => $meeting3->id,
                'description' => 'Servicio de streaming y grabación'
            ],
            'allocation_date' => now()->subDays(1),
        ]);

        ResourceAllocation::create([
            'tenant_id' => $tenant2->id,
            'assigned_to_user_id' => $admin2->id,
            'assigned_by_user_id' => $admin2->id,
            'leader_user_id' => $admin2->id,
            'type' => 'cash',
            'amount' => 15000000,
            'details' => [
                'description' => 'Presupuesto general para campañas del mes'
            ],
            'allocation_date' => now()->startOfMonth(),
        ]);

        $this->command->info('✅ Datos de prueba creados exitosamente!');
        $this->command->newLine();
        $this->command->info('📊 Resumen:');
        $this->command->info('  - 2 Tenants');
        $this->command->info('  - 6 Usuarios (2 Admin, 1 Coordinador, 3 Usuarios)');
        $this->command->info('  - 2 Templates de reuniones');
        $this->command->info('  - 3 Reuniones (1 completada, 2 programadas)');
        $this->command->info('  - 3 Asistentes');
        $this->command->info('  - 2 Campañas (1 completada, 1 pendiente)');
        $this->command->info('  - 4 Destinatarios de campaña');
        $this->command->info('  - 5 Compromisos (1 completado, 3 pendientes, 1 vencido)');
        $this->command->info('  - 5 Asignaciones de recursos');
        $this->command->newLine();
        $this->command->info('🔑 Credenciales de prueba:');
        $this->command->info('  Admin Tenant 1: carlos@alcaldiamedellin.gov.co / password123');
        $this->command->info('  Coordinador: maria@alcaldiamedellin.gov.co / password123');
        $this->command->info('  Usuario 1: juan@alcaldiamedellin.gov.co / password123');
        $this->command->info('  Usuario 2: ana@alcaldiamedellin.gov.co / password123');
        $this->command->info('  Admin Tenant 2: luis@gobantioquia.gov.co / password123');
    }
}
