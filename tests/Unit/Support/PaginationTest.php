<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paginación y orden de listados (§15).
 */
final class PaginationTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function limits(): array
    {
        return [
            'ausente' => [null, 25],
            'entero' => [50, 50],
            'cadena' => ['50', 50],
            'cero' => [0, 25],
            'negativo' => [-10, 25],
            'texto' => ['todas', 25],
            'por encima del máximo' => [101, 100],
            'muy por encima' => ['100000', 100],
            'flotante' => [2.5, 25],
            'booleano' => [true, 25],
        ];
    }

    #[DataProvider('limits')]
    public function test_limit_defaults_to_25_and_caps_at_100(mixed $value, int $expected): void
    {
        // §15 — limit 25 por defecto, 100 como máximo.
        $this->assertSame($expected, Pagination::limit($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function offsets(): array
    {
        return [
            'ausente' => [null, 0],
            'entero' => [25, 25],
            'cadena' => ['25', 25],
            'negativo' => [-1, 0],
            'texto' => ['inicio', 0],
            'enorme' => [5000000, 1_000_000],
        ];
    }

    #[DataProvider('offsets')]
    public function test_offset_is_never_negative_and_is_bounded(mixed $value, int $expected): void
    {
        // §15 — offset acotado para no permitir consultas abusivas.
        $this->assertSame($expected, Pagination::offset($value));
    }

    public function test_direction_only_accepts_asc_or_desc(): void
    {
        // §15 — dirección ascendente por defecto.
        $this->assertSame('asc', Pagination::direction(null));
        $this->assertSame('asc', Pagination::direction('ASC'));
        $this->assertSame('desc', Pagination::direction(' desc '));
        $this->assertSame('asc', Pagination::direction('random'));
        $this->assertSame('asc', Pagination::direction(['desc']));
        $this->assertSame('desc', Pagination::direction(null, 'desc'));
    }

    public function test_sort_only_accepts_the_whitelist(): void
    {
        // §15 / §16 — el orden nunca se concatena: se elige de una lista blanca.
        $allowed = ['name', 'created_at', 'updated_at'];

        $this->assertSame('created_at', Pagination::sort('created_at', $allowed, 'name'));
        $this->assertSame('name', Pagination::sort('password_hash', $allowed, 'name'));
        $this->assertSame('name', Pagination::sort('name; DROP TABLE users', $allowed, 'name'));
        $this->assertSame('name', Pagination::sort("' OR 1=1 --", $allowed, 'name'));
        $this->assertSame('name', Pagination::sort('Name', $allowed, 'name'));
        $this->assertSame('name', Pagination::sort(null, $allowed, 'name'));
        $this->assertSame('name', Pagination::sort(['name'], $allowed, 'name'));
    }
}
