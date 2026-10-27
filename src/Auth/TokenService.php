<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\TokenRepository;
use App\Core\ApiException;
use App\Core\Config;

/**
 * Tokens opacos para la app Android (D-08). Sin JWT.
 *
 * - 32 bytes aleatorios en `base64url` (43 caracteres).
 * - En base de datos solo vive `hash('sha256', $token)`.
 * - `expires_at` = ahora + `security.token_ttl_days`.
 * - Revocables uno a uno y en bloque (cambio de contraseña, cuenta desactivada).
 * - El token en claro se devuelve **una sola vez**, al crearlo.
 */
final class TokenService
{
    private const TTL_DAYS_DEFAULT = 90;

    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly Config $config,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{token: string, id: int, name: string, expires_at: string}
     */
    public function issue(int $userId, string $name): array
    {
        $plain = self::generateToken();
        $expiresAt = $this->expiresAt();

        $id = $this->tokens->create($userId, $name, self::hash($plain), $expiresAt);

        $this->audit->log(AuditLogger::TOKEN_CREATED, 'api_token', $id, null, [
            'name' => $name,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $plain,
            'id' => $id,
            'name' => $name,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(int $userId): array
    {
        return $this->tokens->listActiveForUser($userId);
    }

    public function revoke(int $userId, int $tokenId): void
    {
        if (!$this->tokens->revokeOwned($userId, $tokenId, self::now())) {
            // No se distingue "no existe" de "es de otro usuario": un 404 no revela nada.
            throw ApiException::notFound('Token no encontrado.');
        }

        $this->audit->log(AuditLogger::TOKEN_REVOKED, 'api_token', $tokenId);
    }

    /**
     * Revocación masiva: cambio de contraseña o desactivación de la cuenta.
     */
    public function revokeAll(int $userId, string $reason): int
    {
        $revoked = $this->tokens->revokeAllForUser($userId, self::now());

        if ($revoked > 0) {
            $this->audit->log(
                AuditLogger::TOKENS_REVOKED_ALL,
                'user',
                $userId,
                null,
                ['revoked' => $revoked, 'reason' => $reason],
                $userId,
            );
        }

        return $revoked;
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function expiresAt(): string
    {
        $days = (int) $this->config->get('security.token_ttl_days', self::TTL_DAYS_DEFAULT);

        if ($days <= 0) {
            $days = self::TTL_DAYS_DEFAULT;
        }

        return gmdate('Y-m-d H:i:s', time() + $days * 86400);
    }
}
