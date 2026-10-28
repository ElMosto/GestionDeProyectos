<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\NotificationRepository;

final class NotificationService
{
    private const TEMPLATES = [
        'MEETING_INVITED' => ['meeting', 'Tienes una invitación a una reunión.'],
        'MEETING_CHANGED' => ['meeting', 'Una reunión ha cambiado. Revisa tu invitación.'],
        'MEETING_CANCELLED' => ['meeting', 'Una reunión ha sido cancelada.'],
        'MEETING_RESPONDED' => ['meeting', 'Una invitación a tu reunión recibió respuesta.'],
        'TASK_ASSIGNED' => ['task', 'Tienes una tarea asignada.'],
        'TASK_REASSIGNED' => ['task', 'La asignación de una tarea ha cambiado.'],
        'TASK_CHANGED' => ['task', 'Una tarea ha cambiado.'],
        'TASK_CANCELLED' => ['task', 'Una tarea ha sido cancelada.'],
        'RESERVATION_CREATED' => ['reservation', 'Tienes una nueva reserva a tu cargo.'],
        'RESERVATION_CHANGED' => ['reservation', 'Una reserva ha cambiado.'],
        'RESERVATION_CANCELLED' => ['reservation', 'Una reserva ha sido cancelada.'],
    ];

    public function __construct(
        private readonly NotificationRepository $notes,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function list(array $query): array
    {
        $this->authorizer->requireCapability(Capabilities::NOTIFICATIONS_VIEW);
        $page = $this->notes->listing((int) $this->authorizer->id(), $query);
        return ['data' => array_map($this->serialize(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function read(int $id): array
    {
        $this->authorizer->requireUser();
        return $this->db->transaction(function () use ($id): array {
            $note = $this->notes->find($id, true);
            if (!$this->authorizer->canReadNotification($note)) {
                throw ApiException::notFound();
            }
            $this->notes->markRead($id);
            return $this->serialize($this->notes->find($id, true));
        });
    }

    /** Internal domain event; caller owns transaction. Never starts a nested transaction. */
    public function notify(array $userIds, string $type, string $entityType, int $entityId): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Notificar requiere transacción de dominio.');
        }
        $payload = $this->payload($type, $entityType, $entityId);
        $ids = [];
        foreach ($userIds as $id) {
            if ((!is_int($id) && !is_string($id)) || preg_match('/\A[1-9][0-9]*\z/', (string) $id) !== 1
                || strlen((string) $id) > strlen((string) PHP_INT_MAX)
                || (strlen((string) $id) === strlen((string) PHP_INT_MAX) && strcmp((string) $id, (string) PHP_INT_MAX) > 0)) {
                throw new \LogicException('Destinatario de notificación no válido.');
            }
            $ids[(int) $id] = (int) $id;
        }
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $this->notes->notifyActive($id, $type, $entityType, $entityId, $payload);
        }
    }

    private function payload(string $type, string $entity, int $id): array
    {
        $template = self::TEMPLATES[$type] ?? null;
        if ($template === null || $template[0] !== $entity || $id < 1) {
            throw new \LogicException('Tipo o referencia de notificación no admitidos.');
        }
        $route = match ($entity) {
            'meeting' => 'meetings', 'task' => 'tasks', 'reservation' => 'reservations',
        };
        return ['message' => $template[1], 'action' => '/' . $route . '/' . $id];
    }

    private function serialize(array $row): array
    {
        if (!$this->authorizer->canReadNotification($row)) {
            throw ApiException::notFound();
        }
        // Never echo persisted payload, even legacy rows can contain private snapshots.
        $known = isset(self::TEMPLATES[$row['type']]) && self::TEMPLATES[$row['type']][0] === $row['entity_type']
            && $row['entity_id'] !== null && (int) $row['entity_id'] > 0;
        return ['id' => (string) $row['id'], 'type' => $row['type'], 'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
            'payload' => $known ? $this->payload($row['type'], $row['entity_type'], (int) $row['entity_id'])
                : ['message' => 'Tienes una notificación.', 'action' => '/notifications'],
            'read_at' => self::iso($row['read_at']), 'created_at' => self::iso($row['created_at'])];
    }

    private static function iso(?string $value): ?string
    {
        return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
    }
}
