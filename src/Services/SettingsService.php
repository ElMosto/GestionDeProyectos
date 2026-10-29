<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\SettingsRepository;

final class SettingsService
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly Authorizer $authorizer,
        private readonly Database $db,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function show(): array
    {
        $this->authorizer->requireAdmin();
        return self::versioned($this->repository->state());
    }

    public function updateSettings(array $fields): array
    {
        $this->authorizer->requireAdmin();
        return $this->db->transaction(function () use ($fields): array {
            $old = self::versioned($this->repository->state(true));
            if (!hash_equals($old['version'], $fields['expected_version'])) {
                throw new ApiException(409, 'VERSION_CONFLICT', 'La configuración cambió. Recárgala antes de continuar.');
            }
            unset($fields['expected_version']);
            if (isset($fields['timezone']) && $fields['timezone'] !== $old['timezone'] && $this->repository->futureOccupancy()) {
                throw new ApiException(409, 'SETTINGS_TIMEZONE_IN_USE', 'Resuelve las ocupaciones futuras antes de cambiar la zona horaria.');
            }
            $this->repository->change($fields, (int) $this->authorizer->id());
            $after = self::versioned($this->repository->state());
            $this->audit->record('SETTINGS_UPDATE', 'settings', 0, $old, $after);
            return $after;
        });
    }

    private static function versioned(array $state): array
    {
        ksort($state);
        return $state + ['version' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }
}
