<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\AuthContext;
use App\Core\ApiException;

/**
 * Punto único de autorización del backend. Dos preguntas separadas, siempre:
 *
 *   1. ¿el rol puede ejecutar la acción?  -> `requireCapability()`
 *   2. ¿puede sobre *este* objeto?        -> `canViewProject()`, `canManageSale()`, ...
 *
 * Si cualquiera falla -> 403 FORBIDDEN, nunca "existe pero no puedes" (§16).
 * Un recurso sin política explícita se deniega: no hay método "pasa".
 */
final class Authorizer
{
    public function __construct(private readonly AuthContext $auth)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->auth->user();
    }

    public function id(): ?int
    {
        return $this->auth->id();
    }

    public function role(): ?string
    {
        return $this->auth->roleCode();
    }

    public function isAdmin(): bool
    {
        return $this->auth->isAdmin();
    }

    /**
     * Mapa informativo para la UI. El backend reautoriza en cada petición:
     * esto no es un permiso.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return Capabilities::forRole($this->auth->roleCode());
    }

    public function can(string $capability): bool
    {
        if (!$this->auth->isAuthenticated()) {
            return false;
        }

        return Capabilities::roleHas($this->auth->roleCode(), $capability);
    }

    /**
     * Exige la capacidad global. Lanza 401 si no hay identidad y 403 si el rol no la tiene.
     */
    public function requireCapability(string $capability): void
    {
        $this->requireUser();

        if (!$this->can($capability)) {
            throw ApiException::forbidden();
        }
    }

    /**
     * Operación de alto impacto (§5): solo ADMIN, sin excepciones implícitas.
     */
    public function requireAdmin(): void
    {
        $this->requireUser();

        if (!$this->isAdmin()) {
            throw ApiException::forbidden();
        }
    }

    /**
     * Identidad obligatoria; devuelve el usuario ya cargado en la petición.
     *
     * @return array<string, mixed>
     */
    public function requireUser(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }

    // -------------------------------------------------------------------------
    // Clientes (§7) — fase 1.
    //
    // El directorio mínimo (id, tipo, nombre, contacto, teléfono, activo) lo ve
    // cualquier usuario activo. Los datos ampliados y la edición dependen de la
    // relación con el objeto (`clients.created_by`), no solo del rol.
    // -------------------------------------------------------------------------

    /**
     * Ver el registro: capacidad global o ser el creador. Conocer un ID ajeno
     * no otorga acceso; quien no puede verlo recibe 404, no 403.
     *
     * @param array<string, mixed>|null $client
     */
    public function canViewClient(?array $client): bool
    {
        if (!$this->auth->isAuthenticated() || $client === null) {
            return false;
        }

        return $this->can(Capabilities::CLIENTS_VIEW) || $this->isClientCreator($client);
    }

    /**
     * Datos sensibles del cliente (`email`, `address`, `notes`,
     * `representative_name`, `created_by`, fechas y `version`): ADMIN o creador.
     * El recorte se aplica también en listados y exportaciones.
     *
     * @param array<string, mixed>|null $client
     */
    public function canViewSensitiveClient(?array $client): bool
    {
        if (!$this->auth->isAuthenticated() || $client === null) {
            return false;
        }

        return $this->can(Capabilities::CLIENTS_VIEW_SENSITIVE) || $this->isClientCreator($client);
    }

    /**
     * Editar: ADMIN sobre cualquier cliente; el creador solo los campos de
     * contacto de los suyos. La lista de campos exclusivos de ADMIN se valida
     * en `ClientService`, porque depende del payload, no del objeto.
     *
     * @param array<string, mixed>|null $client
     */
    public function canEditClient(?array $client): bool
    {
        if (!$this->auth->isAuthenticated() || $client === null) {
            return false;
        }

        if ($this->can(Capabilities::CLIENTS_EDIT_ANY)) {
            return true;
        }

        return $this->can(Capabilities::CLIENTS_EDIT_OWN) && $this->isClientCreator($client);
    }

    /**
     * Baja lógica: operación de alto impacto (§5), capacidad global y ADMIN.
     * Que un colaborador cree el registro no le da derecho a desactivarlo.
     *
     * @param array<string, mixed>|null $client
     */
    public function canDeactivateClient(?array $client): bool
    {
        return $client !== null
            && $this->can(Capabilities::CLIENTS_DEACTIVATE)
            && $this->isAdmin();
    }

    /**
     * @param array<string, mixed> $client
     */
    public function isClientCreator(array $client): bool
    {
        $ownerId = $client['created_by'] ?? null;

        return $ownerId !== null && (int) $ownerId === $this->id();
    }

    // -------------------------------------------------------------------------
    // Proyectos y tareas (§5, §8, §16) — fase 2.
    // Filas confiables del repositorio, nunca del payload. `is_member` es un
    // booleano estricto calculado para el actor autenticado, no un permiso global.
    // -------------------------------------------------------------------------

    /**
     * ADMIN ve cualquiera; LIDER solo el que lidera; COLABORADOR solo como miembro.
     *
     * @param array<string, mixed>|null $project
     */
    public function canViewProject(?array $project): bool
    {
        if (!$this->hasActiveActor() || $project === null) {
            return false;
        }

        return $this->can(Capabilities::PROJECTS_VIEW_ANY)
            || ($this->can(Capabilities::PROJECTS_VIEW_OWN) && $this->actorMatches($project['leader_id'] ?? null))
            || ($this->can(Capabilities::PROJECTS_VIEW_MEMBER) && ($project['is_member'] ?? null) === true);
    }

    /**
     * ADMIN, o LIDER con `projects.leader_id = actor`.
     *       Un colaborador miembro nunca gestiona alcance, fechas ni integrantes.
     *
     * @param array<string, mixed>|null $project
     */
    public function canManageProject(?array $project): bool
    {
        if (!$this->hasActiveActor() || $project === null) {
            return false;
        }

        return $this->can(Capabilities::PROJECTS_MANAGE_ANY)
            || ($this->can(Capabilities::PROJECTS_MANAGE_OWN) && $this->actorMatches($project['leader_id'] ?? null));
    }

    /**
     * ADMIN, o LIDER del proyecto. Nunca COLABORADOR sin autorización
     *       administrativa explícita. Si es false, el serializador debe recortar
     *       `base_amount`, cargos, pagos y ticket financiero — también en .xlsx.
     *
     * @param array<string, mixed>|null $project
     */
    public function canViewProjectFinance(?array $project): bool
    {
        if (!$this->hasActiveActor() || $project === null) {
            return false;
        }

        return $this->can(Capabilities::PROJECTS_VIEW_FINANCE_ANY)
            || ($this->can(Capabilities::PROJECTS_VIEW_FINANCE_OWN) && $this->actorMatches($project['leader_id'] ?? null));
    }

    /**
     * Ver también permite comentar (§8, matriz). Para tareas de proyecto, el
     * repositorio debe resolver $project desde tasks.stage_id -> project_stages;
     * no se acepta un proyecto elegido por el cliente. Sin padre se deniega.
     *
     * @param array<string, mixed>|null $task
     * @param array<string, mixed>|null $project
     */
    public function canViewTask(?array $task, ?array $project = null): bool
    {
        if (!$this->hasActiveActor() || $task === null || !array_key_exists('stage_id', $task)) {
            return false;
        }

        if ($task['stage_id'] !== null) {
            return $this->canViewProject($project);
        }

        return $this->isAdmin()
            || ($this->can(Capabilities::TASKS_UPDATE_ASSIGNED) && $this->actorMatches($task['assignee_id'] ?? null));
    }

    /**
     * Alcance, fechas y asignación: solo ADMIN en independientes; ADMIN o líder
     * del proyecto en las demás (§8). No concede cambios de avance por payload.
     *
     * @param array<string, mixed>|null $task
     * @param array<string, mixed>|null $project
     */
    public function canManageTask(?array $task, ?array $project = null): bool
    {
        if (!$this->canViewTask($task, $project)) {
            return false;
        }

        return $task['stage_id'] === null ? $this->isAdmin() : $this->canManageProject($project);
    }

    /**
     * Avance: ADMIN/líder del proyecto o responsable COLABORADOR miembro.
     * En independientes, ADMIN o responsable de cualquier rol admitido (§8).
     *
     * @param array<string, mixed>|null $task
     * @param array<string, mixed>|null $project
     */
    public function canUpdateTaskProgress(?array $task, ?array $project = null): bool
    {
        if (!$this->canViewTask($task, $project)) {
            return false;
        }

        if ($this->canManageTask($task, $project)) {
            return true;
        }

        return $this->can(Capabilities::TASKS_UPDATE_ASSIGNED)
            && $this->actorMatches($task['assignee_id'] ?? null)
            && ($task['stage_id'] === null || $this->role() === Role::COLABORADOR);
    }

    /**
     * §5, §12: fila confiable del repositorio; ADMIN o responsable activo.
     * El histórico sigue visible. La ocupación mínima no usa esta política.
     * @param array<string, mixed>|null $reservation
     */
    public function canViewReservation(?array $reservation): bool
    {
        return $this->hasActiveActor()
            && $reservation !== null
            && ($this->isAdmin()
                || ($this->can(Capabilities::RESERVATIONS_VIEW_OWN)
                    && $this->actorMatches($reservation['responsible_id'] ?? null)));
    }

    /**
     * §12: reprogramar y transiciones; estado, versión y bloqueos van en servicio.
     * No concede cancelación ni permite cambiar responsable.
     * @param array<string, mixed>|null $reservation
     */
    public function canManageReservation(?array $reservation): bool
    {
        return $this->canViewReservation($reservation)
            && ($this->isAdmin()
                || ($this->can(Capabilities::RESERVATIONS_RESCHEDULE_OWN)
                    && $this->actorMatches($reservation['responsible_id'] ?? null)));
    }

    /**
     * D-23 / §10: cancelar reserva cancela venta; solo ADMIN, aun sin pagos.
     * El servicio exige motivo, neto cero y cancelación coordinada sin reembolso automático.
     * @param array<string, mixed>|null $reservation
     */
    public function canCancelReservation(?array $reservation): bool
    {
        return $this->canViewReservation($reservation)
            && $this->isAdmin()
            && $this->can(Capabilities::RESERVATIONS_CANCEL_ANY);
    }

    /** §5, §12: configuración, tarifas, horarios y bloqueos; solo ADMIN activo. */
    public function canManageSpaces(): bool
    {
        return $this->hasActiveActor()
            && $this->isAdmin()
            && $this->can(Capabilities::SPACES_MANAGE);
    }

    private function hasActiveActor(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user['active'] ?? null, [true, 1, '1'], true)
            && self::normalizedId($user['id'] ?? null) !== null;
    }

    private function actorMatches(mixed $id): bool
    {
        $normalized = self::normalizedId($id);

        return $normalized !== null && $normalized === self::normalizedId($this->user()['id'] ?? null);
    }

    /** Comparación estricta sin truncar BIGINT ni aceptar booleanos o "7abc". */
    private static function normalizedId(mixed $id): ?string
    {
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        $value = (string) $id;

        return preg_match('/\A[1-9][0-9]*\z/', $value) === 1 ? $value : null;
    }

    /**
     * §5, §10: fila confiable del repositorio, nunca del payload.
     * El histórico sigue visible en cualquier estado, incluidas reservas.
     * @param array<string, mixed>|null $sale
     */
    public function canViewSale(?array $sale): bool
    {
        return $this->hasActiveActor()
            && $sale !== null
            && ($this->isAdmin()
                || ($this->can(Capabilities::SALES_VIEW_OWN) && $this->actorMatches($sale['responsible_id'] ?? null)));
    }

    /** @param array<string, mixed>|null $sale */
    public function canViewSaleFinance(?array $sale): bool
    {
        return $this->canViewSale($sale);
    }

    /**
     * §10: las reservas requieren el servicio coordinado de fase 4.
     * @param array<string, mixed>|null $sale
     */
    public function canManageSale(?array $sale): bool
    {
        return $this->canViewSale($sale)
            && ($sale['status'] ?? null) === 'BORRADOR'
            && ($sale['type'] ?? null) === 'ORDINARIA'
            && ($this->can(Capabilities::SALES_EDIT_DRAFT_ANY)
                || ($this->can(Capabilities::SALES_EDIT_DRAFT_OWN) && $this->actorMatches($sale['responsible_id'] ?? null)));
    }

    /** @param array<string, mixed>|null $sale */
    public function canIssueSale(?array $sale): bool
    {
        return $this->canManageSale($sale) && $this->can(Capabilities::SALES_ISSUE_OWN);
    }

    /**
     * D-18 / §10: solo ADMIN, también sin pagos. El servicio exige motivo,
     * neto cero y coordinación de reservas; la policy no reemplaza esas reglas.
     * @param array<string, mixed>|null $sale
     */
    public function canCancelSale(?array $sale): bool
    {
        return $this->canViewSale($sale)
            && $this->isAdmin()
            && $this->can(Capabilities::SALES_CANCEL_WITHOUT_PAYMENTS_ANY);
    }

    /**
     * §11: reservas se cobran únicamente mediante su venta emitida.
     * @param array<string, mixed>|null $sale
     */
    public function canRegisterSalePayment(?array $sale): bool
    {
        return $this->canViewSale($sale)
            && $this->can(Capabilities::PAYMENTS_REGISTER_OWN)
            && ($sale['status'] ?? null) === 'EMITIDA'
            && in_array($sale['type'] ?? null, ['ORDINARIA', 'RESERVA'], true);
    }

    /**
     * §11: finalizar no liquida saldo; cancelar impide nuevos cobros.
     * El backend resuelve el documento; registered_by no concede acceso.
     * @param array<string, mixed>|null $project
     */
    public function canRegisterProjectPayment(?array $project): bool
    {
        return $this->canViewProjectFinance($project)
            && $this->can(Capabilities::PAYMENTS_REGISTER_PROJECT)
            && in_array($project['billable'] ?? null, [true, 1, '1'], true)
            && isset($project['status'])
            && $project['status'] !== 'CANCELADO';
    }
}
