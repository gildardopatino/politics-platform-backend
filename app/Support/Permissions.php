<?php

namespace App\Support;

/**
 * Catálogo canónico de permisos (Spec 0002).
 *
 * Fuente única de nombres de permiso del backend. Debe coincidir 1:1 con
 * `src/constants/permissions.ts` del frontend (Constitución, Art. IV).
 *
 * Reglas:
 * - Todo en inglés y en snake_case: `view_*`, `create_*`, `edit_*`, `delete_*`
 *   para CRUD, `manage_*` cuando el módulo no se modela como CRUD.
 * - Guard `api` siempre (es el guard de la API; ver `config/auth.php`).
 * - Añadir un permiso aquí obliga a sembrarlo y a reflejarlo en el frontend:
 *   `tests/Feature/Permissions/PermissionCatalogTest.php` lo verifica.
 */
final class Permissions
{
    /**
     * Guard bajo el que viven todos los permisos de la API.
     */
    public const GUARD = 'api';

    // Users
    public const VIEW_USERS = 'view_users';

    public const CREATE_USERS = 'create_users';

    public const EDIT_USERS = 'edit_users';

    public const DELETE_USERS = 'delete_users';

    // Meetings
    public const VIEW_MEETINGS = 'view_meetings';

    public const CREATE_MEETINGS = 'create_meetings';

    public const EDIT_MEETINGS = 'edit_meetings';

    public const DELETE_MEETINGS = 'delete_meetings';

    // Campaigns / comunicaciones
    public const VIEW_CAMPAIGNS = 'view_campaigns';

    public const CREATE_CAMPAIGNS = 'create_campaigns';

    public const EDIT_CAMPAIGNS = 'edit_campaigns';

    public const DELETE_CAMPAIGNS = 'delete_campaigns';

    // Commitments
    public const VIEW_COMMITMENTS = 'view_commitments';

    public const CREATE_COMMITMENTS = 'create_commitments';

    public const EDIT_COMMITMENTS = 'edit_commitments';

    public const DELETE_COMMITMENTS = 'delete_commitments';

    // Resources / logística
    public const VIEW_RESOURCES = 'view_resources';

    public const CREATE_RESOURCES = 'create_resources';

    public const EDIT_RESOURCES = 'edit_resources';

    public const DELETE_RESOURCES = 'delete_resources';

    // Voters
    public const VIEW_VOTERS = 'view_voters';

    // Call center
    public const VIEW_CALLS = 'view_calls';

    // Contactos geográficos
    public const VIEW_CONTACTS = 'view_contacts';

    // Eventos
    public const VIEW_EVENTS = 'view_events';

    // Enlaces territoriales
    public const MANAGE_LIAISONS = 'manage_liaisons';

    // Landing page
    public const MANAGE_LANDINGPAGE = 'manage_landingpage';

    // Reportes
    public const VIEW_REPORTS = 'view_reports';

    // Auditoría
    public const VIEW_AUDITS = 'view_audits';

    // Pestañas del dashboard
    public const VIEW_COMOVAMOS = 'view_comovamos';

    public const VIEW_DASHBOARDMAP = 'view_dashboardmap';

    /**
     * Catálogo agrupado por módulo.
     *
     * @return array<string, array<int, string>>
     */
    public static function byModule(): array
    {
        return [
            'users' => [self::VIEW_USERS, self::CREATE_USERS, self::EDIT_USERS, self::DELETE_USERS],
            'meetings' => [self::VIEW_MEETINGS, self::CREATE_MEETINGS, self::EDIT_MEETINGS, self::DELETE_MEETINGS],
            'campaigns' => [self::VIEW_CAMPAIGNS, self::CREATE_CAMPAIGNS, self::EDIT_CAMPAIGNS, self::DELETE_CAMPAIGNS],
            'commitments' => [self::VIEW_COMMITMENTS, self::CREATE_COMMITMENTS, self::EDIT_COMMITMENTS, self::DELETE_COMMITMENTS],
            'resources' => [self::VIEW_RESOURCES, self::CREATE_RESOURCES, self::EDIT_RESOURCES, self::DELETE_RESOURCES],
            'voters' => [self::VIEW_VOTERS],
            'calls' => [self::VIEW_CALLS],
            'contacts' => [self::VIEW_CONTACTS],
            'events' => [self::VIEW_EVENTS],
            'liaisons' => [self::MANAGE_LIAISONS],
            'landing' => [self::MANAGE_LANDINGPAGE],
            'reports' => [self::VIEW_REPORTS],
            'audits' => [self::VIEW_AUDITS],
            'dashboard' => [self::VIEW_COMOVAMOS, self::VIEW_DASHBOARDMAP],
        ];
    }

    /**
     * Catálogo plano.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::byModule()));
    }

    /**
     * Permisos de cada rol plantilla global (se clonan por tenant al crearlo).
     *
     * - admin: todo el catálogo.
     * - coordinator: opera la campaña completa salvo borrados sensibles y
     *   auditoría.
     * - operator: trabajo de campo (crea reuniones, gestiona votantes/llamadas).
     * - viewer: solo lectura.
     *
     * @return array<string, array<int, string>>
     */
    public static function byRole(): array
    {
        return [
            'admin' => self::all(),

            'coordinator' => [
                self::VIEW_USERS, self::CREATE_USERS, self::EDIT_USERS,
                self::VIEW_MEETINGS, self::CREATE_MEETINGS, self::EDIT_MEETINGS,
                self::VIEW_CAMPAIGNS, self::CREATE_CAMPAIGNS, self::EDIT_CAMPAIGNS,
                self::VIEW_COMMITMENTS, self::CREATE_COMMITMENTS, self::EDIT_COMMITMENTS,
                self::VIEW_RESOURCES, self::CREATE_RESOURCES, self::EDIT_RESOURCES,
                self::VIEW_VOTERS, self::VIEW_CALLS, self::VIEW_CONTACTS, self::VIEW_EVENTS,
                self::MANAGE_LIAISONS, self::MANAGE_LANDINGPAGE,
                self::VIEW_REPORTS, self::VIEW_COMOVAMOS, self::VIEW_DASHBOARDMAP,
            ],

            'operator' => [
                self::VIEW_USERS,
                self::VIEW_MEETINGS, self::CREATE_MEETINGS,
                self::VIEW_COMMITMENTS,
                self::VIEW_RESOURCES,
                self::VIEW_VOTERS, self::VIEW_CALLS, self::VIEW_CONTACTS, self::VIEW_EVENTS,
            ],

            'viewer' => [
                self::VIEW_USERS,
                self::VIEW_MEETINGS,
                self::VIEW_COMMITMENTS,
                self::VIEW_RESOURCES,
                self::VIEW_VOTERS, self::VIEW_CALLS, self::VIEW_CONTACTS, self::VIEW_EVENTS,
                self::VIEW_REPORTS, self::VIEW_COMOVAMOS,
            ],
        ];
    }
}
