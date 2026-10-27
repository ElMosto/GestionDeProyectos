<?php

declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Core\Database;

/**
 * Intentos de acceso para el rate limit de login (§16).
 * Sin FK a `users`: se registran también correos inexistentes, a propósito.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function countFailuresForEmail(string $email, int $windowSeconds): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND successful = 0 AND created_at >= ?'
        );
        $stmt->execute([$email, self::windowStart($windowSeconds)]);

        return (int) $stmt->fetchColumn();
    }

    public function countFailuresForIp(string $ip, int $windowSeconds): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND successful = 0 AND created_at >= ?'
        );
        $stmt->execute([$ip, self::windowStart($windowSeconds)]);

        return (int) $stmt->fetchColumn();
    }

    public function record(?string $email, string $ip, ?string $userAgent, bool $successful): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (email, ip_address, user_agent, successful) VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $email,
            $ip,
            self::truncate($userAgent, 255),
            $successful ? 1 : 0,
        ]);
    }

    private static function windowStart(int $windowSeconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - max(1, $windowSeconds));
    }

    private static function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
