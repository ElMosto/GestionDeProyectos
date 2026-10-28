<?php

declare(strict_types=1);

namespace App\Tests\Unit\Policies;

use App\Policies\Capabilities;
use App\Policies\Role;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Matriz rol → capacidades (§5, §19.A).
 *
 * Fuente de verdad de la matriz: `.opencode/skills/permisos-rbac/references/matriz-permisos.md`
 * y `docs/seguridad.md` §8.
 */
final class CapabilitiesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function declaredCapabilities(): array
    {
        $values = [];

        foreach ((new ReflectionClass(Capabilities::class))->getConstants() as $name => $value) {
            if (is_string($value) && preg_match('/^[a-z]+(\.[a-zA-Z]+)+$/', $value) === 1) {
                $values[$value] = true;
            }
        }

        $capabilities = array_keys($values);
        sort($capabilities);

        return $capabilities;
    }

    public function test_A_admin_has_every_capability_of_the_catalogue(): void
    {
        // §19.A — el administrador gestiona cuentas, configuración y todas las operaciones.
        $map = Capabilities::forRole(Role::ADMINISTRADOR);

        $this->assertNotEmpty($map);
        $this->assertNotContains(false, $map, 'El administrador debe tener concedida toda la matriz.');

        foreach (Capabilities::all() as $capability) {
            $this->assertTrue($map[$capability], 'Capacidad no concedida al administrador: ' . $capability);
        }
    }

    public function test_A_lider_has_no_account_management_deactivation_or_global_finance(): void
    {
        // §19.A / §5 — operaciones de alto impacto y dashboard global son solo del administrador.
        $forbidden = [
            Capabilities::USERS_MANAGE,
            Capabilities::AUDIT_VIEW,
            Capabilities::SETTINGS_MANAGE,
            Capabilities::CLIENTS_DEACTIVATE,
            Capabilities::CLIENTS_EDIT_ANY,
            Capabilities::CLIENTS_VIEW_SENSITIVE,
            Capabilities::CLIENTS_CONVERT,
            Capabilities::FINANCE_DASHBOARD,
            Capabilities::EXPENSES_VIEW,
            Capabilities::EXPENSES_MANAGE,
            Capabilities::CATALOG_MANAGE,
            Capabilities::PAYMENTS_VOID,
            Capabilities::PAYMENTS_REFUND,
            Capabilities::SALES_CANCEL_WITHOUT_PAYMENTS_ANY,
            Capabilities::SALES_CANCEL_WITHOUT_PAYMENTS_OWN,
            Capabilities::SALES_CANCEL_WITH_PAYMENTS,
            Capabilities::SPACES_MANAGE,
            Capabilities::RESERVATIONS_RETROACTIVE,
            Capabilities::PROJECTS_VIEW_ANY,
            Capabilities::PROJECTS_MANAGE_ANY,
            Capabilities::PROJECTS_VIEW_FINANCE_ANY,
            Capabilities::TASKS_CREATE_STANDALONE,
        ];

        foreach ($forbidden as $capability) {
            $this->assertFalse(
                Capabilities::roleHas(Role::LIDER_PROYECTO, $capability),
                'LIDER_PROYECTO no debe tener ' . $capability
            );
        }
    }

    public function test_A_colaborador_cannot_see_sensitive_client_data_or_edit_any_client(): void
    {
        // §19.A / §7 — el colaborador ve el directorio mínimo, no datos sensibles ni edición general.
        $forbidden = [
            Capabilities::CLIENTS_VIEW_SENSITIVE,
            Capabilities::CLIENTS_EDIT_ANY,
            Capabilities::CLIENTS_DEACTIVATE,
            Capabilities::CLIENTS_CONVERT,
            Capabilities::PROJECTS_CREATE,
            Capabilities::TASKS_CREATE_STANDALONE,
            Capabilities::SALES_CANCEL_WITHOUT_PAYMENTS_OWN,
            Capabilities::RESERVATIONS_CANCEL_OWN,
            Capabilities::USERS_MANAGE,
            Capabilities::FINANCE_DASHBOARD,
        ];

        foreach ($forbidden as $capability) {
            $this->assertFalse(
                Capabilities::roleHas(Role::COLABORADOR, $capability),
                'COLABORADOR no debe tener ' . $capability
            );
        }

        $allowed = [
            Capabilities::CLIENTS_CREATE,
            Capabilities::CLIENTS_VIEW,
            Capabilities::CLIENTS_EDIT_OWN,
            Capabilities::CATALOG_VIEW,
            Capabilities::SALES_CREATE,
            Capabilities::RESERVATIONS_CREATE,
            Capabilities::AGENDA_VIEW_OWN,
            Capabilities::USERS_DIRECTORY,
        ];

        foreach ($allowed as $capability) {
            $this->assertTrue(
                Capabilities::roleHas(Role::COLABORADOR, $capability),
                'COLABORADOR debe tener ' . $capability
            );
        }
    }

    public function test_A_there_is_no_capability_outside_the_closed_catalogue(): void
    {
        // §5 — deny by default: la matriz no inventa capacidades ni deja huecos.
        $declared = self::declaredCapabilities();
        $exposed = Capabilities::all();

        $this->assertSame($declared, $exposed, 'Hay capacidades declaradas que no están en la matriz, o al revés.');

        foreach (Role::all() as $role) {
            $this->assertSame($declared, array_keys(Capabilities::forRole($role)));
        }
    }

    public function test_A_unknown_or_absent_role_gets_nothing(): void
    {
        // §5 — deny by default ante un rol desconocido o sin identidad.
        foreach ([null, '', 'SUPERADMIN', 'admin', Role::ADMINISTRADOR . ' '] as $unknown) {
            $map = Capabilities::forRole($unknown);

            $this->assertNotEmpty($map);
            $this->assertNotContains(true, $map, 'Un rol desconocido no debe recibir ninguna capacidad.');
        }

        $this->assertFalse(Capabilities::roleHas(Role::ADMINISTRADOR, 'invented.capability'));
        $this->assertFalse(Capabilities::roleHas(null, Capabilities::USERS_MANAGE));
    }

    public function test_A_matrix_cells_match_the_documented_permission_table(): void
    {
        // §5 + matriz-permisos.md — celdas verificables de la tabla documentada.
        $expectations = [
            // capacidad => [ADMIN, LIDER, COLABORADOR]
            Capabilities::USERS_DIRECTORY => [true, true, true],
            Capabilities::CLIENTS_CREATE => [true, true, true],
            Capabilities::CLIENTS_VIEW => [true, true, true],
            Capabilities::CLIENTS_EDIT_OWN => [true, true, true],
            Capabilities::CLIENTS_EDIT_ANY => [true, false, false],
            Capabilities::CLIENTS_DEACTIVATE => [true, false, false],
            Capabilities::CATALOG_VIEW => [true, true, true],
            Capabilities::CATALOG_MANAGE => [true, false, false],
            Capabilities::PROJECTS_CREATE => [true, true, false],
            // El líder ve proyectos propios, no cualquier proyecto por membresía (§5).
            Capabilities::PROJECTS_VIEW_MEMBER => [true, false, true],
            Capabilities::PROJECTS_VIEW_FINANCE_OWN => [true, true, false],
            Capabilities::TASKS_UPDATE_ASSIGNED => [true, true, true],
            Capabilities::TASKS_CREATE_STANDALONE => [true, false, false],
            Capabilities::PAYMENTS_REGISTER_PROJECT => [true, true, false],
            Capabilities::PAYMENTS_VOID => [true, false, false],
            // D-18 / §10: cancelar exige ADMIN incluso sin pagos.
            Capabilities::SALES_CANCEL_WITHOUT_PAYMENTS_OWN => [true, false, false],
            Capabilities::SALES_CANCEL_WITHOUT_PAYMENTS_ANY => [true, false, false],
            Capabilities::SALES_CANCEL_WITH_PAYMENTS => [true, false, false],
            Capabilities::RESERVATIONS_CANCEL_OWN => [true, true, false],
            Capabilities::SPACES_MANAGE => [true, false, false],
            Capabilities::MEETINGS_CREATE => [true, true, true],
            Capabilities::NOTIFICATIONS_VIEW => [true, true, true],
            Capabilities::FINANCE_DASHBOARD => [true, false, false],
        ];

        $roles = [Role::ADMINISTRADOR, Role::LIDER_PROYECTO, Role::COLABORADOR];

        foreach ($expectations as $capability => [$admin, $lider, $colaborador]) {
            $this->assertSame($admin, Capabilities::roleHas($roles[0], $capability), 'ADMINISTRADOR / ' . $capability);
            $this->assertSame($lider, Capabilities::roleHas($roles[1], $capability), 'LIDER_PROYECTO / ' . $capability);
            $this->assertSame($colaborador, Capabilities::roleHas($roles[2], $capability), 'COLABORADOR / ' . $capability);
        }
    }
}
