<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuditLogger;
use App\Auth\TemporaryPasswordService;
use App\Auth\TokenService;
use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\LastAdminGuard;
use App\Repositories\RoleRepository;
use App\Repositories\UserAdminRepository;
use PDOException;

/**
 * Reglas administrativas de cuentas (§5, §6, §15).
 *
 * Alta, edición de perfil por administrador, cambio de rol con protección del
 * último administrador, baja lógica/reactivación y restablecimiento con clave
 * temporal de un solo uso. El servicio nunca manipula contraseñas en claro más
 * allá del hash y no las incluye en auditoría.
 */
final class UserService
{
    public function __construct(
        private readonly UserAdminRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuditLogger $audit,
        private readonly TokenService $tokens,
        private readonly TemporaryPasswordService $temporaryPasswords,
        private readonly LastAdminGuard $lastAdmin,
        private readonly Database $db,
        private readonly UserPresenter $presenter,
    ) {
    }

    /**
     * @param array{q: ?string, role: ?string, active: ?int, limit: int, offset: int, sort: string, direction: string} $query
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(array $query): array
    {
        $result = $this->users->paginate(
            $query['q'],
            $query['role'],
            $query['active'],
            $query['limit'],
            $query['offset'],
            $query['sort'],
            $query['direction'],
        );

        return [
            'data' => array_map(fn (array $row): array => $this->presenter->profile($row), $result['rows']),
            'meta' => [
                'total' => $result['total'],
                'limit' => $query['limit'],
                'offset' => $query['offset'],
            ],
        ];
    }

    /**
     * Directorio mínimo de usuarios activos (§5).
     *
     * @return list<array<string, mixed>>
     */
    public function directory(): array
    {
        $entries = [];

        foreach ($this->users->directory() as $row) {
            $entries[] = [
                'id' => (string) $row['id'],
                'name' => $row['name'],
                'job_title' => $row['job_title'],
                'role' => ['code' => $row['role_code']],
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        return $this->presenter->profile($this->requireUser($id));
    }

    /**
     * @param array{name: string, email: string, role_id: ?int, role_code: ?string, password: string, phone: ?string, job_title: ?string, timezone: string} $input
     * @return array<string, mixed>
     */
    public function create(array $input, int $actorId): array
    {
        $role = $this->resolveRole($input['role_id'], $input['role_code']);

        if ($this->users->findByEmail($input['email']) !== null) {
            throw ApiException::conflict('EMAIL_TAKEN', 'Ya existe un usuario con ese correo.');
        }

        try {
            $id = $this->users->create(
                (int) $role['id'],
                $input['name'],
                $input['email'],
                password_hash($input['password'], PASSWORD_DEFAULT),
                $input['phone'],
                $input['job_title'],
                $input['timezone'],
                $actorId,
            );
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ApiException::conflict('EMAIL_TAKEN', 'Ya existe un usuario con ese correo.');
            }

            throw $e;
        }

        // La contraseña y su hash nunca forman parte de `after`.
        $this->audit->log('USER_CREATE', 'user', $id, null, [
            'name' => $input['name'],
            'email' => $input['email'],
            'role_id' => (int) $role['id'],
            'role_code' => (string) $role['code'],
            'job_title' => $input['job_title'],
            'timezone' => $input['timezone'],
            'active' => true,
            'must_change_password' => true,
        ], $actorId);

        return $this->presenter->profile($this->requireUser($id));
    }

    /**
     * @param array{fields: array<string, mixed>, role_id: ?int, role_code: ?string, expected_version: int} $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, array $actor): array
    {
        $target = $this->requireUser($id);
        $actorId = (int) $actor['id'];
        $fields = $input['fields'];
        $roleChange = false;
        $newRole = null;

        if ($input['role_id'] !== null || $input['role_code'] !== null) {
            $newRole = $this->resolveRole($input['role_id'], $input['role_code']);

            if ((int) $target['role_id'] !== (int) $newRole['id']) {
                if ($actorId === $id) {
                    throw ApiException::forbidden('No puedes cambiar tu propio rol.');
                }

                $fields['role_id'] = (int) $newRole['id'];
                $roleChange = true;
            }
        }

        $expectedVersion = $input['expected_version'];

        if ($fields === []) {
            if ((int) $target['version'] !== $expectedVersion) {
                throw self::versionConflict();
            }

            return $this->presenter->profile($target);
        }

        $otherFields = array_diff_key($fields, ['role_id' => true]);
        $beforeOther = array_intersect_key($target, $otherFields);

        $updated = $this->db->transaction(function () use ($id, $fields, $expectedVersion, $roleChange): ?array {
            if ($roleChange) {
                // §5: no se puede degradar al último ADMINISTRADOR activo.
                $this->users->lockActiveAdmins();
                $this->lastAdmin->assertCanChangeRole($id, (int) $fields['role_id']);
            }

            return $this->users->update($id, $fields, $expectedVersion);
        });

        if ($updated === null) {
            throw self::versionConflict();
        }

        if ($roleChange) {
            $this->audit->log('ROLE_CHANGE', 'user', $id, [
                'role_id' => (int) $target['role_id'],
                'role_code' => (string) $target['role_code'],
            ], [
                'role_id' => (int) $newRole['id'],
                'role_code' => (string) $newRole['code'],
            ], $actorId);
        }

        if ($otherFields !== []) {
            $this->audit->log('USER_UPDATE', 'user', $id, $beforeOther, $otherFields, $actorId);
        }

        return $this->presenter->profile($updated);
    }

    /**
     * Baja lógica idempotente. Revoca tokens; las sesiones abiertas dejan de
     * valer en la siguiente petición porque el middleware recarga `active` (§5).
     *
     * @return array<string, mixed>
     */
    public function deactivate(int $id, int $actorId): array
    {
        $target = $this->requireUser($id);

        if ((int) $target['active'] === 1) {
            $this->db->transaction(function () use ($id): void {
                $this->users->lockActiveAdmins();
                $this->lastAdmin->assertCanDeactivate($id);
                $this->users->setActive($id, false);
            });

            $this->audit->log('USER_DEACTIVATE', 'user', $id, ['active' => true], ['active' => false], $actorId);
        }

        // Idempotente: repetir la baja no falla y no duplica la auditoría.
        $this->tokens->revokeAll($id, 'account_deactivated');

        return $this->presenter->profile($this->requireUser($id));
    }

    /**
     * Reactivación idempotente.
     *
     * @return array<string, mixed>
     */
    public function reactivate(int $id, int $actorId): array
    {
        $target = $this->requireUser($id);

        if ((int) $target['active'] === 0) {
            $this->users->setActive($id, true);
            $this->audit->log('USER_REACTIVATE', 'user', $id, ['active' => false], ['active' => true], $actorId);
        }

        return $this->presenter->profile($this->requireUser($id));
    }

    /**
     * Clave temporal de un solo uso (§6). La clave en claro solo viaja en esta
     * respuesta: no se guarda ni se audita.
     *
     * @return array{user: array<string, mixed>, temporary_password: string}
     */
    public function resetPassword(int $id, int $actorId): array
    {
        $this->requireUser($id);

        $temporary = $this->temporaryPasswords->issue($id, $actorId);

        $this->audit->log('PASSWORD_RESET_BY_ADMIN', 'user', $id, null, [
            'must_change_password' => true,
            'tokens_revoked' => true,
        ], $actorId);

        return [
            'user' => $this->presenter->profile($this->requireUser($id)),
            'temporary_password' => $temporary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUser(int $id): array
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            throw ApiException::notFound('Usuario no encontrado.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRole(?int $roleId, ?string $roleCode): array
    {
        $role = null;

        if ($roleId !== null) {
            $role = $this->roles->findById($roleId);

            if ($role === null) {
                throw ApiException::validation(['role_id' => 'El rol indicado no existe.']);
            }
        }

        if ($roleCode !== null) {
            $byCode = $this->roles->findByCode($roleCode);

            if ($byCode === null) {
                throw ApiException::validation(['role_id' => 'El rol indicado no existe.']);
            }

            if ($role !== null && (int) $role['id'] !== (int) $byCode['id']) {
                throw ApiException::validation(['role_id' => 'El rol indicado no coincide con su código.']);
            }

            $role ??= $byCode;
        }

        if ($role === null) {
            throw ApiException::validation(['role_id' => 'Indica el rol del usuario.']);
        }

        return $role;
    }

    private static function versionConflict(): ApiException
    {
        return new ApiException(
            409,
            'VERSION_CONFLICT',
            'El usuario cambió mientras lo editabas. Vuelve a cargarlo e inténtalo de nuevo.',
            ['version' => 'La versión enviada ya no es la actual.'],
        );
    }
}
