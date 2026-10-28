<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProjectIdempotencyRepository extends ProjectStorage
{
    public function acquire(int $actorId, string $operation, string $key, string $hash): array
    {
        $this->execute('INSERT INTO idempotency_requests(actor_id,operation,`key`,request_hash) VALUES (?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)', [$actorId, $operation, $key, $hash]);
        return $this->one('SELECT id,request_hash,response_json FROM idempotency_requests WHERE actor_id=? AND operation=? AND `key`=? FOR UPDATE', [$actorId, $operation, $key]);
    }

    public function complete(int $id, array $response, int $status): void
    {
        $this->execute("UPDATE idempotency_requests SET status='COMPLETED',response_status=?,response_json=?,completed_at=UTC_TIMESTAMP() WHERE id=?",
            [$status, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
    }
}
