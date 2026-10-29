<?php

declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Core\Database;
use JsonException;

/**
 * Bitácora append-only (§16). Nunca se actualiza ni se borra una fila.
 */
final class AuditLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before Ya saneado por AuditLogger
     * @param array<string, mixed>|null $after  Ya saneado por AuditLogger
     */
    public function insert(
        ?int $actorId,
        ?string $action,
        string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        ?string $requestId,
        ?string $ip,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_json, after_json, request_id, ip_address)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $actorId,
            mb_substr($action === null ? '' : $action, 0, 60),
            mb_substr($entityType, 0, 40),
            $entityId,
            $before === null ? null : self::encode($before),
            $after === null ? null : self::encode($after),
            $requestId === null ? null : mb_substr($requestId, 0, 64),
            $ip === null ? null : mb_substr($ip, 0, 45),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{}';
        }
    }
}
