<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/** Utilidades SQL privadas al módulo; identificadores exclusivamente de esquemas fijos. */
abstract class ProjectStorage
{
    public function __construct(protected readonly Database $db)
    {
    }

    protected function rows(string $sql, array $params = []): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            $statement->bindValue($i + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
        return $statement->fetchAll();
    }

    protected function one(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    protected function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute(array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, $params));
    }

    protected function insertRow(string $table, array $fields, array $allowed): int
    {
        $this->check($fields, $allowed);
        $this->execute(
            'INSERT INTO ' . $table . ' (' . implode(',', array_keys($fields)) . ') VALUES ('
                . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields),
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function changeRow(string $table, int $id, array $fields, array $allowed): void
    {
        $this->check($fields, $allowed);
        $assignments = array_map(static fn (string $key): string => $key . '=?', array_keys($fields));
        $assignments[] = 'version=version+1';
        $this->execute('UPDATE ' . $table . ' SET ' . implode(',', $assignments) . ' WHERE id=?', [...array_values($fields), $id]);
    }

    protected function page(string $select, string $from, string $where, array $params, string $order, array $q): array
    {
        $total = $this->one('SELECT COUNT(*) AS n ' . $from . ' WHERE ' . $where, $params);
        return [
            'rows' => $this->rows('SELECT ' . $select . ' ' . $from . ' WHERE ' . $where
                . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $q['limit'], $q['offset']]),
            'meta' => ['total' => (int) $total['n'], 'limit' => $q['limit'], 'offset' => $q['offset']],
        ];
    }

    private function check(array $fields, array $allowed): void
    {
        if (array_diff(array_keys($fields), $allowed) !== []) {
            throw new \LogicException('Columna no permitida en almacenamiento de proyectos.');
        }
    }
}
