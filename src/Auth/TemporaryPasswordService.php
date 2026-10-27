<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\PasswordResetRepository;
use App\Auth\Repositories\UserRepository;
use App\Core\ApiException;
use App\Core\Database;

/**
 * Clave temporal de un solo uso (§6): el administrador restablece el acceso sin
 * conocer la contraseña anterior.
 *
 * El servicio fija `must_change_password = 1`, revoca los tokens móviles del
 * usuario y guarda en `password_resets` solo el hash de la clave, con caducidad.
 * La clave en claro se devuelve una única vez a quien la emite.
 *
 * @todo Fase 2: la ruta administrativa `POST /users/{id}/reset-password` (backend-php)
 *       debe llamar a `issue()` y devolver la clave una sola vez.
 */
final class TemporaryPasswordService
{
    private const DEFAULT_TTL_HOURS = 24;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly Database $db,
    ) {
    }

    public function issue(int $userId, ?int $createdBy, int $ttlHours = self::DEFAULT_TTL_HOURS): string
    {
        $user = $this->users->findForAuth($userId);

        if ($user === null) {
            throw ApiException::notFound('Usuario no encontrado.');
        }

        $temporary = self::generate();
        $hash = password_hash($temporary, PASSWORD_DEFAULT);
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, $ttlHours) * 3600);

        $this->db->transaction(function () use ($userId, $hash, $temporary, $expiresAt, $createdBy, $now): void {
            $this->users->updatePassword($userId, $hash, true, $now);
            $this->resets->create($userId, TokenService::hash($temporary), $expiresAt, $createdBy);
        });

        $this->tokens->revokeAll($userId, 'temporary_password');

        $this->audit->log(
            AuditLogger::PASSWORD_RESET_ISSUED,
            'user',
            $userId,
            null,
            ['expires_at' => $expiresAt],
            $createdBy,
        );

        return $temporary;
    }

    /**
     * ¿La contraseña indicada es una clave temporal vigente de este usuario?
     */
    public function isUsable(int $userId, string $temporary): bool
    {
        $record = $this->resets->findUsable($userId, TokenService::hash($temporary), gmdate('Y-m-d H:i:s'));

        return $record !== null;
    }

    /**
     * Clave legible sin caracteres ambiguos (0/O, 1/l/I).
     */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $length = 20;
        $password = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
