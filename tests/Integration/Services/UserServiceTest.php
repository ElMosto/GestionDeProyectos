<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Auth\AuditLogger;
use App\Auth\TokenService;
use App\Core\ApiException;
use App\Policies\Role;
use App\Services\UserService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\InProcessApi;
use App\Validation\UserValidation;

/**
 * Administración de cuentas (§5, §6, §19.A).
 *
 * Todo se ejerce a través de `UserService`, con la base real: transacciones,
 * protección del último administrador, revocación de tokens y auditoría.
 */
final class UserServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'ClaveInicial123!';

    private function userService(): UserService
    {
        /** @var UserService $service */
        $service = $this->service(UserService::class);

        return $service;
    }

    private function audit(): AuditLogger
    {
        /** @var AuditLogger $audit */
        $audit = $this->service(AuditLogger::class);

        return $audit;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createInput(array $overrides = []): array
    {
        return UserValidation::create(array_merge([
            'name' => 'Persona Nueva',
            'email' => 'persona.' . \App\Tests\Support\Fixtures::uniqueSuffix() . '@test.local',
            'role' => Role::COLABORADOR,
            'password' => self::PASSWORD,
            'job_title' => 'Productor',
        ], $overrides), 12);
    }

    public function test_A_created_user_is_normalized_flagged_and_never_exposes_the_hash(): void
    {
        // §19.A / §5 — el administrador crea la cuenta; el correo se normaliza y la
        // contraseña es temporal (cambio obligatorio en el primer acceso).
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);

        $created = $this->userService()->create(
            $this->createInput(['name' => '  Persona Nueva  ', 'email' => '  Persona.Nueva@TEST.Local ']),
            $admin['id']
        );

        $this->assertSame('persona.nueva@test.local', $created['email']);
        $this->assertSame('Persona Nueva', $created['name']);
        $this->assertTrue($created['must_change_password']);
        $this->assertTrue($created['active']);
        $this->assertSame(Role::COLABORADOR, $created['role']['code']);

        $this->assertNoSecrets($created, [self::PASSWORD], 'la ficha del usuario creado');
        $this->assertNoSecrets($this->fixtures->auditLogs(), [self::PASSWORD], 'audit_logs');

        $statement = $this->pdo->prepare('SELECT password_hash, must_change_password FROM users WHERE id = ?');
        $statement->execute([(int) $created['id']]);
        $row = $statement->fetch();

        $this->assertIsArray($row);
        $this->assertNotSame(self::PASSWORD, $row['password_hash']);
        $this->assertTrue(password_verify(self::PASSWORD, (string) $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);
    }

    public function test_A_duplicate_email_is_rejected_as_conflict(): void
    {
        // §5 — el correo es único; repetirlo devuelve 409, no crea otra cuenta.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $this->fixtures->createUser(Role::COLABORADOR, 'repetido@test.local');

        $exception = $this->assertApiException(
            fn () => $this->userService()->create(
                $this->createInput(['email' => 'REPETIDO@test.local']),
                $admin['id']
            ),
            409,
            'EMAIL_TAKEN'
        );

        $this->assertSame('EMAIL_TAKEN', $exception->errorCode);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'repetido@test.local'")->fetchColumn());
    }

    public function test_A_role_change_is_audited_with_before_and_after(): void
    {
        // §19.A / §16 — cambiar el rol es operación de alto impacto y queda auditada.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $target = $this->fixtures->createUser(Role::COLABORADOR);
        $this->audit()->bindRequest('req-role-change', '127.0.0.1');

        $updated = $this->userService()->update(
            $target['id'],
            UserValidation::update(['version' => 1, 'role' => Role::LIDER_PROYECTO]),
            ['id' => $admin['id']]
        );

        $this->assertSame(Role::LIDER_PROYECTO, $updated['role']['code']);

        $rows = $this->pdo->query(
            "SELECT * FROM audit_logs WHERE action = 'ROLE_CHANGE' AND entity_type = 'user' ORDER BY id DESC LIMIT 1"
        )->fetchAll();

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame((int) $target['id'], (int) $row['entity_id']);
        $this->assertSame((int) $admin['id'], (int) $row['actor_id']);
        $this->assertSame('req-role-change', $row['request_id']);

        $before = json_decode((string) $row['before_json'], true);
        $after = json_decode((string) $row['after_json'], true);

        $this->assertSame(Role::COLABORADOR, $before['role_code']);
        $this->assertSame(Role::LIDER_PROYECTO, $after['role_code']);
    }

    public function test_A_self_role_change_is_forbidden(): void
    {
        // §6 — el formulario de perfil nunca cambia el rol; el endpoint administrativo
        // tampoco permite que un administrador se degrade a sí mismo.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $other = $this->fixtures->createUser(Role::ADMINISTRADOR);

        $this->assertApiException(
            fn () => $this->userService()->update(
                $other['id'],
                UserValidation::update(['version' => 1, 'role' => Role::COLABORADOR]),
                ['id' => $other['id']]
            ),
            403,
            'FORBIDDEN'
        );

        $roleId = (int) $this->pdo->query('SELECT role_id FROM users WHERE id = ' . (int) $other['id'])->fetchColumn();
        $this->assertSame($this->fixtures->roleId(Role::ADMINISTRADOR), $roleId);
        $this->assertNotSame($admin['id'], $other['id']);
    }

    public function test_A_last_administrator_cannot_be_deactivated(): void
    {
        // §19.A — «no desactivar ni quitar privilegios al último administrador activo».
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);

        $this->assertApiException(
            fn () => $this->userService()->deactivate($admin['id'], $admin['id']),
            409,
            'LAST_ADMIN_PROTECTED'
        );

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT active FROM users WHERE id = ' . $admin['id'])->fetchColumn()
        );
    }

    public function test_A_last_administrator_cannot_be_downgraded(): void
    {
        // §19.A / §5 — degradar al último admin deja el sistema sin gestión de cuentas.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $lider = $this->fixtures->createUser(Role::LIDER_PROYECTO);
        $colaboradorRoleId = $this->fixtures->roleId(Role::COLABORADOR);

        $this->assertApiException(
            fn () => $this->userService()->update(
                $admin['id'],
                ['fields' => [], 'role_id' => $colaboradorRoleId, 'role_code' => null, 'expected_version' => 1],
                ['id' => $lider['id']]
            ),
            409,
            'LAST_ADMIN_PROTECTED'
        );

        $this->assertSame(
            $this->fixtures->roleId(Role::ADMINISTRADOR),
            (int) $this->pdo->query('SELECT role_id FROM users WHERE id = ' . $admin['id'])->fetchColumn()
        );
    }

    public function test_A_a_second_administrator_can_be_downgraded(): void
    {
        // §5 — la protección es del *último* administrador, no de todos.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $second = $this->fixtures->createUser(Role::ADMINISTRADOR);

        $updated = $this->userService()->update(
            $second['id'],
            UserValidation::update(['version' => 1, 'role' => Role::COLABORADOR]),
            ['id' => $admin['id']]
        );

        $this->assertSame(Role::COLABORADOR, $updated['role']['code']);
    }

    public function test_A_deactivation_revokes_tokens_and_reactivation_restores_access(): void
    {
        // §19.A — «desactivar cuenta invalida acceso»: el token deja de valer en la
        // siguiente petición, incluso si la cuenta se reactiva después.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $target = $this->fixtures->createUser(Role::COLABORADOR);
        $token = $this->fixtures->createToken($target['id']);
        $second = $this->fixtures->createToken($target['id']);

        $this->assertSame(200, InProcessApi::get('/api/v1/auth/me', [], $token['token'])->status);

        $deactivated = $this->userService()->deactivate($target['id'], $admin['id']);

        $this->assertFalse($deactivated['active']);
        $this->assertNotNull($this->fixtures->tokenRow($token['id'])['revoked_at']);
        $this->assertNotNull($this->fixtures->tokenRow($second['id'])['revoked_at']);
        $this->assertSame(401, InProcessApi::get('/api/v1/auth/me', [], $token['token'])->status);

        $reactivated = $this->userService()->reactivate($target['id'], $admin['id']);

        $this->assertTrue($reactivated['active']);
        $this->assertSame(401, InProcessApi::get('/api/v1/auth/me', [], $token['token'])->status, 'Un token revocado no revive al reactivar la cuenta.');
    }

    public function test_A_deactivation_is_idempotent_and_audited_once(): void
    {
        // §5 — la baja es lógica; repetirla no falla ni duplica auditoría.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $target = $this->fixtures->createUser(Role::COLABORADOR);

        $this->userService()->deactivate($target['id'], $admin['id']);
        $this->userService()->deactivate($target['id'], $admin['id']);

        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'USER_DEACTIVATE' AND entity_id = " . (int) $target['id']
        )->fetchColumn();

        $this->assertSame(1, (int) $count);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . $target['id'])->fetchColumn(), 'La cuenta no se borra físicamente.');
    }

    public function test_A_password_reset_hands_out_the_temporary_key_only_once(): void
    {
        // §6 — clave temporal de un solo uso: viaja en la respuesta, nunca se guarda
        // en claro ni en la auditoría, y obliga a cambiarla.
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $target = $this->fixtures->createUser(Role::COLABORADOR);
        $token = $this->fixtures->createToken($target['id']);
        $this->audit()->bindRequest('req-reset', '127.0.0.1');

        $first = $this->userService()->resetPassword($target['id'], $admin['id']);
        $temporary = $first['temporary_password'];

        $this->assertIsString($temporary);
        $this->assertSame(20, strlen($temporary));
        $this->assertTrue($first['user']['must_change_password']);
        $this->assertNoSecrets($first['user'], [$temporary], 'la respuesta del restablecimiento');

        $statement = $this->pdo->prepare('SELECT password_hash, must_change_password FROM users WHERE id = ?');
        $statement->execute([$target['id']]);
        $row = $statement->fetch();

        $this->assertNotSame($temporary, $row['password_hash']);
        $this->assertTrue(password_verify($temporary, (string) $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);

        $this->assertNotNull($this->fixtures->tokenRow($token['id'])['revoked_at'], 'Emitir una clave temporal revoca los tokens.');

        $pending = $this->pdo->query(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ' . (int) $target['id'] . ' AND used_at IS NULL'
        )->fetchColumn();
        $this->assertSame(1, (int) $pending);

        // La clave en claro no aparece en ninguna fila de auditoría.
        $auditJson = (string) json_encode($this->fixtures->auditLogs(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($temporary, $auditJson);

        // Un segundo restablecimiento entrega otra clave distinta.
        $second = $this->userService()->resetPassword($target['id'], $admin['id']);
        $this->assertNotSame($temporary, $second['temporary_password']);
    }

    public function test_A_user_listing_never_serializes_the_hash(): void
    {
        // §16 — el listado administrativo usa lista blanca de campos y pagina.
        $this->fixtures->createUser(Role::ADMINISTRADOR);
        $this->fixtures->createUser(Role::LIDER_PROYECTO);
        $this->fixtures->createUser(Role::COLABORADOR);

        $result = $this->userService()->list([
            'q' => null,
            'role' => null,
            'active' => null,
            'limit' => 25,
            'offset' => 0,
            'sort' => 'name',
            'direction' => 'asc',
        ]);

        $this->assertCount(3, $result['data']);
        $this->assertSame(3, $result['meta']['total']);
        $this->assertNoSecrets($result['data'], [], 'el listado de usuarios');

        foreach ($result['data'] as $row) {
            $this->assertArrayNotHasKey('password_hash', $row);
        }
    }
}
