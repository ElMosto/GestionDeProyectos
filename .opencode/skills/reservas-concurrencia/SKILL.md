---
name: Reservas y concurrencia de espacios
description: Modelo de ocupación de espacios, intervalos semiabiertos, bloqueo FOR UPDATE sin interbloqueos, estados bloqueantes, reprogramación y cancelación coordinada con la venta. Cárgala al tocar reservas, bloqueos o reuniones con espacio.
---

# Reservas y concurrencia de espacios

## Una sola fuente de verdad

`space_occupancies` representa **todo** lo que ocupa un espacio: reservas, bloqueos por
mantenimiento y reuniones que usan el estudio. Toda validación de conflicto se hace contra esta
tabla, no contra `reservations`.

```
space_occupancies(id, space_id, start_at, end_at, kind, status)
  kind: RESERVATION | MAINTENANCE | MEETING
```

`reservations.occupancy_id` y `space_blocks.occupancy_id` son `UNIQUE`. La fila de ocupación se crea
y se modifica siempre junto al documento que la origina, en la misma transacción.

## Intervalos semiabiertos

Se usa `[inicio, fin)`: una reserva de 10:00 a 11:00 permite otra a las 11:00.

```sql
-- Conflicto si hay solapamiento real, no contacto en el borde
WHERE space_id = :space
  AND status IN ('CONFIRMED','IN_PROGRESS')   -- estados bloqueantes
  AND start_at < :new_end
  AND end_at   > :new_start
```

Nunca usar `BETWEEN` ni `<=`/`>=` en los bordes: eso bloquea horarios adyacentes.

## Bloqueo obligatorio

Una consulta de disponibilidad **no** evita la concurrencia. Todas las rutas que crean, mueven o
liberan ocupación deben:

1. `BEGIN`.
2. `SELECT id FROM spaces WHERE id IN (?, ?) ORDER BY id FOR UPDATE` — **siempre en orden de id
   ascendente**; si se cambia de espacio se bloquean ambos para evitar interbloqueos.
3. Consultar solapamientos contra `space_occupancies`.
4. Validar disponibilidad (`space_hours`), bloqueos y reglas de estado.
5. Crear/actualizar ocupación + reserva + venta.
6. `COMMIT`.

Rutas afectadas, todas con el mismo bloqueo: crear reserva, reprogramar, cancelar, crear/cancelar
bloqueo de mantenimiento, reunión con espacio.

Dos peticiones simultáneas para el mismo hueco: una gana, la otra recibe `409
RESERVATION_CONFLICT`. Se prueba con dos conexiones reales, no secuencialmente.

## Reglas de negocio

- **Cliente BANDA obligatorio.** NORMAL → `422`.
- Toda reserva genera **exactamente una** venta `type = RESERVA` y la referencia por
  `reservations.sale_id` (`UNIQUE`). Sin segunda ruta de cobro.
- Una reserva `CONFIRMADA` bloquea el horario **aun sin pagar**. Pago y uso del espacio son estados
  independientes.
- Un borrador no bloquea, y la UI debe advertir que no garantiza disponibilidad.
- Estados: `CONFIRMED`, `IN_PROGRESS`, `FINISHED`, `CANCELLED`. Bloqueantes: los dos primeros.
- Fin > inicio. Bloques de 30 minutos configurables.
- Sin reservas retrospectivas, salvo ADMIN con motivo registrado.

## Cobro

```
total = tarifa_hora_snapshot × minutos / 60
```

Redondeo **una vez al final** (ver `dinero-decimales`). La tarifa se congela en la reserva: cambiar
la tarifa del espacio no altera reservas existentes.

## Reprogramación

1. Bloquear espacio(s) en orden de ID.
2. Revalidar solapamientos y disponibilidad.
3. Recalcular tarifa con la regla explícita y guardar snapshot si corresponde.
4. Si la reserva tiene pagos: **no** reducir el total por debajo de lo cobrado sin resolución
   administrativa (reembolso o ajuste explícito). Nunca un descuento implícito.
5. Actualizar la venta vinculada en la misma transacción; si cambia el total, revisar el estado de
   pago (puede pasar de PAGADO a PARCIAL).
6. Si cambia el espacio, el bloqueo cubrió ambos; si cambia el horario, la fila de ocupación se
   actualiza, no se duplica.

## Cancelación

- Cambia la ocupación a `CANCELLED`, liberando el horario.
- La venta se cancela de forma coordinada: solo ADMIN si hay pagos, con motivo y reembolso.
- No se borra ninguna fila. El historial financiero se conserva.
- Cualquier penalización debe venir de una política configurada; no se inventa.

## Agenda

- La agenda consulta por rango visible; nunca el histórico completo.
- Los eventos arrastrados solo se persisten si el usuario tiene permiso y la API revalida el
  conflicto. Un `PATCH` genérico de reserva no puede saltarse el bloqueo.
- El calendario de espacios muestra ocupación con datos mínimos: sin importes, sin cliente.

## Pruebas obligatorias

1. Dos reservas simultáneas mismo espacio/horario → una `201`, una `409`.
2. 10:00-11:00 y 11:00-12:00 → ambas válidas.
3. Espacios distintos a la misma hora → válidas.
4. Cliente NORMAL → `422`.
5. Reprogramar a un hueco ocupado → `409` y sin cambios parciales.
6. Cancelar libera el horario para una nueva reserva.
7. Reserva con pago: bajar total por debajo de lo cobrado → bloqueado.
8. Una reserva produce exactamente una venta, y el dashboard la cuenta una sola vez.
