<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends ProjectStorage
{
    public function state(bool $lock = false): array
    {
        $rows = $this->rows("SELECT `key`,value_json FROM settings WHERE `key` IN ('studio_name','timezone','currency') ORDER BY `key`" . ($lock ? ' FOR UPDATE' : ''));
        $state = [];
        foreach ($rows as $row) {
            $state[$row['key']] = json_decode($row['value_json'], true, 8, JSON_THROW_ON_ERROR);
        }
        if (count($state) !== 3 || $state['currency'] !== 'MXN') {
            throw new \LogicException('Configuración inicial incompleta.');
        }
        return $state;
    }

    public function change(array $fields, int $actor): void
    {
        foreach ($fields as $key => $value) {
            if (!in_array($key, ['studio_name','timezone'], true)) {
                throw new \LogicException('Configuración no permitida.');
            }
            $this->execute('UPDATE settings SET value_json=?,updated_by=?,version=version+1 WHERE `key`=?',
                [json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$actor,$key]);
        }
    }

    public function futureOccupancy(): bool
    {
        // Writers of occupancies take these same ordered parent locks (§12).
        $this->rows('SELECT id FROM spaces ORDER BY id FOR UPDATE');
        return $this->one("SELECT id FROM space_occupancies WHERE status IN ('CONFIRMED','IN_PROGRESS') AND end_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE") !== null;
    }
}
