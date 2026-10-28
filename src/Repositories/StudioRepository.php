<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class StudioRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Datos configurados, sin inventar domicilio, teléfono ni datos fiscales. */
    public function ticketData(): array
    {
        $statement = $this->db->pdo()->prepare('SELECT `key`, value_json FROM settings WHERE `key` IN (?, ?)');
        $statement->execute(['studio_name', 'timezone']);
        $data = ['name' => '', 'timezone' => 'UTC'];
        foreach ($statement->fetchAll() as $row) {
            $value = json_decode($row['value_json'], true, 16, JSON_THROW_ON_ERROR);
            if (is_string($value)) {
                $data[$row['key'] === 'studio_name' ? 'name' : 'timezone'] = $value;
            }
        }
        return $data;
    }
}
