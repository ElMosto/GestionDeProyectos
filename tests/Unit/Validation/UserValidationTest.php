<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validation;

use App\Core\ApiException;
use App\Validation\UserValidation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validación de usuarios (§5, §6, §15, §16).
 */
final class UserValidationTest extends TestCase
{
    private const MIN_PASSWORD = 12;

    /**
     * @return array<string, mixed>
     */
    private static function validCreatePayload(): array
    {
        return [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@test.local',
            'role' => 'COLABORADOR',
            'password' => 'ClaveSegura123!',
        ];
    }

    /**
     * @param callable():mixed $action
     */
    private function capture(callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $exception) {
            return $exception;
        }

        $this->fail('Se esperaba una ApiException y no se lanzó.');
    }

    public function test_A_create_normalizes_email_to_lowercase(): void
    {
        // §5 / §16 — el correo es el identificador de acceso y se guarda normalizado.
        $input = UserValidation::create([
            'name' => 'Usuario Correo',
            'email' => '  Usuario.Mayusculas@TEST.Local ',
            'role' => 'COLABORADOR',
            'password' => 'ClaveSegura123!',
        ], self::MIN_PASSWORD);

        $this->assertSame('usuario.mayusculas@test.local', $input['email']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEmails(): array
    {
        return [
            'sin arroba' => ['sin-arroba'],
            'vacío' => [''],
            'espacios' => ['   '],
            'inyección' => ["admin' OR 1=1 --"],
            'dominio ausente' => ['usuario@'],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function test_A_create_rejects_invalid_email(string $email): void
    {
        // §5 — correo único y válido.
        $exception = $this->capture(static fn () => UserValidation::create(
            array_merge(self::validCreatePayload(), ['email' => $email]),
            self::MIN_PASSWORD
        ));

        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey('email', $exception->fields);
    }

    public function test_A_create_rejects_unknown_role_code(): void
    {
        // §5 / D-01 — catálogo cerrado de tres roles.
        $exception = $this->capture(static fn () => UserValidation::create(
            array_merge(self::validCreatePayload(), ['role' => 'SUPERADMIN']),
            self::MIN_PASSWORD
        ));

        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey('role_id', $exception->fields);
    }

    public function test_A_create_requires_a_role(): void
    {
        // §5 — el administrador asigna un rol; sin rol no hay cuenta.
        $payload = self::validCreatePayload();
        unset($payload['role']);

        $exception = $this->capture(static fn () => UserValidation::create($payload, self::MIN_PASSWORD));

        $this->assertArrayHasKey('role_id', $exception->fields);
    }

    public function test_A_create_enforces_the_minimum_password_length(): void
    {
        // §6 — contraseña mínima configurada (12 en este proyecto).
        $exception = $this->capture(static fn () => UserValidation::create(
            array_merge(self::validCreatePayload(), ['password' => 'corta']),
            self::MIN_PASSWORD
        ));

        $this->assertArrayHasKey('password', $exception->fields);
    }

    public function test_A_create_rejects_read_only_fields(): void
    {
        // §16 — asignación masiva: estado, hash, versión y fechas no vienen del cliente.
        $exception = $this->capture(static fn () => UserValidation::create(
            array_merge(self::validCreatePayload(), [
                'id' => 1,
                'active' => 0,
                'must_change_password' => 0,
                'password_hash' => 'x',
                'version' => 5,
                'created_at' => '2020-01-01 00:00:00',
            ]),
            self::MIN_PASSWORD
        ));

        foreach (['id', 'active', 'must_change_password', 'password_hash', 'version', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $exception->fields, 'Debe rechazarse ' . $field);
        }
    }

    public function test_A_create_defaults_timezone_and_accepts_valid_iana(): void
    {
        // §6 — zona horaria IANA solo para presentación.
        $default = UserValidation::create(self::validCreatePayload(), self::MIN_PASSWORD);
        $this->assertSame('America/Mexico_City', $default['timezone']);

        $invalid = $this->capture(static fn () => UserValidation::create(
            array_merge(self::validCreatePayload(), ['timezone' => 'Marte/Olimpo']),
            self::MIN_PASSWORD
        ));
        $this->assertArrayHasKey('timezone', $invalid->fields);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidVersions(): array
    {
        return [
            'ausente' => [null],
            'cero' => [0],
            'negativa' => [-1],
            'texto' => ['siete'],
            'vacía' => [''],
        ];
    }

    #[DataProvider('invalidVersions')]
    public function test_A_update_requires_a_positive_version(mixed $version): void
    {
        // §15 — bloqueo optimista también en administración de cuentas.
        $body = ['name' => 'Nombre'];

        if ($version !== null) {
            $body['version'] = $version;
        }

        $exception = $this->capture(static fn () => UserValidation::update($body));

        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey('version', $exception->fields);
    }

    public function test_A_update_rejects_email_password_and_state(): void
    {
        // §6 / §16 — el correo y la contraseña no se cambian desde la edición
        // administrativa; el estado tiene su propia operación.
        $exception = $this->capture(static fn () => UserValidation::update([
            'version' => 1,
            'email' => 'otro@test.local',
            'password' => 'ClaveSegura123!',
            'password_hash' => 'x',
            'active' => 0,
            'id' => 3,
        ]));

        foreach (['email', 'password', 'password_hash', 'active', 'id'] as $field) {
            $this->assertArrayHasKey($field, $exception->fields, 'Debe rechazarse ' . $field);
        }
    }

    public function test_A_update_accepts_a_role_change_by_code(): void
    {
        // §5 — el cambio de rol es una operación administrativa explícita y auditada.
        $result = UserValidation::update(['version' => '2', 'role' => 'LIDER_PROYECTO']);

        $this->assertNull($result['role_id']);
        $this->assertSame('LIDER_PROYECTO', $result['role_code']);
        $this->assertSame(2, $result['expected_version']);
        $this->assertSame([], $result['fields']);
    }

    public function test_A_update_rejects_unknown_role_and_mismatched_pair(): void
    {
        // §5 — rol inexistente o id que no corresponde al código: 422.
        $unknown = $this->capture(static fn () => UserValidation::update(['version' => 1, 'role' => 'DIOS']));
        $this->assertArrayHasKey('role_id', $unknown->fields);

        $invalidId = $this->capture(static fn () => UserValidation::update(['version' => 1, 'role_id' => 'abc']));
        $this->assertArrayHasKey('role_id', $invalidId->fields);
    }

    public function test_A_list_query_rejects_unknown_role_and_clamps_limit(): void
    {
        // §15 — filtro de rol validado contra el catálogo; límite acotado a 100.
        $exception = $this->capture(static fn () => UserValidation::listQuery(['role' => 'SUPERADMIN']));
        $this->assertArrayHasKey('role', $exception->fields);

        $query = UserValidation::listQuery(['limit' => '500', 'sort' => 'password_hash']);
        $this->assertSame(100, $query['limit']);
        $this->assertSame('name', $query['sort']);
    }

    public function test_A_user_id_rejects_non_numeric_values(): void
    {
        // §15 — id inválido es 404, no 500.
        $this->assertSame(3, UserValidation::id('3'));

        foreach (['x', '0', '-2', '1 OR 1=1'] as $raw) {
            $exception = $this->capture(static fn () => UserValidation::id($raw));
            $this->assertSame(404, $exception->status);
        }
    }
}
