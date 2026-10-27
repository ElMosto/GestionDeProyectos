<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\Repositories\UserRepository;
use App\Core\ApiException;

/**
 * Protección del último administrador activo (§5, matriz de permisos).
 *
 * No se puede desactivar, ni degradar de rol, al último ADMINISTRADOR activo:
 * el sistema quedaría sin nadie capaz de gestionar cuentas.
 *
 * `backend-php` debe invocar estos métodos **dentro de la transacción** que
 * desactiva o cambia el rol, antes del UPDATE.
 */
final class LastAdminGuard
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function assertCanDeactivate(int $targetUserId): void
    {
        $this->assertNotLastAdmin($targetUserId, 'No se puede desactivar al último administrador activo.');
    }

    /**
     * @param int $newRoleId Rol destino; si sigue siendo ADMINISTRADOR no hay degradación
     */
    public function assertCanChangeRole(int $targetUserId, int $newRoleId): void
    {
        $target = $this->users->findForAuth($targetUserId);

        if ($target === null) {
            throw ApiException::notFound('Usuario no encontrado.');
        }

        if ((int) $target['role_id'] === $newRoleId) {
            return;
        }

        if (Role::isAdmin($this->users->roleCodeById($newRoleId))) {
            return;
        }

        $this->assertNotLastAdmin($targetUserId, 'No se puede quitar el rol al último administrador activo.');
    }

    private function assertNotLastAdmin(int $targetUserId, string $message): void
    {
        $target = $this->users->findForAuth($targetUserId);

        if ($target === null) {
            throw ApiException::notFound('Usuario no encontrado.');
        }

        if ($target['role_code'] !== Role::ADMINISTRADOR || (int) $target['active'] !== 1) {
            return;
        }

        if ($this->users->countActiveAdmins() <= 1) {
            throw new ApiException(409, 'LAST_ADMIN_PROTECTED', $message);
        }
    }
}
