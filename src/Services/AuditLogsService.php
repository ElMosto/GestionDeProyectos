<?php

declare(strict_types=1);

namespace App\Services;

use App\Policies\Authorizer;
use App\Repositories\AuditLogsRepository;

final class AuditLogsService
{
    public function __construct(private readonly AuditLogsRepository $repository, private readonly Authorizer $authorizer)
    {
    }

    public function listing(array $q): array
    {
        $this->authorizer->requireAdmin();
        $result = $this->repository->listing($q);
        $data = [];
        foreach ($result['rows'] as $row) {
            $item = array_intersect_key($row, array_flip(['id','actor_id','action','entity_type','entity_id','request_id','ip_address','created_at']));
            foreach (['id','actor_id','entity_id'] as $key) {
                $item[$key] = $item[$key] === null ? null : (string) $item[$key];
            }
            $item['created_at'] = str_replace(' ', 'T', $item['created_at']) . 'Z';
            $data[] = $item;
        }
        return ['data' => $data,'meta' => $result['meta']];
    }
}
