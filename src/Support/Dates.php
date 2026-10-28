<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\ApiException;

final class Dates
{
    public static function date(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $m) !== 1
            || (int) $m[1] < 1000 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ApiException::validation([$field => 'Usa una fecha real YYYY-MM-DD o null.']);
        }
        return $value;
    }

    public static function range(?string $start, ?string $end): void
    {
        if ($start !== null && $end !== null && $start > $end) {
            throw ApiException::validation(['dates' => 'El inicio no puede ser posterior al fin.']);
        }
    }

    public static function within(?string $start, ?string $end, ?string $parentStart, ?string $parentEnd): void
    {
        self::range($start, $end);
        foreach ([$start, $end] as $date) {
            if ($date !== null && (($parentStart !== null && $date < $parentStart)
                || ($parentEnd !== null && $date > $parentEnd))) {
                throw ApiException::validation(['dates' => 'Las fechas deben quedar dentro de las del padre.']);
            }
        }
    }
}
