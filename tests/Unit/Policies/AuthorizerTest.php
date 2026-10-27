<?php

declare(strict_types=1);

namespace App\Tests\Unit\Policies;

use App\Auth\AuthContext;
use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Policies\Role;
use PHPUnit\Framework\TestCase;

/**
 * Autorización por rol **y** por objeto (§5, §16, §19.A).
 *
 * Se usa un `AuthContext` real, no un mock: es el mismo objeto que rellena el
 * middleware en cada petición.
 */
final class AuthorizerTest extends TestCase
{
    private const ACTOR_ID = 7;
    private const OTHER_USER_ID = 99;

    private static function authorizer(?string $roleCode, int $userId = self::ACTOR_ID): Authorizer
    {
        $context = new AuthContext();

        if ($roleCode !== null) {
            $context->setUser([
                'id' => $userId,
                'role_id' => 1,
                'role_code' => $roleCode,
                'name' => 'Actor de prueba',
                'email' => 'actor@test.local',
                'active' => 1,
                'must_change_password' => 0,
                'version' => 1,
            ], AuthContext::METHOD_SESSION);
        }

        return new Authorizer($context);
    }

    /**
     * @return array<string, mixed>
     */
    private static function client(?int $createdBy): array
    {
        return [
            'id' => 10,
            'type' => 'NORMAL',
            'name' => 'Cliente de prueba',
            'contact_name' => 'Contacto',
            'representative_name' => null,
            'phone' => '5550000000',
            'email' => 'privado@test.local',
            'address' => 'Calle 1',
            'notes' => 'Notas privadas',
            'active' => 1,
            'created_by' => $createdBy,
            'version' => 1,
        ];
    }

    public function test_A_administrator_sees_sensitive_fields_of_any_client(): void
    {
        // §7 — ADMIN ve notas y dirección de cualquier cliente.
        $authorizer = self::authorizer(Role::ADMINISTRADOR);

        $this->assertTrue($authorizer->canViewSensitiveClient(self::client(self::ACTOR_ID)));
        $this->assertTrue($authorizer->canViewSensitiveClient(self::client(self::OTHER_USER_ID)));
        $this->assertTrue($authorizer->canViewSensitiveClient(self::client(null)));
    }

