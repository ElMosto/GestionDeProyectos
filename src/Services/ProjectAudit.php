<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuditLogger;
use App\Auth\Repositories\AuditLogRepository;
use App\Core\Request;
use App\Policies\Authorizer;
use App\Repositories\CommercialHistoryRepository;

/** Auditoría fail-closed en la misma conexión y transacción que la mutación. */
final class ProjectAudit
{
    private ?string $requestId = null;
    private ?string $ip = null;

    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly AuditLogger $sanitizer,
        private readonly Authorizer $authorizer,
        private readonly CommercialHistoryRepository $history,
    ) {
    }

    public function bind(Request $request): void
    {
        $this->requestId = $request->requestId;
        $this->ip = $request->ip;
    }

    public function record(string $action, string $entity, int $id, ?array $before, array $after): int
    {
        $auditId = $this->logs->insert(
            $this->authorizer->id(), $action, $entity, $id,
            $before === null ? null : $this->sanitizer->sanitize($before),
            $this->sanitizer->sanitize($after), $this->requestId, $this->ip,
        );
        // §11 / D-28: evidencia y snapshot comparten la transacción de negocio.
        // Lecturas/tickets y cambios puramente operativos no crean historia comercial.
        if (in_array($action, [
            'PROJECT_CREATE', 'PROJECT_UPDATE', 'PROJECT_TRANSITION', 'PROJECT_REOPEN',
            'PROJECT_CHARGE_CREATE', 'PROJECT_CHARGE_UPDATE',
            'SALE_ISSUE', 'SALE_CANCEL', 'SALE_RESERVATION_RESCHEDULE',
        ], true)) {
            $this->history->capture($auditId, $action, $entity, $id);
        }
        return $auditId;
    }
}
