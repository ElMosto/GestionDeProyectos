<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalización de parámetros de listado (§15): `limit` 25 por defecto y 100 como
 * máximo, `offset` acotado, orden por lista blanca y dirección ascendente por defecto.
 *
 * Los valores inválidos no rompen la petición: se sustituyen por el valor por
 * defecto, salvo los filtros tipados que valida cada `Validation`.
 */
final class Pagination
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 100;
    public const MAX_OFFSET = 1_000_000;

    public static function limit(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($value, self::MAX_LIMIT);
    }

    public static function offset(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 0) {
            return 0;
        }

        return min($value, self::MAX_OFFSET);
    }

    public static function direction(mixed $value, string $default = 'asc'): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'asc' => 'asc',
            'desc' => 'desc',
            default => $default,
        };
    }

    /**
     * @param list<string> $allowed Claves lógicas permitidas
     */
    public static function sort(mixed $value, array $allowed, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
