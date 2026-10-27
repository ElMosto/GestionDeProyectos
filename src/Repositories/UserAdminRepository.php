<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use PDOStatement;

/**
 * SQL de administración de cuentas (`users` + `roles`) para `/api/v1/users`.
 *
 * No sustituye a `App\Auth\Repositories\UserRepository`: aquel resuelve identidad
 * y credenciales; este resuelve listados, altas y ediciones administrativas. Toda
 * consulta es preparada y las entradas nunca se concatenan.
 */
final class UserAdminRepository
{
    /** Lista blanca de ordenación (§15). */
    private const SORT_COLUMNS = [
        'name' => 'u.name',
        'created_at' => 'u.created_at',
        'last_login_at' => 'u.last_login_at',
    ];

    /** Lista blanca de columnas editables por el administrador. */
    private const EDITABLE_COLUMNS = ['name', 'phone', 'job_title', 'timezone', 'role_id'];

    private const SELECT_COLUMNS = 'u.id, u.role_id, u.name, u.email, u.phone, u.job_title, u.timezone,'
        . ' u.notification_prefs, u.avatar_path, u.active, u.must_change_password,'
        . ' u.password_changed_at, u.last_login_at, u.created_by, u.version,'
        . ' u.created_at, u.updated_at, r.code AS role_code, r.name AS role_name';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(
        ?string $q,
        ?string $roleCode,
        ?int $active,
        int $limit,
        int $offset,
        string $sort,
        string $direction,
    ): array {
        [$where, $params] = self::filters($q, $roleCode, $active);

        $order = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['name'];
        $orderDirection = $direction === 'desc' ? 'DESC' : 'ASC';

        $sql = 'SELECT ' . self::SELECT_COLUMNS
            . ' FROM users u JOIN roles r ON r.id = u.role_id'
            . $where
            . ' ORDER BY ' . $order . ' ' . $orderDirection . ', u.id ' . $orderDirection
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        self::bind($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id' . $where
        );
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
            'SELECT ' . self::SELECT_COLUMNS
            . ' FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.email, u.active FROM users u WHERE u.email = ? LIMIT 1'
        );
        $stmt->execute([$email]);

        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'active' => (int) $row['active']] : null;
    }

    public function create(
        int $roleId,
        string $name,
        string $email,
        string $passwordHash,
        ?string $phone,
        ?string $jobTitle,
        string $timezone,
        int $createdBy,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (role_id, name, email, password_hash, phone, job_title, timezone,'
            . ' active, must_change_password, created_by, version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?, 1)'
        );
        $stmt->execute([$roleId, $name, $email, $passwordHash, $phone, $jobTitle, $timezone, $createdBy]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Actualiza solo columnas de la lista blanca e incrementa la versión en el
     * mismo `UPDATE`. Devuelve `null` si la versión esperada ya no coincide.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $fields, int $expectedVersion): ?array
    {
        $assignments = [];
        $params = [];

        foreach ($fields as $column => $value) {
            if (!in_array($column, self::EDITABLE_COLUMNS, true)) {
                throw new \LogicException(sprintf('Columna de usuario no permitida: %s', $column));
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
            'UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ? AND version = ?'
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
            'UPDATE users SET active = ?, version = version + 1 WHERE id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $id]);

        return $this->findById($id);
    }

    /**
     * Bloquea las cuentas ADMINISTRADOR activas para que dos bajas o cambios de
     * rol concurrentes no puedan dejar el sistema sin administrador (§5).
     * Debe invocarse dentro de la transacción, antes de `LastAdminGuard`.
     */
    public function lockActiveAdmins(): void
    {
        $stmt = $this->db->pdo()->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id"
            . " WHERE r.code = 'ADMINISTRADOR' AND u.active = 1 FOR UPDATE"
        );
        $stmt->fetchAll();
    }

    /**
     * Directorio mínimo para asignaciones e invitaciones (§5): solo activos.
     *
     * @return list<array<string, mixed>>
     */
    public function directory(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT u.id, u.name, u.job_title, r.code AS role_code'
            . ' FROM users u JOIN roles r ON r.id = u.role_id'
            . ' WHERE u.active = 1 ORDER BY u.name ASC, u.id ASC'
        );

        $rows = $stmt->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'job_title' => is_string($row['job_title']) && $row['job_title'] !== '' ? $row['job_title'] : null,
                'role_code' => (string) $row['role_code'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    /**
     * @return array{0: string, 1: array<string, array{0: mixed, 1: int}>}
     */
    private static function filters(?string $q, ?string $roleCode, ?int $active): array
    {
        $where = [];
        $params = [];

        if ($q !== null && $q !== '') {
            $where[] = '(u.name LIKE :q_name OR u.email LIKE :q_email)';
            $params[':q_name'] = ['%' . $q . '%', PDO::PARAM_STR];
            $params[':q_email'] = ['%' . $q . '%', PDO::PARAM_STR];
        }

        if ($roleCode !== null) {
            $where[] = 'r.code = :role_code';
            $params[':role_code'] = [$roleCode, PDO::PARAM_STR];
        }

        if ($active !== null) {
            $where[] = 'u.active = :active';
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
        foreach (['id', 'role_id', 'active', 'must_change_password', 'version'] as $intColumn) {
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
