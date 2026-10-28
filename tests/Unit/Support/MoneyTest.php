<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** §8, §10, §19.B/D — exact decimals, independent of MariaDB. */
final class MoneyTest extends TestCase
{
    public function test_B_decimal_addition_does_not_use_float(): void
    {
        self::assertSame('0.30', Money::add('0.10', '0.20'));
        self::assertSame('1500.00', Money::add('1000.00', '500.00'));
        self::assertSame('0.30', Money::mul('3.000', '0.10'));
    }

    public function test_D_two_units_with_extra_total_one_hundred_twenty(): void
    {
        self::assertSame('120.00', Money::mul('2.000', Money::add('50.00', Money::mul('1.000', '10.00'))));
    }

    public function test_D_subtraction_and_comparison_are_exact(): void
    {
        self::assertSame('700.00', Money::sub('1000.00', '300.00'));
        self::assertSame(0, Money::compare('0.30', '0.30'));
        self::assertSame(-1, Money::compare('300.00', '1000.00'));
    }

    public static function roundingCases(): array
    {
        return [
            'half up' => ['0.005', '0.01'],
            'below half' => ['0.004999', '0.00'],
            'negative half away from zero' => ['-0.005', '-0.01'],
            'carry' => ['999.995', '1000.00'],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_B_rounding_is_half_up_at_line_boundary(string $input, string $expected): void
    {
        self::assertSame($expected, Money::round($input));
    }

    public function test_B_two_rounded_lines_are_not_rounded_as_one_line(): void
    {
        $line = Money::mul('0.500', '0.01');
        self::assertSame('0.01', $line);
        self::assertSame('0.02', Money::add($line, $line));
    }
}
