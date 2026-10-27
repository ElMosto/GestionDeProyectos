<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use PDOStatement;

/**
 * SQL de clientes (§7, §15). Los clientes nunca son cuentas y solo se desactivan.
 *
 * El recorte de campos sensibles no ocurre aquí: el repositorio devuelve la fila
 * completa y el serializador del servicio decide qué ve cada actor.
 */
final class ClientRepository
{
    /** Lista blanca de ordenación. */
    private const SORT_COLUMNS = [
        'name' => 'c.name',
        'created_at' => 'c.created_at',
        'updated_at' => 'c.updated_at',
    ];

    /** Lista blanca de columnas editables. */
    private const EDITABLE_COLUMNS = [
        'type',
        'name',
        'contact_name',
        'representative_name',
        'phone',
        'email',
        'address',
        'notes',
    ];

    private const SELECT_COLUMNS = 'c.id, c.type, c.name, c.contact_name, c.representative_name,'
        . ' c.phone, c.email, c.address, c.notes, c.active, c.created_by, c.version,'
        . ' c.created_at, c.updated_at';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(
        ?string $q,
        ?string $type,
        ?int $active,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
    ): array {
        [$where, $params] = self::filters($q, $type, $active);

        $order = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['name'];
        $orderDirection = $direction === 'desc' ? 'DESC' : 'ASC';

        $sql = 'SELECT ' . self::SELECT_COLUMNS
            . ' FROM clients c'
            . $where
            . ' ORDER BY ' . $order . ' ' . $orderDirection . ', c.id ' . $orderDirection
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        self::bind($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients c' . $where);
        self::bind($countStmt, $params);
        $countStmt->execute();

        return [
            'rows' => array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []),
            'total' => (int) $countStmt->fetchColumn(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM clients c WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Bloquea la fila dentro de una transacción (baja lógica y conversión de tipo).
     *
     * @return array<string, mixed>|null
     */
    public function lockById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM clients c WHERE c.id = ? FOR UPDATE'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $createdBy): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (type, name, contact_name, representative_name, phone, email,'
            . ' address, notes, active, created_by, version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1)'
        );
        $stmt->execute([
            $data['type'],
            $data['name'],
            $data['contact_name'] ?? null,
            $data['representative_name'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['notes'] ?? null,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $fields, int $expectedVersion): ?array
    {
        $assignments = [];
        $params = [];

        foreach ($fields as $column => $value) {
            if (!in_array($column, self::EDITABLE_COLUMNS, true)) {
                throw new \LogicException(sprintf('Columna de cliente no permitida: %s', $column));
            }

            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }

        if ($assignments === []) {
            return $this->findById($id);
        }

        $assignments[] = 'version = version + 1';
        $params[] = $id;
        $params[] = $expectedVersion;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE clients SET ' . implode(', ', $assignments) . ' WHERE id = ? AND version = ?'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findById($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setActive(int $id, bool $active): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE clients SET active = ?, version = version + 1 WHERE id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $id]);

        return $this->findById($id);
    }

    /**
     * ¿El cliente tiene reservas futuras activas? (§7: no desactivar ni convertir
     * BANDA → NORMAL sin resolverlas). `space_occupancies` es la fuente de verdad.
     */
    public function hasFutureReservations(int $clientId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM reservations r"
            . " JOIN space_occupancies o ON o.id = r.occupancy_id"
            . " WHERE r.band_client_id = ?"
            . " AND o.end_at > UTC_TIMESTAMP()"
            . " AND r.status IN ('CONFIRMED','IN_PROGRESS')"
            . " AND o.status IN ('CONFIRMED','IN_PROGRESS')"
            . ' LIMIT 1'
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Posibles duplicados por nombre normalizado, teléfono o correo (§7).
     * No bloquea: el servicio los devuelve como advertencia.
     *
     * @return list<array<string, mixed>>
     */
    public function findPossibleDuplicates(
        int $excludeId,
        string $normalizedName,
        ?string $phone,
        ?string $email,
    ): array {
        $conditions = ['LOWER(TRIM(c.name)) = :name'];
        $params = [':name' => [$normalizedName, PDO::PARAM_STR]];

        $normalizedPhone = self::normalizePhone($phone);

        if ($normalizedPhone !== null) {
            $conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''),"
                . " '+', ''), '.', ''), '(', ''), ')', '') = :phone";
            $params[':phone'] = [$normalizedPhone, PDO::PARAM_STR];
        }

        if ($email !== null) {
            $conditions[] = 'LOWER(TRIM(c.email)) = :email';
            $params[':email'] = [$email, PDO::PARAM_STR];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.name, c.phone, c.email FROM clients c'
            . ' WHERE c.id <> :exclude_id AND (' . implode(' OR ', $conditions) . ')'
            . ' ORDER BY c.name ASC LIMIT 20'
        );
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        self::bind($stmt, $params);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'phone' => is_string($row['phone']) && $row['phone'] !== '' ? $row['phone'] : null,
                'email' => is_string($row['email']) && $row['email'] !== '' ? $row['email'] : null,
            ],
            is_array($rows) ? $rows : []
        );
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    /**
     * @return array{0: string, 1: array<string, array{0: mixed, 1: int}>}
     */
    private static function filters(?string $q, ?string $type, ?int $active): array
    {
        $where = [];
        $params = [];

        if ($q !== null && $q !== '') {
            $where[] = '(c.name LIKE :q_name OR c.contact_name LIKE :q_contact'
                . ' OR c.representative_name LIKE :q_representative'
                . ' OR c.phone LIKE :q_phone OR c.email LIKE :q_email)';
            $params[':q_name'] = ['%' . $q . '%', PDO::PARAM_STR];
            $params[':q_contact'] = ['%' . $q . '%', PDO::PARAM_STR];
            $params[':q_representative'] = ['%' . $q . '%', PDO::PARAM_STR];
            $params[':q_phone'] = ['%' . $q . '%', PDO::PARAM_STR];
            $params[':q_email'] = ['%' . $q . '%', PDO::PARAM_STR];
        }

        if ($type !== null) {
            $where[] = 'c.type = :type';
            $params[':type'] = [$type, PDO::PARAM_STR];
        }

        if ($active !== null) {
            $where[] = 'c.active = :active';
            $params[':active'] = [$active, PDO::PARAM_INT];
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, array{0: mixed, 1: int}> $params
     */
    private static function bind(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'active', 'version'] as $intColumn) {
            if (array_key_exists($intColumn, $row)) {
                $row[$intColumn] = (int) $row[$intColumn];
            }
        }

        if (array_key_exists('created_by', $row) && $row['created_by'] !== null) {
            $row['created_by'] = (int) $row['created_by'];
        }

        return $row;
    }
}
