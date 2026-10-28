<?php

declare(strict_types=1);

namespace App\Tests\Unit\Policies;

use App\Auth\AuthContext;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Policies\Role;
use PHPUnit\Framework\TestCase;

/** §5, §10, §12, §16: autorización de reservas por rol y objeto, sin BD. */
final class ReservationAuthorizerTest extends TestCase
{
    private static function policy(?string $role, mixed $id = 7, mixed $active = 1): Authorizer
    {
        $context = new AuthContext();
        if ($role !== null) {
            $context->setUser([
                'id' => $id,
                'role_code' => $role,
                'active' => $active,
            ], AuthContext::METHOD_SESSION);
        }

        return new Authorizer($context);
    }

    public function test_view_and_manage_require_admin_or_own_responsibility(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $policy = self::policy($role);
            foreach (['CONFIRMADA', 'EN_CURSO', 'FINALIZADA', 'CANCELADA'] as $status) {
                foreach ([7, '7', 99, '99'] as $owner) {
                    $reservation = ['id' => '42', 'responsible_id' => $owner, 'status' => $status];
                    $expected = $role === Role::ADMINISTRADOR || (string) $owner === '7';
                    self::assertSame($expected, $policy->canViewReservation($reservation));
                    self::assertSame($expected, $policy->canManageReservation($reservation));
                }
            }
            self::assertFalse($policy->canViewReservation(null));
            self::assertFalse($policy->canManageReservation(null));
            self::assertFalse($policy->canCancelReservation(null));
            self::assertSame($role === Role::ADMINISTRADOR, $policy->canManageSpaces());
        }
    }

    public function test_D23_cancel_is_admin_only_even_for_own_unpaid_reservation(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $policy = self::policy($role);
            foreach ([7, 99] as $owner) {
                self::assertSame($role === Role::ADMINISTRADOR, $policy->canCancelReservation([
                    'id' => 42,
                    'responsible_id' => $owner,
                    'status' => 'CONFIRMADA',
                    'net_paid' => '0.00',
                ]));
            }
        }
    }

    public function test_missing_responsible_never_grants_ownership_but_admin_can_view_existing_row(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            foreach ([['id' => '42'], ['id' => '42', 'responsible_id' => null]] as $reservation) {
                $policy = self::policy($role);
                self::assertSame($role === Role::ADMINISTRADOR, $policy->canViewReservation($reservation));
                self::assertSame($role === Role::ADMINISTRADOR, $policy->canManageReservation($reservation));
            }
        }
    }

    public function test_anonymous_unknown_role_inactive_and_invalid_actor_are_denied(): void
    {
        $policies = [self::policy(null), self::policy('UNKNOWN')];
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            foreach ([false, 0, '0', null, 'true', 2] as $active) {
                $policies[] = self::policy($role, 7, $active);
            }
            foreach ([null, false, 0, '0', '07', '7abc', 7.0, ' 7', '7e0'] as $id) {
                $policies[] = self::policy($role, $id);
            }
        }
        foreach ($policies as $policy) {
            $reservation = ['id' => '42', 'responsible_id' => 7];
            self::assertFalse($policy->canViewReservation($reservation));
            self::assertFalse($policy->canManageReservation($reservation));
            self::assertFalse($policy->canCancelReservation($reservation));
            self::assertFalse($policy->canManageSpaces());
        }
    }

    public function test_ids_are_canonical_and_never_truncated_or_coerced(): void
    {
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $policy = self::policy($role, '7', '1');
            foreach ([null, true, false, 7.0, '07', '7abc', '+7', '7 ', '7.0', '7e0', [], '0'] as $owner) {
                $reservation = ['id' => '42', 'responsible_id' => $owner, 'registered_by' => '7'];
                self::assertFalse($policy->canViewReservation($reservation));
                self::assertFalse($policy->canManageReservation($reservation));
            }
            // Mayor que PHP_INT_MAX: IDs vecinos no colapsan por un cast a int.
            $policy = self::policy($role, '18446744073709551614', true);
            self::assertTrue($policy->canViewReservation(['responsible_id' => '18446744073709551614']));
            self::assertTrue($policy->canManageReservation(['responsible_id' => '18446744073709551614']));
            self::assertFalse($policy->canViewReservation(['responsible_id' => '18446744073709551615']));
            self::assertFalse($policy->canManageReservation(['responsible_id' => '18446744073709551615']));
        }
    }

    public function test_occupancy_capability_does_not_grant_foreign_detail_or_finance(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            $policy = self::policy($role);
            self::assertTrue($policy->can(Capabilities::SPACES_VIEW_OCCUPANCY));
            self::assertSame($role === Role::ADMINISTRADOR, $policy->canViewReservation([
                'id' => 42,
                'responsible_id' => 99,
                'registered_by' => 7,
            ]));
        }
    }
}
