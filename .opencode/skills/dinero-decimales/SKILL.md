---
name: Dinero y decimales exactos
description: Reglas de aritmética monetaria con BCMath, redondeo por partida, importes como string en JSON, fórmula de venta con extras y plan de contingencia en centavos enteros. Cárgala antes de calcular cualquier importe.
---

# Dinero y decimales exactos

## Regla cero

**Nunca `float` para dinero.** Ni `round()`, ni `number_format()` sobre floats intermedios, ni
`+` entre strings. Un céntimo perdido rompe la conciliación del dashboard.

## Representación

| Capa | Representación |
|---|---|
| Base de datos | `DECIMAL(12,2)` |
| PHP | string decimal operado con BCMath |
| JSON | string, `"150.00"` |
| JS/Android | string; solo se formatea para mostrar |

Escala monetaria: **2** decimales. Escala de cantidades: **3**.

## Funciones base

```php
final class Money
{
    public const SCALE = 2;

    public static function add(string $a, string $b): string
    { return bcadd($a, $b, self::SCALE); }

    public static function sub(string $a, string $b): string
    { return bcsub($a, $b, self::SCALE); }

    public static function mul(string $a, string $b): string
    { return self::round(bcmul($a, $b, self::SCALE + 4)); }

    public static function div(string $a, string $b): string
    { return self::round(bcdiv($a, $b, self::SCALE + 4)); }

    /** Redondeo comercial half-up, alejado de cero. */
    public static function round(string $value): string
    {
        if ($value[0] === '-') {
            return '-' . self::round(substr($value, 1));
        }
        $result = bcadd($value, '0.' . str_repeat('0', self::SCALE) . '5', self::SCALE);
        return $result;
    }

    public static function format(string $v): string
    { return bcadd($v, '0', self::SCALE); }
}
```

- Comparar con `bccomp($a, $b, 2)`, nunca con `==`.
- Verificar `0` con `bccomp($v, '0.00', 2) === 0`.
- `Money` es la **única** implementación. Prohibido duplicarla en un servicio o en JS.

## Fórmula de partida de venta (§10)

```
importe_partida = cantidad_producto × (precio_base + Σ(cantidad_extra_por_unidad × precio_extra))
```

- Redondeo **al final de cada partida**, no por extra ni por multiplicación intermedia.
- `total = Σ importes_partida` (con dos decimales ya redondeados).
- Ejemplo vinculante: `2 × (50.00 + 1 × 10.00) = 120.00`.
- Si un producto lleva un extra distinto por unidad, son **partidas separadas**. No se promedian.

```php
$base = Money::add('50.00', Money::mul('1', '10.00'));   // 60.00
$line = Money::mul('2', $base);                          // 120.00
```

## Precios históricos

Al emitir, se copian a la partida `name_snapshot`, `unit_price` y, por extra, `name_snapshot`,
`quantity_per_unit`, `unit_price`. Cambiar el catálogo **no** altera documentos emitidos. El
snapshot se congela en la emisión, no al crear el borrador (pero el borrador recalcula del catálogo
vigente mientras no se emita).

## Reservas

`total = tarifa_hora × minutos / 60`, redondeando **una sola vez al final**. Bloques de 30 min:
`minutos = 30 × bloques`. Nunca acumules redondeos por bloque.

## Saldos y estados

```
neto_cobrado = Σ pagos válidos - Σ reembolsos efectivos
NO_PAGADO  si neto = 0
PARCIAL    si 0 < neto < total
PAGADO     si neto = total
SIN_CARGO  si total = 0 (sin asiento ficticio)
sobrepago  → rechazar (409 OVERPAYMENT) en esta versión
```

Comparaciones siempre con `bccomp(..., 2)`.

## Contingencia: centavos enteros

Si el hosting no tiene `bcmath`:

- Se opera con enteros en centavos (`int`), misma clase `Money` como fachada.
- Para cantidades fraccionarias, multiplicar cantidad por 1000 y dividir al final con redondeo
  half-up explícito.
- **Una sola** implementación, con pruebas de los mismos casos. No conviven dos motores.
- Se documenta el cambio en `docs/decisiones.md` antes de tocar código.

## Pruebas obligatorias

- `2 × (50 + 10) = 120.00`.
- `1000 - 300 = 700.00` y estado PARCIAL.
- `0.005` redondea a `0.01` (half-up).
- `0.10 + 0.20 = 0.30` exacto.
- Reserva de 90 min a `$250/hora = 375.00`.
- Total cero → `SIN_CARGO`, sin pago.
