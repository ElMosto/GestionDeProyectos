<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Catálogo de roles (`roles`). Solo lectura: el catálogo es cerrado (D-01).
 */
final class RoleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, name FROM roles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, name FROM roles WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];

        return $row;
    }
}
