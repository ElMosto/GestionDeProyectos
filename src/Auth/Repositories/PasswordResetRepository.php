<?php

declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Core\Database;

/**
 * Claves temporales de un solo uso (§6). Se guarda el SHA-256, nunca la clave.
 */
final class PasswordResetRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt, ?int $createdBy): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt, $createdBy]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUsable(int $userId, string $tokenHash, string $utcNow): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, expires_at FROM password_resets'
            . ' WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?'
            . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $tokenHash, $utcNow]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Consume todas las claves pendientes del usuario: una clave temporal es de un solo uso.
     */
    public function markAllUsed(int $userId, string $utcNow): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$utcNow, $userId]);

        return $stmt->rowCount();
    }
}
