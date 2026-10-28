<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\ApiException;
use App\Core\Request;

final class Version
{
    public static function fromRequest(Request $request): int
    {
        $values = [];
        foreach (['version', 'expected_version'] as $key) {
            if (array_key_exists($key, $request->body)) {
                $values[] = self::parse($request->body[$key]);
            }
        }
        if ($request->header('if-match') !== null) {
            if (preg_match('/\A"([1-9][0-9]*)"\z/', $request->header('if-match'), $m) !== 1) {
                throw ApiException::validation(['version' => 'If-Match debe contener una versión entre comillas.']);
            }
            $values[] = self::parse($m[1]);
        }
        if ($values === [] || count(array_unique($values)) !== 1) {
            throw ApiException::validation(['version' => 'Envía una versión esperada coherente.']);
        }
        return $values[0];
    }

    public static function parse(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value))
            || preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $value) !== 1
            || (int) $value > 4294967295) {
            throw ApiException::validation(['version' => 'La versión debe ser un entero positivo válido.']);
        }
        return (int) $value;
    }

    public static function assert(array $row, int $expected): void
    {
        if ((int) $row['version'] !== $expected) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'El registro cambió. Recárgalo antes de continuar.');
        }
    }
}
