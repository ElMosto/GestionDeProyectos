<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class SaleMoneyTest extends TestCase
{
    public function test_D_dos_productos_con_extra_por_unidad_totalizan_120(): void
    {
        self::assertSame('120.00', Money::saleLineTotal('2.000', '50.00', [
            ['quantity_per_unit' => '1.000', 'unit_price' => '10.00'],
        ]));
    }

    public function test_D_no_redondea_el_extra_antes_de_multiplicar_la_partida(): void
    {
        // §10: 2 × (0 + .5 × .01) = .01; redondear el extra antes produciría .02.
        self::assertSame('0.01', Money::saleLineTotal('2.000', '0.00', [
            ['quantity_per_unit' => '0.500', 'unit_price' => '0.01'],
        ]));
    }

    public function test_D_redondea_cada_partida_antes_de_sumar_el_total(): void
    {
        $line = Money::saleLineTotal('0.500', '0.01', []);
        self::assertSame('0.01', $line);
        self::assertSame('0.02', Money::add($line, $line));
    }

    public function test_D_suma_decimal_exacta_y_redondeo_half_up(): void
    {
        self::assertSame('0.30', Money::add('0.10', '0.20'));
        self::assertSame('0.01', Money::round('0.005'));
        self::assertSame('700.00', Money::sub('1000.00', '300.00'));
    }
}
