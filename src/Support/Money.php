<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\ApiException;

/** Aritmética monetaria exacta; BCMath verificado como requisito del despliegue. */
final class Money
{
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 2);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 2);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 2);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, 6));
    }

    public static function round(string $value): string
    {
        return str_starts_with($value, '-')
            ? '-' . self::round(substr($value, 1)) : bcadd($value, '0.005', 2);
    }

    public static function amount(string $value): string
    {
        if (bccomp($value, '0', 2) < 0 || bccomp($value, '9999999999.99', 2) > 0) {
            throw ApiException::validation(['amount' => 'El importe excede el rango permitido.']);
        }
        return bcadd($value, '0', 2);
    }
}
