<?php

declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acceso a `users` y `roles`. Única capa con SQL de cuentas (§4, skill arquitectura-php).
 *
 * Los métodos `find*` que alimentan la sesión devuelven la fila SIN `password_hash`:
 * el hash solo sale de `passwordHash()` y de `findWithPasswordByEmail()`, que se usan
 * exclusivamente para verificar credenciales.
 */
final class UserRepository
{
    private const AUTH_COLUMNS = 'u.id, u.role_id, u.name, u.email, u.phone, u.job_title, u.timezone,'
        . ' u.notification_prefs, u.avatar_path, u.active, u.must_change_password,'
        . ' u.password_changed_at, u.last_login_at, u.version,'
        . ' r.code AS role_code, r.name AS role_name';

    /** Lista blanca de columnas editables desde «Mi usuario» (§6). */
    private const EDITABLE_PROFILE_COLUMNS = ['name', 'phone', 'job_title', 'timezone', 'notification_prefs'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAuth(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::AUTH_COLUMNS . ' FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Incluye `password_hash`; usar solo para verificar la contraseña y descartarlo.
     *
     * @return array<string, mixed>|null
     */
    public function findWithPasswordByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::AUTH_COLUMNS . ', u.password_hash'
            . ' FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1'
        );
        $stmt->execute([$email]);

        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function passwordHash(int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function touchLastLogin(int $id, string $utcNow): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([$utcNow, $id]);
    }

    /**
     * Actualiza solo columnas de la lista blanca. Devuelve la fila resultante, o
     * `null` si la versión esperada ya no coincide (409 en el controlador).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    public function updateProfile(int $id, array $fields, ?int $expectedVersion): ?array
    {
        $assignments = [];
        $params = [];

        foreach ($fields as $column => $value) {
            if (!in_array($column, self::EDITABLE_PROFILE_COLUMNS, true)) {
                throw new \LogicException(sprintf('Columna de perfil no permitida: %s', $column));
            }

            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }

        if ($assignments === []) {
            return $this->findForAuth($id);
        }

        $assignments[] = 'version = version + 1';

        $sql = 'UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?';
        $params[] = $id;

        if ($expectedVersion !== null) {
            $sql .= ' AND version = ?';
            $params[] = $expectedVersion;
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findForAuth($id);
    }

    /**
     * @param string|null $hash NULL conserva el hash actual (solo desbloqueo/forzar cambio)
     */
    public function updatePassword(int $id, ?string $hash, bool $mustChange, string $utcNow): void
    {
        $pdo = $this->db->pdo();

        if ($hash === null) {
            $stmt = $pdo->prepare(
                'UPDATE users SET must_change_password = ?, version = version + 1 WHERE id = ?'
            );
            $stmt->execute([$mustChange ? 1 : 0, $id]);

            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = ?, must_change_password = ?,'
            . ' version = version + 1 WHERE id = ?'
        );
        $stmt->execute([$hash, $utcNow, $mustChange ? 1 : 0, $id]);
    }

    public function setAvatarPath(int $id, ?string $path): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET avatar_path = ?, version = version + 1 WHERE id = ?');
        $stmt->execute([$path, $id]);
    }

    public function roleCodeById(int $roleId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM roles WHERE id = ? LIMIT 1');
        $stmt->execute([$roleId]);

        $code = $stmt->fetchColumn();

        return is_string($code) ? $code : null;
    }

    public function countActiveAdmins(): int
    {
        $stmt = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id"
            . " WHERE r.code = 'ADMINISTRADOR' AND u.active = 1"
        );

        return (int) $stmt->fetchColumn();
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

        return $row;
    }
}
