<?php

declare(strict_types=1);

namespace App\Tests\Unit\Policies;

use App\Policies\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Catálogo cerrado de roles (§2.1, §5, D-01).
 */
final class RoleTest extends TestCase
{
    public function test_A_catalogue_has_exactly_three_roles(): void
    {
        // §19.A — «el administrador crea los tres roles»: el catálogo no admite un cuarto.
        $this->assertSame(
            ['ADMINISTRADOR', 'LIDER_PROYECTO', 'COLABORADOR'],
            Role::all()
        );
        $this->assertCount(3, Role::all());
    }

    public function test_A_every_catalogue_code_is_valid(): void
    {
        foreach (Role::all() as $role) {
            $this->assertTrue(Role::isValid($role));
        }
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function invalidRoleCodes(): array
    {
        return [
            'minúsculas' => ['administrador'],
            'capitalizado' => ['Administrador'],
            'cliente como rol' => ['CLIENTE'],
            'banda como rol' => ['BANDA'],
            'vacío' => [''],
            'espacio final' => [Role::ADMINISTRADOR . ' '],
            'nulo' => [null],
            'rol inventado' => ['SUPERADMIN'],
        ];
    }

    #[DataProvider('invalidRoleCodes')]
    public function test_A_unknown_role_codes_are_rejected(?string $code): void
    {
        // §5 — «usuario» es la cuenta, «cliente» nunca es un rol.
        $this->assertFalse(Role::isValid($code));
    }

    public function test_A_is_admin_only_accepts_the_administrator_code(): void
    {
        $this->assertTrue(Role::isAdmin(Role::ADMINISTRADOR));
        $this->assertFalse(Role::isAdmin(Role::LIDER_PROYECTO));
        $this->assertFalse(Role::isAdmin(Role::COLABORADOR));
        $this->assertFalse(Role::isAdmin(null));
    }

    public function test_A_object_check_is_required_for_non_admin_roles(): void
    {
        // §5 — un rol global no concede acceso por objeto: liderazgo y membresía se
        // comprueban aparte. El administrador tiene acceso global.
        $this->assertFalse(Role::requiresObjectCheck(Role::ADMINISTRADOR));
        $this->assertTrue(Role::requiresObjectCheck(Role::LIDER_PROYECTO));
        $this->assertTrue(Role::requiresObjectCheck(Role::COLABORADOR));
        $this->assertFalse(Role::requiresObjectCheck(null));
    }
}
