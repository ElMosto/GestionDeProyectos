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
    // Políticas por objeto — fases 2 a 6.
    //
    // Todas deniegan por defecto: hasta que la fase correspondiente implemente
    // la relación (projects.leader_id, project_members, sales.responsible_id,
    // payments.registered_by), devolver `false` es la respuesta correcta. Un
    // `true` aquí sin la comprobación de objeto sería exactamente el fallo que
    // la matriz prohíbe.
    // -------------------------------------------------------------------------

    /**
     * @todo Fase 2: `projects.leader_id = actor` o membresía en `project_members`,
     *       y ADMIN siempre. "Ser miembro da acceso a ver, no a editar ni a ver finanzas".
     *
     * @param array<string, mixed>|null $project
     */
    public function canViewProject(?array $project): bool
    {
        return false;
    }

    /**
     * @todo Fase 2: ADMIN, o LIDER con `projects.leader_id = actor`.
     *       Un colaborador miembro nunca gestiona alcance, fechas ni integrantes.
     *
     * @param array<string, mixed>|null $project
     */
    public function canManageProject(?array $project): bool
    {
        return false;
    }

    /**
     * @todo Fase 2: ADMIN, o LIDER del proyecto. Nunca COLABORADOR sin autorización
     *       administrativa explícita. Si es false, el serializador debe recortar
     *       `base_amount`, cargos, pagos y ticket financiero — también en .xlsx.
     *
     * @param array<string, mixed>|null $project
     */
    public function canViewProjectFinance(?array $project): bool
    {
        return false;
    }

    /**
     * @todo Fase 3: ADMIN, o `sales.responsible_id = actor` para editar BORRADOR,
     *       emitir y cancelar sin pagos. Con pagos registrados, solo ADMIN.
     *
     * @param array<string, mixed>|null $sale
     */
    public function canManageSale(?array $sale): bool
    {
        return false;
    }
}
