<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Matriz rol → capacidades (`.opencode/skills/permisos-rbac/references/matriz-permisos.md`).
 *
 * Una capacidad responde solo la primera pregunta: ¿el rol puede ejecutar la acción?
 * La segunda —¿puede sobre *este* objeto?— la resuelve `Authorizer` con la relación
 * (liderazgo, membresía, responsabilidad, propiedad). Sin esa segunda comprobación,
 * una capacidad marcada aquí como `true` NO autoriza nada por sí sola.
 *
 * `capabilities` que viaja al cliente es informativa: el backend reautoriza siempre.
 */
final class Capabilities
{
    // Cuentas y administración
    public const USERS_MANAGE = 'users.manage';
    public const USERS_DIRECTORY = 'users.directory';
    public const AUDIT_VIEW = 'audit.view';
    public const SETTINGS_MANAGE = 'settings.manage';

    // Mi usuario
    public const ME_EDIT = 'me.edit';
    public const ME_AVATAR = 'me.avatar';
    public const ME_TOKENS = 'me.tokens';

    // Clientes
    public const CLIENTS_CREATE = 'clients.create';
    public const CLIENTS_VIEW = 'clients.view';
    public const CLIENTS_VIEW_SENSITIVE = 'clients.viewSensitive';
    public const CLIENTS_EDIT_ANY = 'clients.editAny';
    public const CLIENTS_EDIT_OWN = 'clients.editOwn';
    public const CLIENTS_DEACTIVATE = 'clients.deactivate';
    public const CLIENTS_CONVERT = 'clients.convert';

    // Proyectos, etapas y tareas
    public const PROJECTS_CREATE = 'projects.create';
    public const PROJECTS_VIEW_ANY = 'projects.viewAny';
    public const PROJECTS_VIEW_OWN = 'projects.viewOwn';
    public const PROJECTS_VIEW_MEMBER = 'projects.viewMember';
    public const PROJECTS_MANAGE_ANY = 'projects.manageAny';
    public const PROJECTS_MANAGE_OWN = 'projects.manageOwn';
    public const PROJECTS_VIEW_FINANCE_ANY = 'projects.viewFinanceAny';
    public const PROJECTS_VIEW_FINANCE_OWN = 'projects.viewFinanceOwn';
    public const PROJECTS_EXPORT = 'projects.export';
    public const PROJECTS_TICKET = 'projects.ticket';
    public const TASKS_CREATE_STANDALONE = 'tasks.createStandalone';
    public const TASKS_UPDATE_ASSIGNED = 'tasks.updateAssigned';
    public const EXPENSES_MANAGE = 'expenses.manage';
    public const EXPENSES_VIEW = 'expenses.view';

    // Ventas, catálogo y cobros
    public const CATALOG_MANAGE = 'catalog.manage';
    public const CATALOG_VIEW = 'catalog.view';
    public const SALES_CREATE = 'sales.create';
    public const SALES_VIEW_OWN = 'sales.viewOwn';
    public const SALES_EDIT_DRAFT_ANY = 'sales.editDraftAny';
    public const SALES_EDIT_DRAFT_OWN = 'sales.editDraftOwn';
    public const SALES_ISSUE_OWN = 'sales.issueOwn';
    public const SALES_CANCEL_WITHOUT_PAYMENTS_ANY = 'sales.cancelWithoutPaymentsAny';
    public const SALES_CANCEL_WITHOUT_PAYMENTS_OWN = 'sales.cancelWithoutPaymentsOwn';
    public const SALES_CANCEL_WITH_PAYMENTS = 'sales.cancelWithPayments';
    public const SALES_TICKET_OWN = 'sales.ticketOwn';
    public const PAYMENTS_REGISTER_OWN = 'payments.registerOwn';
    public const PAYMENTS_REGISTER_PROJECT = 'payments.registerProject';
    public const PAYMENTS_VOID = 'payments.void';
    public const PAYMENTS_REFUND = 'payments.refund';
    public const FINANCE_DASHBOARD = 'finance.dashboard';

    // Espacios, reservas y agenda
    public const SPACES_MANAGE = 'spaces.manage';
    public const SPACES_VIEW_OCCUPANCY = 'spaces.viewOccupancy';
    public const RESERVATIONS_CREATE = 'reservations.create';
    public const RESERVATIONS_VIEW_OWN = 'reservations.viewOwn';
    public const RESERVATIONS_RESCHEDULE_OWN = 'reservations.rescheduleOwn';
    public const RESERVATIONS_CANCEL_ANY = 'reservations.cancelAny';
    public const RESERVATIONS_CANCEL_OWN = 'reservations.cancelOwn';
    public const RESERVATIONS_RETROACTIVE = 'reservations.retroactive';
    public const AGENDA_VIEW_OWN = 'agenda.viewOwn';

    // Reuniones y notificaciones
    public const MEETINGS_CREATE = 'meetings.create';
    public const MEETINGS_RESPOND = 'meetings.respond';
    public const NOTIFICATIONS_VIEW = 'notifications.viewOwn';

