<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuditLogger;
use App\Auth\Repositories\AuditLogRepository;
use App\Core\Request;
use App\Policies\Authorizer;

/** Auditoría fail-closed en la misma conexión y transacción que la mutación. */
final class ProjectAudit
{
    private ?string $requestId = null;
    private ?string $ip = null;

    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly AuditLogger $sanitizer,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function bind(Request $request): void
    {
        $this->requestId = $request->requestId;
        $this->ip = $request->ip;
    }

    public function record(string $action, string $entity, int $id, ?array $before, array $after): void
    {
        $this->logs->insert(
            $this->authorizer->id(), $action, $entity, $id,
            $before === null ? null : $this->sanitizer->sanitize($before),
            $this->sanitizer->sanitize($after), $this->requestId, $this->ip,
        );
    }
}
