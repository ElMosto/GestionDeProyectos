<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Repositories\AuditLogRepository;
use Throwable;

/**
 * Auditoría de permisos, importes, pagos, estados, horarios, cancelaciones y
 * exportaciones sensibles (§16).
 *
 * Guarda `before`/`after` **sin secretos**: contraseñas, hashes, tokens, CSRF y
 * datos de tarjeta se eliminan antes de escribir. La auditoría nunca rompe la
 * operación de negocio: un fallo al registrar se anota en el log privado.
 */
final class AuditLogger
{
    public const LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';
    public const LOGIN_INACTIVE_ACCOUNT = 'LOGIN_INACTIVE_ACCOUNT';
    public const LOGOUT = 'LOGOUT';
    public const ACCOUNT_INACTIVE_ACCESS = 'ACCOUNT_INACTIVE_ACCESS';
    public const PASSWORD_CHANGE = 'PASSWORD_CHANGE';
    public const PASSWORD_RESET_ISSUED = 'PASSWORD_RESET_ISSUED';
    public const PROFILE_UPDATE = 'PROFILE_UPDATE';
    public const AVATAR_UPDATE = 'AVATAR_UPDATE';
    public const TOKEN_CREATED = 'TOKEN_CREATED';
    public const TOKEN_REVOKED = 'TOKEN_REVOKED';
    public const TOKENS_REVOKED_ALL = 'TOKENS_REVOKED_ALL';

    /** Claves exactas que jamás se persisten en la bitácora. */
    private const FORBIDDEN_KEYS = [
        'password',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'password_hash',
        'password_confirmation',
        'token',
        'token_plain',
        'plain_token',
        'csrf',
        'csrf_token',
        'secret',
        'authorization',
        'card',
        'card_number',
        'cvv',
        'pan',
    ];

    private const MAX_DEPTH = 4;
    private const MAX_STRING = 2000;

    private ?string $requestId = null;
    private ?string $ip = null;

    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly AuthContext $auth,
    ) {
    }

    /**
     * Vincula la petición HTTP actual para que toda entrada quede correlacionada.
     */
    public function bindRequest(?string $requestId, ?string $ip): void
    {
        $this->requestId = $requestId;
        $this->ip = $ip;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): void {
        try {
            $this->logs->insert(
                $actorId ?? $this->auth->id(),
                $action,
                $entityType,
                $entityId === null ? null : (int) $entityId,
                $before === null ? null : $this->sanitize($before),
                $after === null ? null : $this->sanitize($after),
                $this->requestId,
                $this->ip,
            );
        } catch (Throwable $e) {
            // Se registra el fallo, nunca el contenido sensible del payload.
            error_log(sprintf('[audit] no se pudo registrar %s: %s', $action, $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $clean = $this->sanitizeValue($payload, 0);

        return is_array($clean) ? $clean : [];
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[omitido]';
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isForbiddenKey($key)) {
                    $clean[$key] = '[omitido]';
                    continue;
                }

                $clean[$key] = $this->sanitizeValue($item, $depth + 1);
            }

            return $clean;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, self::MAX_STRING);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[omitido]';
    }

    private function isForbiddenKey(string $key): bool
    {
        $key = mb_strtolower($key);

        if (in_array($key, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        // Sufijos inequívocos: token_hash, api_token, access_token, client_secret...
        foreach (['_token', '_hash', '_secret'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        // `must_change_password` es un indicador auditable, no una credencial.
        return str_ends_with($key, '_password') && !str_starts_with($key, 'must_change_');
    }
}