    /**
     * Capacidades por rol. Lo que no aparece aquí está denegado (§5: deny by default).
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        Role::ADMINISTRADOR => [
            self::USERS_MANAGE,
            self::USERS_DIRECTORY,
            self::AUDIT_VIEW,
            self::SETTINGS_MANAGE,
            self::ME_EDIT,
            self::ME_AVATAR,
            self::ME_TOKENS,
            self::CLIENTS_CREATE,
            self::CLIENTS_VIEW,
            self::CLIENTS_VIEW_SENSITIVE,
            self::CLIENTS_EDIT_ANY,
            self::CLIENTS_EDIT_OWN,
            self::CLIENTS_DEACTIVATE,
            self::CLIENTS_CONVERT,
            self::PROJECTS_CREATE,
            self::PROJECTS_VIEW_ANY,
            self::PROJECTS_VIEW_OWN,
            self::PROJECTS_VIEW_MEMBER,
            self::PROJECTS_MANAGE_ANY,
            self::PROJECTS_MANAGE_OWN,
            self::PROJECTS_VIEW_FINANCE_ANY,
            self::PROJECTS_VIEW_FINANCE_OWN,
            self::PROJECTS_EXPORT,
            self::PROJECTS_TICKET,
            self::TASKS_CREATE_STANDALONE,
            self::TASKS_UPDATE_ASSIGNED,
            self::EXPENSES_MANAGE,
            self::EXPENSES_VIEW,
            self::CATALOG_MANAGE,
            self::CATALOG_VIEW,
            self::SALES_CREATE,
            self::SALES_VIEW_OWN,
            self::SALES_EDIT_DRAFT_ANY,
            self::SALES_EDIT_DRAFT_OWN,
            self::SALES_ISSUE_OWN,
            self::SALES_CANCEL_WITHOUT_PAYMENTS_ANY,
            self::SALES_CANCEL_WITHOUT_PAYMENTS_OWN,
            self::SALES_CANCEL_WITH_PAYMENTS,
            self::SALES_TICKET_OWN,
            self::PAYMENTS_REGISTER_OWN,
            self::PAYMENTS_REGISTER_PROJECT,
            self::PAYMENTS_VOID,
            self::PAYMENTS_REFUND,
            self::FINANCE_DASHBOARD,
            self::SPACES_MANAGE,
            self::SPACES_VIEW_OCCUPANCY,
            self::RESERVATIONS_CREATE,
            self::RESERVATIONS_VIEW_OWN,
            self::RESERVATIONS_RESCHEDULE_OWN,
            self::RESERVATIONS_CANCEL_ANY,
            self::RESERVATIONS_CANCEL_OWN,
            self::RESERVATIONS_RETROACTIVE,
            self::AGENDA_VIEW_OWN,
            self::MEETINGS_CREATE,
            self::MEETINGS_RESPOND,
            self::NOTIFICATIONS_VIEW,
        ],
        Role::LIDER_PROYECTO => [
            self::USERS_DIRECTORY,
            self::ME_EDIT,
            self::ME_AVATAR,
            self::ME_TOKENS,
            self::CLIENTS_CREATE,
            self::CLIENTS_VIEW,
            self::CLIENTS_EDIT_OWN,
            self::PROJECTS_CREATE,
            self::PROJECTS_VIEW_OWN,
            self::PROJECTS_MANAGE_OWN,
            self::PROJECTS_VIEW_FINANCE_OWN,
            self::PROJECTS_EXPORT,
            self::PROJECTS_TICKET,
            self::TASKS_UPDATE_ASSIGNED,
            self::CATALOG_VIEW,
            self::SALES_CREATE,
            self::SALES_VIEW_OWN,
            self::SALES_EDIT_DRAFT_OWN,
            self::SALES_ISSUE_OWN,
            self::SALES_TICKET_OWN,
            self::PAYMENTS_REGISTER_OWN,
            self::PAYMENTS_REGISTER_PROJECT,
            self::SPACES_VIEW_OCCUPANCY,
            self::RESERVATIONS_CREATE,
            self::RESERVATIONS_VIEW_OWN,
            self::RESERVATIONS_RESCHEDULE_OWN,
            self::RESERVATIONS_CANCEL_OWN,
            self::AGENDA_VIEW_OWN,
            self::MEETINGS_CREATE,
            self::MEETINGS_RESPOND,
            self::NOTIFICATIONS_VIEW,
        ],
        Role::COLABORADOR => [
            self::USERS_DIRECTORY,
            self::ME_EDIT,
            self::ME_AVATAR,
            self::ME_TOKENS,
            self::CLIENTS_CREATE,
            self::CLIENTS_VIEW,
            self::CLIENTS_EDIT_OWN,
            self::PROJECTS_VIEW_MEMBER,
            self::PROJECTS_EXPORT,
            self::TASKS_UPDATE_ASSIGNED,
            self::CATALOG_VIEW,
            self::SALES_CREATE,
            self::SALES_VIEW_OWN,
            self::SALES_EDIT_DRAFT_OWN,
            self::SALES_ISSUE_OWN,
            self::SALES_TICKET_OWN,
            self::PAYMENTS_REGISTER_OWN,
            self::SPACES_VIEW_OCCUPANCY,
            self::RESERVATIONS_CREATE,
            self::RESERVATIONS_VIEW_OWN,
            self::RESERVATIONS_RESCHEDULE_OWN,
            self::AGENDA_VIEW_OWN,
            self::MEETINGS_CREATE,
            self::MEETINGS_RESPOND,
            self::NOTIFICATIONS_VIEW,
        ],
    ];

    /**
     * Todas las capacidades conocidas, en orden estable.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::MATRIX as $capabilities) {
            foreach ($capabilities as $capability) {
                $all[$capability] = true;
            }
        }

        $keys = array_keys($all);
        sort($keys);

        return $keys;
    }

    /**
     * Mapa plano capacidad → booleano para la UI. Nunca concede permisos por sí solo.
     *
     * @return array<string, bool>
     */
    public static function forRole(?string $roleCode): array
    {
        $granted = array_fill_keys(self::all(), false);

        foreach (self::MATRIX[$roleCode ?? ''] ?? [] as $capability) {
            $granted[$capability] = true;
        }

        return $granted;
    }

    public static function roleHas(?string $roleCode, string $capability): bool
    {
        return in_array($capability, self::MATRIX[$roleCode ?? ''] ?? [], true);
    }
}
