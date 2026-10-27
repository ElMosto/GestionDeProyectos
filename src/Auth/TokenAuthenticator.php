<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\TokenRepository;
use App\Auth\Repositories\UserRepository;

/**
 * Valida `Authorization: Bearer <token>` contra `api_tokens` (D-08).
 *
 * Un token vale solo si: existe, no está revocado, no está expirado y su usuario
 * sigue activo. No hay caché ni JWT: revocar en base de datos surte efecto en la
 * siguiente petición.
 */
final class TokenAuthenticator
{
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{user: array<string, mixed>, token_id: int}|null
     */
    public function authenticate(string $plainToken): ?array
    {
        if ($plainToken === '') {
            return null;
        }

        $token = $this->tokens->findByHash(TokenService::hash($plainToken));

        if ($token === null) {
            return null;
        }

        if ($token['revoked_at'] !== null) {
            return null;
        }

        $expiresAt = $token['expires_at'];

        if (is_string($expiresAt) && strtotime($expiresAt . ' UTC') <= time()) {
            return null;
        }

        $user = $this->users->findForAuth((int) $token['user_id']);

        if ($user === null || (int) $user['active'] !== 1) {
            return null;
        }

        // Máximo un UPDATE por minuto y token: no se escribe en cada petición.
        $this->tokens->touch((int) $token['id'], TokenService::now());

        return [
            'user' => $user,
            'token_id' => (int) $token['id'],
        ];
    }
}
