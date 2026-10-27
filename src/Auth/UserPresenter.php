<?php

declare(strict_types=1);

namespace App\Auth;

use App\Policies\Capabilities;
use stdClass;

/**
 * Serializador de usuario. Lista blanca de campos: `password_hash`, tokens,
 * `password_resets` y cualquier columna interna no salen nunca de aquí (§16).
 */
final class UserPresenter
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function profile(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'phone' => self::nullableString($user['phone'] ?? null),
            'job_title' => self::nullableString($user['job_title'] ?? null),
            'timezone' => (string) ($user['timezone'] ?? 'UTC'),
            'role' => [
                'id' => (string) $user['role_id'],
                'code' => (string) $user['role_code'],
                'name' => (string) $user['role_name'],
            ],
            'avatar_url' => self::hasAvatar($user) ? '/api/v1/me/avatar' : null,
            'notification_prefs' => self::notificationPrefs($user['notification_prefs'] ?? null),
            'active' => (int) $user['active'] === 1,
            'must_change_password' => (int) $user['must_change_password'] === 1,
            'last_login_at' => self::iso($user['last_login_at'] ?? null),
            'version' => (string) ($user['version'] ?? 1),
        ];
    }

    /**
     * Respuesta de `POST /auth/login`, `GET /auth/me`, `GET /me`, `PATCH /me`.
     * `capabilities` es informativa para la UI: el backend reautoriza siempre.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function sessionPayload(array $user): array
    {
        return [
            'user' => $this->profile($user),
            'capabilities' => Capabilities::forRole((string) ($user['role_code'] ?? '')),
            'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
        ];
    }

    public static function hasAvatar(array $user): bool
    {
        $path = $user['avatar_path'] ?? null;

        return is_string($path) && $path !== '';
    }

    /**
     * Fecha de BD (UTC) a ISO 8601.
     */
    public static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function notificationPrefs(mixed $value): array|stdClass
    {
        if (!is_string($value) || $value === '') {
            return new stdClass();
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : new stdClass();
    }
}