    public function test_A_non_admin_sees_sensitive_fields_only_of_own_clients(): void
    {
        // §7 — «el creador puede corregir datos de contacto de sus registros»;
        // conocer un ID ajeno no otorga acceso a notas ni dirección.
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $authorizer = self::authorizer($role);

            $this->assertTrue($authorizer->canViewSensitiveClient(self::client(self::ACTOR_ID)), $role . ' / propio');
            $this->assertFalse($authorizer->canViewSensitiveClient(self::client(self::OTHER_USER_ID)), $role . ' / ajeno');
            $this->assertFalse($authorizer->canViewSensitiveClient(self::client(null)), $role . ' / sin creador');
        }
    }

    public function test_A_view_client_is_directory_visibility_for_every_active_role(): void
    {
        // §7 — todos los usuarios activos consultan el directorio mínimo, también
        // de clientes ajenos. El recorte de campos lo hace el serializador.
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $authorizer = self::authorizer($role);

            $this->assertTrue($authorizer->canViewClient(self::client(self::OTHER_USER_ID)), $role);
            $this->assertFalse($authorizer->canViewClient(null), $role . ' / cliente inexistente');
        }

        $this->assertFalse(self::authorizer(null)->canViewClient(self::client(self::ACTOR_ID)));
    }

    public function test_A_edit_client_requires_admin_or_being_the_creator(): void
    {
        // §7 — cambios generales solo ADMIN; el creador corrige contacto de lo suyo.
        $admin = self::authorizer(Role::ADMINISTRADOR);
        $this->assertTrue($admin->canEditClient(self::client(self::OTHER_USER_ID)));

        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $authorizer = self::authorizer($role);

            $this->assertTrue($authorizer->canEditClient(self::client(self::ACTOR_ID)), $role . ' / propio');
            $this->assertFalse($authorizer->canEditClient(self::client(self::OTHER_USER_ID)), $role . ' / ajeno');
            $this->assertFalse($authorizer->canEditClient(self::client(null)), $role . ' / sin creador');
        }

        $this->assertFalse(self::authorizer(null)->canEditClient(self::client(self::ACTOR_ID)));
    }

    public function test_A_only_admin_can_deactivate_a_client(): void
    {
        // §5 / §7 — la baja es operación de alto impacto: ser el creador no autoriza.
        $this->assertTrue(self::authorizer(Role::ADMINISTRADOR)->canDeactivateClient(self::client(self::OTHER_USER_ID)));
        $this->assertTrue(self::authorizer(Role::ADMINISTRADOR)->canDeactivateClient(self::client(self::ACTOR_ID)));

        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $this->assertFalse(self::authorizer($role)->canDeactivateClient(self::client(self::ACTOR_ID)), $role);
        }

        $this->assertFalse(self::authorizer(Role::ADMINISTRADOR)->canDeactivateClient(null));
    }

    public function test_A_unauthenticated_authorizer_denies_everything(): void
    {
        // §16 — sin identidad no hay permisos: la respuesta correcta es 401.
        $authorizer = self::authorizer(null);

        $this->assertFalse($authorizer->can(Capabilities::CLIENTS_VIEW));
        $this->assertFalse($authorizer->canViewClient(self::client(null)));
        $this->assertFalse($authorizer->canViewSensitiveClient(self::client(self::ACTOR_ID)));
        $this->assertFalse($authorizer->canEditClient(self::client(self::ACTOR_ID)));
        $this->assertFalse($authorizer->canDeactivateClient(self::client(self::ACTOR_ID)));
        $this->assertFalse($authorizer->isAdmin());

        $exception = $this->captureApiException(static fn () => $authorizer->requireUser());

        $this->assertSame(401, $exception->status);
        $this->assertSame('UNAUTHENTICATED', $exception->errorCode);

        $capabilityException = $this->captureApiException(
            static fn () => $authorizer->requireCapability(Capabilities::CLIENTS_VIEW)
        );

        $this->assertSame(401, $capabilityException->status);
    }

    public function test_A_require_capability_returns_403_for_a_role_without_it(): void
    {
        // §16 — 403 FORBIDDEN, nunca «existe pero no puedes».
        $colaborador = self::authorizer(Role::COLABORADOR);

        $exception = $this->captureApiException(
            static fn () => $colaborador->requireCapability(Capabilities::USERS_MANAGE)
        );

        $this->assertSame(403, $exception->status);
        $this->assertSame('FORBIDDEN', $exception->errorCode);
    }

    public function test_A_require_admin_rejects_every_non_admin_role(): void
    {
        // §5 — operaciones de alto impacto: solo ADMINISTRADOR, sin excepciones implícitas.
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $exception = $this->captureApiException(
                static fn () => self::authorizer($role)->requireAdmin()
            );

            $this->assertSame(403, $exception->status);
        }

        self::authorizer(Role::ADMINISTRADOR)->requireAdmin();
        $this->addToAssertionCount(1);
    }

    public function test_A_capabilities_map_reflects_the_authenticated_role(): void
    {
        // La matriz viaja al cliente solo como información de UI.
        $colaborador = self::authorizer(Role::COLABORADOR)->capabilities();

        $this->assertTrue($colaborador[Capabilities::CLIENTS_CREATE]);
        $this->assertFalse($colaborador[Capabilities::USERS_MANAGE]);
        $this->assertFalse($colaborador[Capabilities::FINANCE_DASHBOARD]);

        $anonymous = self::authorizer(null)->capabilities();
        $this->assertNotContains(true, $anonymous);
    }

    public function test_A_project_and_sale_object_policies_deny_by_default(): void
    {
        // §5 / docs/seguridad.md §8.2 — hasta que exista la relación real (fase 2-3),
        // las políticas por objeto de proyecto y venta devuelven false, también para
        // el administrador. Un `true` aquí sin comprobar el objeto sería el fallo.
        $project = ['id' => 1, 'leader_id' => self::ACTOR_ID, 'client_id' => 5, 'base_amount' => '1000.00'];
        $sale = ['id' => 1, 'responsible_id' => self::ACTOR_ID, 'status' => 'BORRADOR'];

        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR, null] as $role) {
            $authorizer = self::authorizer($role);

            $this->assertFalse($authorizer->canViewProject($project), (string) $role);
            $this->assertFalse($authorizer->canManageProject($project), (string) $role);
            $this->assertFalse($authorizer->canViewProjectFinance($project), (string) $role);
            $this->assertFalse($authorizer->canManageSale($sale), (string) $role);
        }

        $admin = self::authorizer(Role::ADMINISTRADOR);
        $this->assertFalse($admin->canViewProject(null));
        $this->assertFalse($admin->canManageSale(null));
    }

    public function test_A_auth_context_never_exposes_the_password_hash(): void
    {
        // §16 — defensa en profundidad: aunque un repositorio lo devolviera, no se propaga.
        $context = new AuthContext();
        $context->setUser(['id' => 1, 'role_id' => 1, 'role_code' => Role::ADMINISTRADOR, 'password_hash' => '$2y$12$abc'], 'session');

        $this->assertArrayNotHasKey('password_hash', (array) $context->user());
    }

    private function captureApiException(callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $exception) {
            return $exception;
        }

        $this->fail('Se esperaba una ApiException y no se lanzó ninguna.');
    }
}
