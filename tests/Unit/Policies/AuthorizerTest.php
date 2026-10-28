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

    public function test_D_sale_relationship_and_states_are_authorized_independently(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR, null] as $role) {
            $policy = self::authorizer($role);
            foreach (['7', 7, '99', null, '7abc'] as $responsible) {
                foreach (['BORRADOR', 'EMITIDA', 'CANCELADA'] as $status) {
                    foreach (['ORDINARIA', 'RESERVA'] as $type) {
                        $sale = ['id' => 1, 'responsible_id' => $responsible, 'status' => $status, 'type' => $type];
                        $visible = $role === Role::ADMINISTRADOR
                            || ($role !== null && in_array($responsible, [7, '7'], true));
                        $editable = $visible && $status === 'BORRADOR' && $type === 'ORDINARIA';
                        $this->assertSame($visible, $policy->canViewSale($sale));
                        $this->assertSame($visible, $policy->canViewSaleFinance($sale));
                        $this->assertSame($editable, $policy->canManageSale($sale));
                        $this->assertSame($editable, $policy->canIssueSale($sale));
                        $this->assertSame($role === Role::ADMINISTRADOR, $policy->canCancelSale($sale));
                        $this->assertSame($visible && $status === 'EMITIDA', $policy->canRegisterSalePayment($sale));
                    }
                }
            }
        }
    }

    public function test_D_project_payments_require_billable_owned_finance_not_membership_or_registrant(): void
    {
        // §11: FINALIZADO aún puede tener saldo; CANCELADO no recibe nuevos pagos.
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR, null] as $role) {
            $policy = self::authorizer($role);
            foreach ([7, '7', 99, null, true, '7abc'] as $leader) {
                foreach ([true, 1, '1', false, 0, '0', 'true', 1.0, null] as $billable) {
                    foreach (['BORRADOR', 'PLANIFICADO', 'EN_CURSO', 'PAUSADO', 'FINALIZADO', 'CANCELADO'] as $status) {
                        $project = ['leader_id' => $leader, 'billable' => $billable, 'status' => $status,
                            'is_member' => true, 'registered_by' => 7];
                        $finance = $role === Role::ADMINISTRADOR
                            || ($role === Role::LIDER_PROYECTO && in_array($leader, [7, '7'], true));
                        $this->assertSame(
                            $finance && in_array($billable, [true, 1, '1'], true) && $status !== 'CANCELADO',
                            $policy->canRegisterProjectPayment($project)
                        );
                    }
                }
            }
        }
    }

    public function test_D_null_inactive_missing_identity_and_malformed_ids_deny_sale_and_project_payments(): void
    {
        $sale = ['responsible_id' => '7', 'status' => 'EMITIDA', 'type' => 'ORDINARIA'];
        $project = ['leader_id' => '7', 'billable' => 1, 'status' => 'FINALIZADO'];
        $saleMethods = ['canViewSale', 'canViewSaleFinance', 'canManageSale', 'canIssueSale', 'canCancelSale', 'canRegisterSalePayment'];
        $contexts = [self::authorizer(null)];
        foreach ([0, false, '0', null] as $active) {
            $context = new AuthContext();
            $context->setUser(['id' => '7', 'active' => $active, 'role_code' => Role::ADMINISTRADOR], AuthContext::METHOD_TOKEN);
            $contexts[] = new Authorizer($context);
        }
        foreach ([null, true, 7.0, '07', '7abc'] as $id) {
            $context = new AuthContext();
            $context->setUser(['id' => $id, 'active' => 1, 'role_code' => Role::ADMINISTRADOR], AuthContext::METHOD_SESSION);
            $contexts[] = new Authorizer($context);
        }
        foreach ($contexts as $policy) {
            foreach ($saleMethods as $method) {
                $this->assertFalse($policy->$method($sale));
            }
            $this->assertFalse($policy->canRegisterProjectPayment($project));
        }
        $admin = self::authorizer(Role::ADMINISTRADOR);
        foreach ($saleMethods as $method) {
            $this->assertFalse($admin->$method(null));
        }
        $this->assertFalse($admin->canRegisterProjectPayment(null));
        $this->assertFalse($admin->canRegisterProjectPayment(['billable' => 1]));
        $this->assertFalse($admin->canManageSale(['status' => 'BORRADOR']));
        $this->assertFalse($admin->canRegisterSalePayment(array_replace($sale, ['type' => 'UNKNOWN'])));
        $owner = self::authorizer(Role::COLABORADOR);
        foreach ([true, 7.0, '07', '7abc', '9223372036854775808'] as $id) {
            $this->assertFalse($owner->canViewSale(array_replace($sale, ['responsible_id' => $id])));
        }
        // BIGINT nunca se trunca a PHP_INT_MAX al comparar responsabilidades.
        $context = new AuthContext();
        $context->setUser(['id' => '9223372036854775808', 'active' => 1, 'role_code' => Role::COLABORADOR], AuthContext::METHOD_TOKEN);
        $big = new Authorizer($context);
        $this->assertTrue($big->canViewSale(array_replace($sale, ['responsible_id' => '9223372036854775808'])));
        $this->assertFalse($big->canViewSale(array_replace($sale, ['responsible_id' => '9223372036854775809'])));
    }

    public function test_B_project_visibility_and_finance_require_the_correct_relationship(): void
    {
        $owned = ['id' => '12', 'leader_id' => '7', 'is_member' => false];
        $foreign = ['id' => '13', 'leader_id' => '99', 'is_member' => true];
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR, null] as $role) {
            $policy = self::authorizer($role);
            $this->assertSame(in_array($role, [Role::ADMINISTRADOR, Role::LIDER_PROYECTO], true), $policy->canViewProject($owned));
            $this->assertSame(in_array($role, [Role::ADMINISTRADOR, Role::COLABORADOR], true), $policy->canViewProject($foreign));
            foreach (['canManageProject', 'canViewProjectFinance'] as $method) {
                $this->assertSame(in_array($role, [Role::ADMINISTRADOR, Role::LIDER_PROYECTO], true), $policy->$method($owned));
                $this->assertSame($role === Role::ADMINISTRADOR, $policy->$method($foreign));
                $this->assertFalse($policy->$method(null));
            }
        }
    }

    public function test_B_membership_must_be_a_trusted_boolean_not_a_payload_string(): void
    {
        $policy = self::authorizer(Role::COLABORADOR);
        foreach ([null, false, 0, 1, '1', 'true', []] as $membership) {
            $this->assertFalse($policy->canViewProject(['leader_id' => '99', 'is_member' => $membership]));
        }
        $this->assertTrue($policy->canViewProject(['leader_id' => '99', 'is_member' => true]));
    }

    public function test_B_independent_task_assignee_can_update_progress_but_not_scope(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $policy = self::authorizer($role);
            $own = ['id' => '1', 'stage_id' => null, 'assignee_id' => '7'];
            $other = ['id' => '2', 'stage_id' => null, 'assignee_id' => '99'];
            $this->assertTrue($policy->canViewTask($own));
            $this->assertTrue($policy->canUpdateTaskProgress($own));
            $this->assertSame($role === Role::ADMINISTRADOR, $policy->canManageTask($own));
            $this->assertSame($role === Role::ADMINISTRADOR, $policy->canViewTask($other));
            $this->assertSame($role === Role::ADMINISTRADOR, $policy->canUpdateTaskProgress($other));
        }
    }

    public function test_B_project_task_visibility_does_not_grant_scope_or_other_assignees_progress(): void
    {
        $project = ['id' => '12', 'leader_id' => '99', 'is_member' => true];
        $own = ['id' => '1', 'stage_id' => '5', 'assignee_id' => '7'];
        $other = ['id' => '2', 'stage_id' => '5', 'assignee_id' => '99'];
        $policy = self::authorizer(Role::COLABORADOR);
        $this->assertTrue($policy->canViewTask($own, $project));
        $this->assertTrue($policy->canViewTask($other, $project));
        $this->assertTrue($policy->canUpdateTaskProgress($own, $project));
        $this->assertFalse($policy->canUpdateTaskProgress($other, $project));
        $this->assertFalse($policy->canManageTask($own, $project));
        $this->assertFalse($policy->canUpdateTaskProgress($own, array_replace($project, ['is_member' => false])));
        $leader = self::authorizer(Role::LIDER_PROYECTO, 99);
        $this->assertTrue($leader->canManageTask($own, $project));
        $this->assertTrue($leader->canUpdateTaskProgress($other, $project));
    }

    public function test_B_missing_task_parent_inactive_actor_and_malformed_ids_are_denied(): void
    {
        $project = ['id' => '12', 'leader_id' => '7', 'is_member' => true];
        $task = ['id' => '1', 'stage_id' => '5', 'assignee_id' => '7'];
        foreach ([self::authorizer(Role::ADMINISTRADOR), self::authorizer(null)] as $policy) {
            $this->assertFalse($policy->canViewTask($task));
            $this->assertFalse($policy->canManageTask($task));
            $this->assertFalse($policy->canUpdateTaskProgress($task));
            $this->assertFalse($policy->canViewTask(['assignee_id' => 7], $project));
            $this->assertFalse($policy->canViewTask(null, $project));
        }
        $context = new AuthContext();
        $context->setUser(['id' => '7', 'active' => 0, 'role_code' => Role::ADMINISTRADOR], AuthContext::METHOD_TOKEN);
        $inactive = new Authorizer($context);
        $this->assertFalse($inactive->canViewProject($project));
        $this->assertFalse($inactive->canViewTask($task, $project));
        $leader = self::authorizer(Role::LIDER_PROYECTO);
        foreach (['7abc', true, 7.5, '07', null] as $id) {
            $this->assertFalse($leader->canManageProject(array_replace($project, ['leader_id' => $id])));
        }
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
