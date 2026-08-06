<?php

namespace Database\Seeders;

use App\Models\Barrio;
use App\Models\Campaign;
use App\Models\Commitment;
use App\Models\LandingBanner;
use App\Models\LandingEvento;
use App\Models\LandingPropuesta;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Municipality;
use App\Models\Priority;
use App\Models\ResourceItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TipoVotante;
use App\Models\User;
use App\Models\Voter;
use App\Scopes\TenantScope;
use App\Services\TenantProvisioningService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos demo para desarrollo y pruebas (Spec 0003).
 *
 * Dos claves de diseño:
 *
 * 1. **El alta de cada tenant pasa por `TenantProvisioningService`**, el mismo
 *    que usa `POST /tenants`. Antes este seeder asignaba los roles GLOBALES a
 *    usuarios de tenant, mientras la API clona un juego de roles por tenant: dos
 *    caminos divergentes para la misma operación.
 *
 * 2. **`tenant_id` explícito en todo `create`**. En un seeder no hay usuario
 *    autenticado, así que el trait `HasTenant` no autorrellena `tenant_id` y los
 *    modelos con la columna NOT NULL revientan (era lo que rompía
 *    `migrate:fresh --seed`). Además se enlaza `current_tenant_id` en el
 *    contenedor por bloque de tenant, para que `TenantScope` filtre las lecturas
 *    y para los modelos que sí consultan ese binding (`MeetingAttendee`).
 *
 * Fechas fijas: los datos deben ser deterministas para poder aserirlos.
 *
 * Credenciales: `docs/DEMO_DATA.md`.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Contraseña de todos los usuarios demo. Solo para entornos de desarrollo.
     */
    public const PASSWORD = 'Demo1234!';

    /**
     * Ancla temporal de los datos demo. Todo se calcula relativo a ella.
     */
    public const FECHA_BASE = '2026-08-01 08:00:00';

    /**
     * Roles no-admin que recibe cada tenant demo, con su usuario de ejemplo.
     *
     * @var array<string, array{nombre: string, prefijo: string}>
     */
    private const USUARIOS_POR_ROL = [
        'coordinator' => ['nombre' => 'Coordinador', 'prefijo' => 'coordinador'],
        'operator' => ['nombre' => 'Operador', 'prefijo' => 'operador'],
        'viewer' => ['nombre' => 'Visor', 'prefijo' => 'visor'],
    ];

    public function run(): void
    {
        $base = Carbon::parse(self::FECHA_BASE);

        foreach ($this->tenantsDemo() as $definicion) {
            $tenant = app(TenantProvisioningService::class)->provision($definicion['provision']);

            $this->enContextoDe($tenant, function () use ($tenant, $definicion, $base) {
                $usuarios = $this->crearUsuarios($tenant, $definicion['dominio']);

                $this->crearVotantes($tenant, $definicion['votantes']);
                $meetings = $this->crearReuniones($tenant, $usuarios, $definicion, $base);
                $this->crearAsistentes($tenant, $meetings['pasada'], $usuarios['admin'], $definicion['asistentes'], $base);
                $this->crearCompromisos($tenant, $meetings, $usuarios, $base);
                $this->crearCampana($tenant, $usuarios['admin'], $definicion, $base);
                $this->crearRecursos($tenant);
                $this->crearLanding($tenant, $definicion, $base);
            });
        }

        // Deja el contenedor sin tenant fijado: los seeders posteriores (y las
        // pruebas que invocan este seeder) no deben heredar el filtro.
        app()->instance('current_tenant_id', null);
    }

    /**
     * Ejecuta un bloque con el tenant fijado en el contenedor.
     */
    private function enContextoDe(Tenant $tenant, callable $bloque): void
    {
        app()->instance('current_tenant_id', $tenant->id);

        try {
            $bloque();
        } finally {
            app()->instance('current_tenant_id', null);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tenantsDemo(): array
    {
        return [
            [
                'dominio' => 'medellin.demo',
                'provision' => [
                    'slug' => 'alcaldia-medellin',
                    'nombre' => 'Alcaldía de Medellín',
                    'tipo_cargo' => 'Alcaldia',
                    'identificacion' => '1234567890',
                    'email_contacto' => 'contacto@medellin.demo',
                    'phone_contacto' => '6044448500',
                    'metadata' => ['ciudad' => 'Medellín', 'periodo' => '2024-2027'],
                    'admin_name' => 'Carlos Rodríguez',
                    'admin_email' => 'admin@medellin.demo',
                    'admin_password' => self::PASSWORD,
                    'initial_emails' => 5000,
                    'initial_whatsapp' => 2000,
                ],
                'municipio' => 'Medellín',
                'lugar' => 'Casa de la Cultura',
                'campana' => 'Invitación a la asamblea barrial',
                'votantes' => [
                    ['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'],
                    ['cedula' => '71000002', 'nombres' => 'Julián', 'apellidos' => 'Mesa'],
                    ['cedula' => '71000003', 'nombres' => 'Sofía', 'apellidos' => 'Cardona'],
                    ['cedula' => '71000004', 'nombres' => 'Andrés', 'apellidos' => 'Vélez'],
                ],
                'asistentes' => [
                    ['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo', 'checked_in' => true],
                    ['cedula' => '71000002', 'nombres' => 'Julián', 'apellidos' => 'Mesa', 'checked_in' => true],
                    ['cedula' => '71000003', 'nombres' => 'Sofía', 'apellidos' => 'Cardona', 'checked_in' => false],
                ],
            ],
            [
                'dominio' => 'antioquia.demo',
                'provision' => [
                    'slug' => 'gobernacion-antioquia',
                    'nombre' => 'Gobernación de Antioquia',
                    'tipo_cargo' => 'Gobernacion',
                    'identificacion' => '0987654321',
                    'email_contacto' => 'contacto@antioquia.demo',
                    'phone_contacto' => '6043859000',
                    'metadata' => ['departamento' => 'Antioquia', 'periodo' => '2024-2027'],
                    'admin_name' => 'Marta Gómez',
                    'admin_email' => 'admin@antioquia.demo',
                    'admin_password' => self::PASSWORD,
                    'initial_emails' => 3000,
                    'initial_whatsapp' => 1000,
                ],
                'municipio' => 'Medellín',
                'lugar' => 'Sede Gobernación',
                'campana' => 'Convocatoria a líderes municipales',
                'votantes' => [
                    ['cedula' => '72000001', 'nombres' => 'Beatriz', 'apellidos' => 'Ospina'],
                    ['cedula' => '72000002', 'nombres' => 'Camilo', 'apellidos' => 'Arango'],
                    ['cedula' => '72000003', 'nombres' => 'Diana', 'apellidos' => 'Zapata'],
                    ['cedula' => '72000004', 'nombres' => 'Esteban', 'apellidos' => 'Muñoz'],
                ],
                'asistentes' => [
                    ['cedula' => '72000001', 'nombres' => 'Beatriz', 'apellidos' => 'Ospina', 'checked_in' => true],
                    ['cedula' => '72000002', 'nombres' => 'Camilo', 'apellidos' => 'Arango', 'checked_in' => false],
                ],
            ],
        ];
    }

    /**
     * El admin lo crea el servicio de aprovisionamiento; aquí se añaden los
     * usuarios de los demás roles, cada uno con el rol CLONADO de su tenant.
     *
     * @return array<string, \App\Models\User>
     */
    private function crearUsuarios(Tenant $tenant, string $dominio): array
    {
        $usuarios = ['admin' => app(TenantProvisioningService::class)->adminDe($tenant)];

        foreach (self::USUARIOS_POR_ROL as $rol => $datos) {
            $usuario = User::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                ['email' => "{$datos['prefijo']}@{$dominio}"],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "{$datos['nombre']} {$tenant->nombre}",
                    'password' => Hash::make(self::PASSWORD),
                    'is_super_admin' => false,
                    'reports_to' => $usuarios['admin']?->id,
                ]
            );

            $rolDelTenant = Role::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('name', $rol)
                ->where('guard_name', 'api')
                ->first();

            if ($rolDelTenant && ! $usuario->hasRole($rolDelTenant)) {
                $usuario->assignRole($rolDelTenant);
            }

            $usuarios[$rol] = $usuario;
        }

        return $usuarios;
    }

    /**
     * @param  array<int, array<string, string>>  $definiciones
     */
    private function crearVotantes(Tenant $tenant, array $definiciones): void
    {
        $tipo = TipoVotante::firstOrCreate(['descripcion' => 'Elector']);
        $barrio = Barrio::first();

        foreach ($definiciones as $votante) {
            Voter::firstOrCreate(
                ['tenant_id' => $tenant->id, 'cedula' => $votante['cedula']],
                [
                    'nombres' => $votante['nombres'],
                    'apellidos' => $votante['apellidos'],
                    'email' => strtolower($votante['nombres']).'.'.strtolower($votante['apellidos']).'@votante.demo',
                    'telefono' => '30'.$votante['cedula'],
                    'barrio_id' => $barrio?->id,
                    'tipo_votante_id' => $tipo->id,
                ]
            );
        }
    }

    /**
     * Una reunión ya celebrada y otra por venir, para que el calendario y los
     * listados tengan ambos estados.
     *
     * @param  array<string, \App\Models\User>  $usuarios
     * @param  array<string, mixed>  $definicion
     * @return array{pasada: \App\Models\Meeting, proxima: \App\Models\Meeting}
     */
    private function crearReuniones(Tenant $tenant, array $usuarios, array $definicion, Carbon $base): array
    {
        $municipio = Municipality::where('nombre', $definicion['municipio'])->first();
        $barrio = Barrio::first();

        $comun = [
            'tenant_id' => $tenant->id,
            'planner_user_id' => $usuarios['admin']?->id,
            'lugar_nombre' => $definicion['lugar'],
            'direccion' => 'Calle 50 #45-30',
            'municipality_id' => $municipio?->id,
            'department_id' => $municipio?->department_id,
            'barrio_id' => $barrio?->id,
        ];

        $pasada = Meeting::firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Asamblea comunitaria'],
            $comun + [
                'description' => 'Encuentro con líderes del territorio.',
                'starts_at' => $base->copy()->subWeeks(2),
                'ends_at' => $base->copy()->subWeeks(2)->addHours(2),
                'status' => 'completed',
            ]
        );

        $proxima = Meeting::firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Reunión de planeación'],
            $comun + [
                'description' => 'Planeación de la siguiente jornada.',
                'starts_at' => $base->copy()->addWeeks(2),
                'ends_at' => $base->copy()->addWeeks(2)->addHours(3),
                'status' => 'scheduled',
            ]
        );

        return ['pasada' => $pasada, 'proxima' => $proxima];
    }

    /**
     * @param  array<int, array<string, mixed>>  $definiciones
     */
    private function crearAsistentes(Tenant $tenant, Meeting $meeting, ?User $creador, array $definiciones, Carbon $base): void
    {
        foreach ($definiciones as $asistente) {
            MeetingAttendee::firstOrCreate(
                ['meeting_id' => $meeting->id, 'cedula' => $asistente['cedula']],
                [
                    'tenant_id' => $tenant->id,
                    'created_by' => $creador?->id,
                    'nombres' => $asistente['nombres'],
                    'apellidos' => $asistente['apellidos'],
                    'telefono' => '30'.$asistente['cedula'],
                    'checked_in' => $asistente['checked_in'],
                    'checked_in_at' => $asistente['checked_in'] ? $base->copy()->subWeeks(2) : null,
                ]
            );
        }
    }

    /**
     * Uno vencido, uno en curso y uno cumplido: cubre los tres estados que
     * consultan los listados y el endpoint `commitments/overdue`.
     *
     * @param  array{pasada: \App\Models\Meeting, proxima: \App\Models\Meeting}  $meetings
     * @param  array<string, \App\Models\User>  $usuarios
     */
    private function crearCompromisos(Tenant $tenant, array $meetings, array $usuarios, Carbon $base): void
    {
        $alta = Priority::where('name', 'Alta')->first();
        $media = Priority::where('name', 'Media')->first();

        $compromisos = [
            [
                'description' => 'Entregar el censo de líderes del sector',
                'due_date' => $base->copy()->subWeek()->toDateString(),
                'status' => 'pending',
                'priority_id' => $alta?->id,
                'assigned_user_id' => $usuarios['coordinator']?->id,
                'meeting_id' => $meetings['pasada']->id,
            ],
            [
                'description' => 'Coordinar el transporte de la próxima jornada',
                'due_date' => $base->copy()->addWeeks(3)->toDateString(),
                'status' => 'in_progress',
                'priority_id' => $media?->id,
                'assigned_user_id' => $usuarios['operator']?->id,
                'meeting_id' => $meetings['proxima']->id,
            ],
            [
                'description' => 'Enviar el acta de la asamblea',
                'due_date' => $base->copy()->subWeeks(1)->toDateString(),
                'status' => 'completed',
                'priority_id' => $media?->id,
                'assigned_user_id' => $usuarios['admin']?->id,
                'meeting_id' => $meetings['pasada']->id,
            ],
        ];

        foreach ($compromisos as $compromiso) {
            Commitment::firstOrCreate(
                ['tenant_id' => $tenant->id, 'description' => $compromiso['description']],
                $compromiso + ['tenant_id' => $tenant->id, 'created_by' => $usuarios['admin']?->id]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definicion
     */
    private function crearCampana(Tenant $tenant, ?User $autor, array $definicion, Carbon $base): void
    {
        Campaign::firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => $definicion['campana']],
            [
                'created_by' => $autor?->id,
                'message' => 'Le esperamos en nuestro próximo encuentro. Confirme su asistencia.',
                'channel' => 'whatsapp',
                'status' => 'draft',
                'scheduled_at' => $base->copy()->addWeek(),
            ]
        );
    }

    private function crearRecursos(Tenant $tenant): void
    {
        $items = [
            ['name' => 'Silla plástica', 'category' => 'furniture', 'unit' => 'unidad', 'unit_cost' => 15000, 'stock_quantity' => 200],
            ['name' => 'Transporte vehicular', 'category' => 'vehicle', 'unit' => 'viaje', 'unit_cost' => 120000, 'stock_quantity' => null],
            ['name' => 'Refrigerio', 'category' => 'material', 'unit' => 'persona', 'unit_cost' => 8000, 'stock_quantity' => 500],
            ['name' => 'Caja menor', 'category' => 'cash', 'unit' => 'COP', 'unit_cost' => 1, 'stock_quantity' => null],
        ];

        foreach ($items as $item) {
            ResourceItem::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $item['name']],
                $item + ['tenant_id' => $tenant->id, 'is_active' => true]
            );
        }
    }

    /**
     * Contenido mínimo de landing para que las vistas públicas no salgan vacías.
     *
     * @param  array<string, mixed>  $definicion
     */
    private function crearLanding(Tenant $tenant, array $definicion, Carbon $base): void
    {
        LandingBanner::firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => $definicion['provision']['nombre']],
            [
                'subtitle' => 'Trabajando por el territorio',
                'description' => 'Conozca las propuestas y participe en los encuentros.',
                'image' => 'demo/banner.jpg',
                'cta_text' => 'Ver propuestas',
                'cta_link' => '#propuestas',
                'order' => 1,
                'is_active' => true,
            ]
        );

        $propuestas = [
            [
                'categoria' => 'Seguridad',
                'titulo' => 'Territorios seguros',
                'descripcion' => 'Más presencia institucional en los barrios.',
                'puntos_clave' => ['Patrullaje por cuadrantes', 'Cámaras comunitarias'],
                'icono' => 'shield',
                'order' => 1,
            ],
            [
                'categoria' => 'Educación',
                'titulo' => 'Educación con futuro',
                'descripcion' => 'Becas y jornada complementaria.',
                'puntos_clave' => ['Becas técnicas', 'Bilingüismo'],
                'icono' => 'book-open',
                'order' => 2,
            ],
        ];

        foreach ($propuestas as $propuesta) {
            LandingPropuesta::firstOrCreate(
                ['tenant_id' => $tenant->id, 'titulo' => $propuesta['titulo']],
                $propuesta + ['tenant_id' => $tenant->id, 'is_active' => true]
            );
        }

        LandingEvento::firstOrCreate(
            ['tenant_id' => $tenant->id, 'titulo' => 'Encuentro ciudadano'],
            [
                'fecha' => $base->copy()->addWeeks(2)->toDateString(),
                'hora' => '18:00',
                'lugar' => $definicion['lugar'],
                'descripcion' => 'Espacio abierto de participación.',
                'tipo' => 'comunitario',
                'is_active' => true,
            ]
        );
    }
}
