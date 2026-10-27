---
name: Pagos e idempotencia
description: Ciclo de vida de pagos y reembolsos, estados calculados, transacciones sin sobrepago, claves de idempotencia y reglas contables del dashboard. Cárgala al implementar cobros, anulaciones o finanzas.
---

# Pagos e idempotencia

## Un solo módulo de pagos

Un pago pertenece **exactamente** a una venta **o** a un proyecto cobrable. Nunca a ambos, nunca a
una reserva directamente (la reserva se cobra por su venta). Restricción en BD + validación en
servicio.

```sql
CHECK ((project_id IS NULL) <> (sale_id IS NULL))
CHECK (amount > 0)
```

## Estados calculados

```
neto = Σ pagos con status = 'VALID'  -  Σ reembolsos efectivos
```

| Condición | Estado |
|---|---|
| `total = 0` | `SIN_CARGO` (sin asiento ficticio) |
| `neto = 0` y `total > 0` | `NO_PAGADO` |
| `0 < neto < total` | `PARCIAL` |
| `neto = total` | `PAGADO` |

Nunca se guarda "pagado" a mano. Si el estado se persiste por rendimiento, es una caché que se
recalcula en la misma transacción y se puede reconstruir.

**Sobrepago:** `neto + importe > total` → `409 OVERPAYMENT`. No se admite en esta versión.

## Anular ≠ reembolsar

| Operación | Significado | Efecto |
|---|---|---|
| **Anular** (`status = 'VOID'`) | Error de registro | El pago deja de contar; queda con motivo y auditoría |
| **Reembolso** | Devolución real de dinero | Se cuenta como salida; límite = importe no reembolsado |

- Un pago anulado no se borra ni se edita: se marca y se conserva.
- Un reembolso referencia el pago original y no puede exceder lo no reembolsado.
- Ambas son solo ADMIN.
- El historial se conserva íntegro; el dashboard las considera por fecha efectiva.

## Idempotencia

Toda escritura con dinero o bloqueo de espacio:

```
Idempotency-Key: <uuid v4 generado por el cliente>
```

Algoritmo, **todo dentro de la transacción del efecto**:

1. `SELECT ... FOR UPDATE` en `idempotency_requests` por (`actor_id`, `operation`, `key`).
2. Existe y `request_hash` igual → devolver `response_json` y `status` guardados (mismo código HTTP).
3. Existe y `request_hash` distinto → `409 IDEMPOTENCY_KEY_REUSED`.
4. No existe → ejecutar el efecto, guardar fila con el resultado serializado, `COMMIT`.

- `request_hash` = hash del cuerpo canónico + operación + id de ruta.
- La clave caduca por antigüedad (propuesta: 24 h) mediante limpieza; nunca se reutiliza una clave
  con contenido distinto.
- El frontend **reutiliza** la misma clave al reintentar el mismo envío; genera una nueva solo
  cuando el usuario cambia los datos.

## Registrar un pago (transacción completa)

1. `BEGIN`.
2. Bloquear el documento: `SELECT ... FROM sales|projects WHERE id = ? FOR UPDATE`.
3. Verificar policy (rol + objeto) y estado del documento (no cancelado).
4. Verificar idempotencia (pasos arriba).
5. Calcular `neto` actual y validar `neto + importe <= total`.
6. Insertar pago, calcular nuevo estado, actualizar documento (`version + 1`).
7. Insertar notificación y auditoría.
8. Guardar respuesta idempotente, `COMMIT`.

Dos peticiones simultáneas del mismo importe: la segunda espera el bloqueo, recalcula y falla con
`OVERPAYMENT` o devuelve la respuesta idempotente. **Nunca** ambas tienen éxito.

## Reglas del dashboard (§11)

| Concepto | Fecha que se usa |
|---|---|
| Importe comercial de ventas | `issued_at` (emisión) |
| Importe de proyectos | emisión/registro del proyecto, no el cobro |
| Cobros reales | `effective_at` del pago (no `created_at`) |
| Pendientes a fecha de corte | operaciones hasta esa fecha |
| Gastos | `paid_at` si están pagados; separado si pendientes |

- `resultado de caja = cobros netos - gastos pagados`. **No** es utilidad contable.
- Cada documento se suma una vez: una reserva cuenta como venta, no como ingreso extra.
- Nunca se vincula una venta a un proyecto para duplicar el mismo cobro.
- Valor interno estimado se presenta **aparte**: no es cobro ni cuenta por cobrar.
- Anulaciones y reembolsos afectan los indicadores con trazabilidad, sin doble conteo.
- Sin IVA, descuentos ni tasas inventadas.

## Pruebas obligatorias

- `1000` con `300` → PARCIAL, saldo `700`; luego `700` → PAGADO.
- Misma `Idempotency-Key` + mismo cuerpo → una sola fila de pago y misma respuesta.
- Misma clave + cuerpo distinto → `409`.
- Dos cobros simultáneos que exceden el total → uno pasa, el otro `409`.
- Anular un pago devuelve el documento a PARCIAL/NO_PAGADO sin borrar historial.
- Reembolso mayor a lo pagado → `422`/`409`.
- Total `0.00` → `SIN_CARGO`, sin fila en `payments`.
- Pago de una venta de septiembre cobrado en octubre aparece en octubre en cobros y en septiembre en
  importe comercial.
