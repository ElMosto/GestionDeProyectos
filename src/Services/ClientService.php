<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuditLogger;
use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\ClientRepository;

/**
 * Reglas de clientes (§7, §15).
 *
 * El directorio mínimo ve todo usuario activo. `email`, `address`, `notes`,
 * `representative_name`, `created_by`, fechas y `version` solo se serializan si
 * el actor es ADMIN (`clients.viewSensitive`) o el creador del registro. El
 * recorte ocurre aquí, en el servidor, nunca en el frontend.
 */
final class ClientService
{
    /** Campos que solo puede tocar `clients.editAny` (ADMIN). */
    private const ADMIN_ONLY_FIELDS = ['type', 'name', 'notes'];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly AuditLogger $audit,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * @param array{q: ?string, type: ?string, active: ?int, limit: int, offset: int, sort: string, direction: string} $query
     * @param array<string, mixed> $actor
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(array $query, array $actor): array
    {
        $result = $this->clients->paginate(
            $query['q'],
            $query['type'],
            $query['active'],
            $query['limit'],
            $query['offset'],
            $query['sort'],
            $query['direction'],
        );

        return [
            'data' => array_map(fn (array $row): array => $this->present($row), $result['rows']),
            'meta' => [
                'total' => $result['total'],
                'limit' => $query['limit'],
                'offset' => $query['offset'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function show(int $id, array $actor): array
    {
        $client = $this->clients->findById($id);

        if ($client === null || !$this->authorizer->canViewClient($client)) {
            throw ApiException::notFound('Cliente no encontrado.');
        }

        return $this->present($client);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array{client: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function create(array $input, array $actor): array
    {
        $actorId = (int) $actor['id'];
        $id = $this->clients->create($input, $actorId);
        $client = $this->clients->findById($id);

        if ($client === null) {
            throw ApiException::notFound('Cliente no encontrado.');
        }

        $this->audit->log('CLIENT_CREATE', 'client', $id, null, $input, $actorId);

        return [
            'client' => $this->present($client),
            'warnings' => $this->duplicateWarnings($client),
        ];
    }

    /**
     * @param array{fields: array<string, mixed>, expected_version: int} $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, array $actor): array
    {
        $client = $this->clients->findById($id);

        if ($client === null || !$this->authorizer->canViewClient($client)) {
            throw ApiException::notFound('Cliente no encontrado.');
        }

        if (!$this->authorizer->canEditClient($client)) {
            throw ApiException::forbidden('No puedes editar este cliente.');
        }

        $fields = $input['fields'];
        $expectedVersion = $input['expected_version'];

        if (!$this->authorizer->can(Capabilities::CLIENTS_EDIT_ANY)) {
            foreach (self::ADMIN_ONLY_FIELDS as $field) {
                if (array_key_exists($field, $fields)) {
                    throw ApiException::forbidden('Solo un administrador puede modificar ese campo.');
                }
            }
        }

        if ($fields === []) {
            if ((int) $client['version'] !== $expectedVersion) {
                throw self::versionConflict();
            }

            return $this->present($client);
        }

        $before = array_intersect_key($client, $fields);

        $updated = $this->db->transaction(function () use ($id, $fields, $expectedVersion): ?array {
            $locked = $this->clients->lockById($id);

            if ($locked === null) {
                throw ApiException::notFound('Cliente no encontrado.');
            }

            $mergedType = $fields['type'] ?? $locked['type'];
            $representative = array_key_exists('representative_name', $fields)
                ? $fields['representative_name']
                : $locked['representative_name'];
            $phone = array_key_exists('phone', $fields) ? $fields['phone'] : $locked['phone'];

            if ($mergedType === 'BANDA' && (self::isBlank($representative) || self::isBlank($phone))) {
                $errors = [];

                if (self::isBlank($representative)) {
                    $errors['representative_name'] = 'Una banda exige el nombre del representante.';
                }

                if (self::isBlank($phone)) {
                    $errors['phone'] = 'Una banda exige un teléfono de contacto.';
                }

                throw ApiException::validation($errors);
            }

            // §7: no convertir BANDA → NORMAL con reservas futuras activas.
            if (
                ($fields['type'] ?? $locked['type']) === 'NORMAL'
                && $locked['type'] === 'BANDA'
                && $this->clients->hasFutureReservations($id)
            ) {
                throw new ApiException(
                    409,
                    'CLIENT_HAS_FUTURE_RESERVATIONS',
                    'El cliente tiene reservas futuras activas. Resuélvelas antes de convertirlo a NORMAL.',
                );
            }

            return $this->clients->update($id, $fields, $expectedVersion);
        });

        if ($updated === null) {
            throw self::versionConflict();
        }

        $this->audit->log('CLIENT_UPDATE', 'client', $id, $before, $fields, (int) $actor['id']);

        return $this->present($updated);
    }

    /**
     * Baja lógica con protección de reservas futuras (§7). Nunca borra.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function deactivate(int $id, array $actor): array
    {
        $client = $this->clients->findById($id);

        if ($client === null) {
            throw ApiException::notFound('Cliente no encontrado.');
        }

        if (!$this->authorizer->canDeactivateClient($client)) {
            throw ApiException::forbidden();
        }

        if ((int) $client['active'] === 1) {
            $updated = $this->db->transaction(function () use ($id): ?array {
                $locked = $this->clients->lockById($id);

                if ($locked === null) {
                    throw ApiException::notFound('Cliente no encontrado.');
                }

                if ($this->clients->hasFutureReservations($id)) {
                    throw new ApiException(
                        409,
                        'CLIENT_HAS_FUTURE_RESERVATIONS',
                        'El cliente tiene reservas futuras activas. Resuélvelas antes de desactivarlo.',
                    );
                }

                return $this->clients->setActive($id, false);
            });

            if ($updated !== null) {
                $client = $updated;
            }

            $this->audit->log(
                'CLIENT_DEACTIVATE',
                'client',
                $id,
                ['active' => true],
                ['active' => false],
                (int) $actor['id'],
            );
        }

        return $this->present($client);
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function present(array $client): array
    {
        $data = [
            'id' => (string) $client['id'],
            'type' => (string) $client['type'],
            'name' => (string) $client['name'],
            'contact_name' => self::nullable($client['contact_name'] ?? null),
            'phone' => self::nullable($client['phone'] ?? null),
            'active' => (int) $client['active'] === 1,
        ];

        if (!$this->authorizer->canViewSensitiveClient($client)) {
            return $data;
        }

        $data['representative_name'] = self::nullable($client['representative_name'] ?? null);
        $data['email'] = self::nullable($client['email'] ?? null);
        $data['address'] = self::nullable($client['address'] ?? null);
        $data['notes'] = self::nullable($client['notes'] ?? null);
        $data['created_by'] = $client['created_by'] !== null ? (string) $client['created_by'] : null;
        $data['version'] = (string) $client['version'];
        $data['created_at'] = UserPresenter::iso($client['created_at'] ?? null);
        $data['updated_at'] = UserPresenter::iso($client['updated_at'] ?? null);

        return $data;
    }

    /**
     * @param array<string, mixed> $client
     * @return list<array<string, mixed>>
     */
    private function duplicateWarnings(array $client): array
    {
        $name = mb_strtolower(trim((string) $client['name']));
        $phone = ClientRepository::normalizePhone(self::nullable($client['phone'] ?? null));
        $email = self::nullable($client['email'] ?? null);
        $email = $email === null ? null : mb_strtolower($email);

        $rows = $this->clients->findPossibleDuplicates(
            (int) $client['id'],
            $name,
            self::nullable($client['phone'] ?? null),
            $email,
        );

        $warnings = [];

        foreach ($rows as $row) {
            $matched = [];

            if (mb_strtolower(trim((string) $row['name'])) === $name) {
                $matched[] = 'name';
            }

            if ($phone !== null && ClientRepository::normalizePhone($row['phone']) === $phone) {
                $matched[] = 'phone';
            }

            if ($email !== null && mb_strtolower(trim((string) $row['email'])) === $email) {
                $matched[] = 'email';
            }

            if ($matched !== []) {
                $warnings[] = [
                    'id' => (string) $row['id'],
                    'name' => (string) $row['name'],
                    'matched_by' => $matched,
                ];
            }
        }

        return $warnings;
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private static function versionConflict(): ApiException
    {
        return new ApiException(
            409,
            'VERSION_CONFLICT',
            'El cliente cambió mientras lo editabas. Vuelve a cargarlo e inténtalo de nuevo.',
            ['version' => 'La versión enviada ya no es la actual.'],
        );
    }
}
