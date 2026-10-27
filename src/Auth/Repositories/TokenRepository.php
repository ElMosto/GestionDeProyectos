<?php

declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Core\Database;

/**
 * Tokens opacos de la app móvil (D-08). Solo se guarda el SHA-256 del token.
 */
final class TokenRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $name, string $tokenHash, string $expiresAt): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $name, $tokenHash, $expiresAt]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, created_at, last_used_at, expires_at FROM api_tokens'
            . ' WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, expires_at, revoked_at, last_used_at FROM api_tokens WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function revokeOwned(int $userId, int $tokenId, string $utcNow): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$utcNow, $tokenId, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function revokeAllForUser(int $userId, string $utcNow): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$utcNow, $userId]);

        return $stmt->rowCount();
    }

    /**
     * Escribe `last_used_at` como máximo una vez por minuto: evita un UPDATE por petición.
     */
    public function touch(int $tokenId, string $utcNow, int $minIntervalSeconds = 60): void
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - max(1, $minIntervalSeconds));

        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET last_used_at = ? WHERE id = ?'
            . ' AND (last_used_at IS NULL OR last_used_at < ?)'
        );
        $stmt->execute([$utcNow, $tokenId, $threshold]);
    }
}
