<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogsRepository extends ProjectStorage
{
    public function listing(array $q): array
    {
        $where = 'created_at>=? AND created_at<?';
        $params = [$q['start'],$q['end']];
        foreach (['actor_id','action','entity_type'] as $key) {
            if (isset($q[$key])) {
                $where .= ' AND ' . $key . '=?';
                $params[] = $q[$key];
            }
        }
        // Never fetch raw deltas: meeting privacy applies even to global administrators.
        return $this->page('id,actor_id,action,entity_type,entity_id,request_id,ip_address,created_at',
            'FROM audit_logs', $where, $params, 'created_at DESC,id DESC', $q);
    }
}
