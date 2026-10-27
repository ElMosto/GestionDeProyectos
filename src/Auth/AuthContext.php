<?php

declare(strict_types=1);

namespace App\Auth;

use App\Policies\Role;

/**
 * Identidad autenticada de la petición en curso (§5, §16).
 *
 * La rellena `Authenticator` dentro del middleware y solo se lee después.
 * Nunca guarda `password_hash`, tokens ni secretos: únicamente el perfil público
 * que ya viene de `users` + `roles`.
 */
final class AuthContext
{
    public const METHOD_SESSION = 'session';
    public const METHOD_TOKEN = 'token';

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private ?string $method = null;

    private ?int $tokenId = null;

    /**
     * @param array<string, mixed> $user Fila de users + roles, sin password_hash
     */
    public function setUser(array $user, string $method, ?int $tokenId = null): void
    {
        $this->user = $user;
        $this->method = $method;
        $this->tokenId = $tokenId;

        // Defensa en profundidad: si un repositorio devolviera el hash por error,
        // no se propaga fuera de la capa de persistencia.
        unset($this->user['password_hash']);
    }

    public function clear(): void
    {
        $this->user = null;
        $this->method = null;
        $this->tokenId = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user === null ? null : (int) $this->user['id'];
    }

    public function roleId(): ?int
    {
        return $this->user === null ? null : (int) $this->user['role_id'];
    }

    public function roleCode(): ?string
    {
        return $this->user === null ? null : (string) ($this->user['role_code'] ?? '');
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function authMethod(): ?string
    {
        return $this->method;
    }

    public function tokenId(): ?int
    {
        return $this->tokenId;
    }

    public function mustChangePassword(): bool
    {
        return $this->user !== null && (int) ($this->user['must_change_password'] ?? 0) === 1;
    }

    public function isAdmin(): bool
    {
        return Role::isAdmin($this->roleCode());
    }
}
