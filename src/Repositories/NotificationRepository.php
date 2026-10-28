<?php

declare(strict_types=1);

namespace App\Repositories;

final class NotificationRepository extends ProjectStorage
{
    public function listing(int $actor, array $q): array
    {
        $where = 'user_id=?';
        if (($q['unread'] ?? false) === true) {
            $where .= ' AND read_at IS NULL';
        }
        $page = $this->page('id,user_id,type,entity_type,entity_id,read_at,created_at', 'FROM notifications',
            $where, [$actor], 'created_at DESC,id DESC', $q);
        $page['meta']['unread_count'] = (int) $this->one('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND read_at IS NULL', [$actor])['n'];
        return $page;
    }

    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT id,user_id,type,entity_type,entity_id,read_at,created_at FROM notifications WHERE id=?'
            . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function markRead(int $id): void
    {
        $this->execute('UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=?', [$id]);
    }

    public function notifyActive(int $user, string $type, string $entity, int $id, array $payload): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Las notificaciones de dominio requieren transacción existente.');
        }
        $this->execute('INSERT INTO notifications(user_id,type,entity_type,entity_id,payload)
            SELECT id,?,?,?,? FROM users WHERE id=? AND active=1',
            [$type, $entity, $id, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $user]);
    }
}
