<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Catálogo cerrado de roles globales (§2.1, §5, D-01).
 * "Usuario" es la cuenta; "cliente" nunca es un rol ni una cuenta.
 */
final class Role
{
    public const ADMINISTRADOR = 'ADMINISTRADOR';
    public const LIDER_PROYECTO = 'LIDER_PROYECTO';
    public const COLABORADOR = 'COLABORADOR';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ADMINISTRADOR, self::LIDER_PROYECTO, self::COLABORADOR];
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::all(), true);
    }

    public static function isAdmin(?string $code): bool
    {
        return $code === self::ADMINISTRADOR;
    }

    /**
     * Un rol global no concede acceso por objeto: liderazgo, membresía,
     * responsabilidad y propiedad se comprueban siempre aparte (§5).
     */
    public static function requiresObjectCheck(?string $code): bool
    {
        return $code === self::LIDER_PROYECTO || $code === self::COLABORADOR;
    }
}
