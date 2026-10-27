<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\TokenService;
use PDO;
use RuntimeException;

/**
 * Datos de prueba creados por SQL directo.
 *
 * Solo se usa para el estado inicial (actores, tokens, ocupaciones) que no es
 * el objeto de la prueba. Todo lo que se valida se ejerce a través de los
 * servicios o de la API, nunca insertando el resultado esperado.
 *
 * Contraseñas de fixture con coste bcrypt bajo: `password_verify` funciona
 * igual y la suite no pierde segundos por hash.
 */
final class Fixtures
{
    private static int $sequence = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function uniqueSuffix(): string
    {
        self::$sequence++;

        return self::$sequence . '-' . bin2hex(random_bytes(3));
    }

    public function roleId(string $roleCode): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM roles WHERE code = ? LIMIT 1');
        $statement->execute([$roleCode]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Rol inexistente en el catálogo: ' . $roleCode);
        }

        return (int) $id;
    }

    /**
     * @return array{id: int, email: string, password: string, role_code: string, must_change_password: int, active: int}
     */
    public function createUser(
        string $roleCode,
        ?string $email = null,
        string $password = 'TestPassword123!',
        bool $mustChangePassword = false,
        bool $active = true,
        ?string $name = null,
    ): array {
        $email ??= strtolower($roleCode) . '.' . self::uniqueSuffix() . '@test.local';
        $name ??= 'Usuario ' . $roleCode . ' ' . self::uniqueSuffix();

        $statement = $this->pdo->prepare(
            'INSERT INTO users (role_id, name, email, password_hash, timezone, active, must_change_password, created_by, version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1)'
        );
        $statement->execute([
            $this->roleId($roleCode),
            $name,
            $email,
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'America/Mexico_City',
            $active ? 1 : 0,
            $mustChangePassword ? 1 : 0,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'email' => $email,
            'password' => $password,
            'role_code' => $roleCode,
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'active' => $active ? 1 : 0,
        ];
    }

    /**
     * Fila completa de `users` + `roles` tal como la carga la autenticación.
     *
     * @return array<string, mixed>
     */
    public function userRow(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.role_id, u.name, u.email, u.phone, u.job_title, u.timezone,'
            . ' u.notification_prefs, u.avatar_path, u.active, u.must_change_password,'
            . ' u.password_changed_at, u.last_login_at, u.created_by, u.version,'
            . ' u.created_at, u.updated_at, r.code AS role_code, r.name AS role_name'
            . ' FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Usuario de prueba inexistente: ' . $userId);
        }

        foreach (['id', 'role_id', 'active', 'must_change_password', 'version'] as $intColumn) {
            $row[$intColumn] = (int) $row[$intColumn];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function createClient(int $createdBy, array $overrides = []): array
    {
        $type = (string) ($overrides['type'] ?? 'NORMAL');
        $suffix = self::uniqueSuffix();

        $data = [
            'type' => $type,
            'name' => $overrides['name'] ?? ('Cliente ' . $suffix),
            'contact_name' => $overrides['contact_name'] ?? 'Contacto ' . $suffix,
            'representative_name' => $overrides['representative_name'] ?? ($type === 'BANDA' ? 'Representante ' . $suffix : null),
            'phone' => $overrides['phone'] ?? ($type === 'BANDA' ? '555000' . random_int(1000, 9999) : null),
            'email' => $overrides['email'] ?? ('cliente.' . $suffix . '@test.local'),
            'address' => $overrides['address'] ?? null,
            'notes' => $overrides['notes'] ?? null,
            'active' => $overrides['active'] ?? 1,
        ];

        $statement = $this->pdo->prepare(
            'INSERT INTO clients (type, name, contact_name, representative_name, phone, email, address, notes, active, created_by, version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $statement->execute([
            $data['type'],
            $data['name'],
            $data['contact_name'],
            $data['representative_name'],
            $data['phone'],
            $data['email'],
            $data['address'],
            $data['notes'],
            $data['active'],
            $createdBy,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->clientRow($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function clientRow(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Cliente de prueba inexistente: ' . $id);
        }

        foreach (['id', 'active', 'version'] as $intColumn) {
            $row[$intColumn] = (int) $row[$intColumn];
        }

        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];

        return $row;
    }

    /**
     * Emite un token opaco real (hash guardado, claro devuelto una vez).
     *
     * @return array{id: int, token: string}
     */
    public function createToken(int $userId, string $name = 'dispositivo-test'): array
    {
        $plain = TokenService::generateToken();

        $statement = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $name,
            TokenService::hash($plain),
            gmdate('Y-m-d H:i:s', time() + 86400),
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'token' => $plain];
    }

    /**
     * @return array{token_id: int, token_hash: string, revoked_at: ?string}
     */
    public function tokenRow(int $tokenId): array
    {
        $statement = $this->pdo->prepare('SELECT id, token_hash, revoked_at FROM api_tokens WHERE id = ? LIMIT 1');
        $statement->execute([$tokenId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Token de prueba inexistente: ' . $tokenId);
        }

        return [
            'token_id' => (int) $row['id'],
            'token_hash' => (string) $row['token_hash'],
            'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        ];
    }

    /**
     * Reserva futura confirmada para un cliente BANDA.
     *
     * Necesaria para §7: no se desactiva ni se convierte a NORMAL un cliente con
     * reservas futuras activas. La ocupación es la fuente de verdad (§12).
     *
     * @return array{occupancy_id: int, reservation_id: int, sale_id: int}
     */
    public function createFutureReservation(int $bandClientId, int $responsibleId): array
    {
        $suffix = self::uniqueSuffix();
        $start = gmdate('Y-m-d H:i:s', time() + 2 * 86400);
        $end = gmdate('Y-m-d H:i:s', time() + 2 * 86400 + 7200);

        $space = $this->pdo->prepare('INSERT INTO spaces (name, hourly_rate) VALUES (?, ?)');
        $space->execute(['Sala ' . $suffix, '500.00']);
        $spaceId = (int) $this->pdo->lastInsertId();

        $occupancy = $this->pdo->prepare(
            "INSERT INTO space_occupancies (space_id, start_at, end_at, kind, status) VALUES (?, ?, ?, 'RESERVATION', 'CONFIRMED')"
        );
        $occupancy->execute([$spaceId, $start, $end]);
        $occupancyId = (int) $this->pdo->lastInsertId();

        $sale = $this->pdo->prepare(
            "INSERT INTO sales (folio, type, client_id, responsible_id, status, issued_at, total)"
            . " VALUES (?, 'RESERVA', ?, ?, 'EMITIDA', ?, '500.00')"
        );
        $sale->execute(['VTA-TEST-' . $suffix, $bandClientId, $responsibleId, gmdate('Y-m-d H:i:s')]);
        $saleId = (int) $this->pdo->lastInsertId();

        $reservation = $this->pdo->prepare(
            'INSERT INTO reservations (occupancy_id, band_client_id, sale_id, responsible_id, hourly_rate_snapshot, total, status)'
            . " VALUES (?, ?, ?, ?, '500.00', '500.00', 'CONFIRMED')"
        );
        $reservation->execute([$occupancyId, $bandClientId, $saleId, $responsibleId]);

        return [
            'occupancy_id' => $occupancyId,
            'reservation_id' => (int) $this->pdo->lastInsertId(),
            'sale_id' => $saleId,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditLogs(): array
    {
        $statement = $this->pdo->query('SELECT * FROM audit_logs ORDER BY id ASC');
        $rows = $statement === false ? [] : $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}
