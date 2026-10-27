# Esquema de base de datos

> Documento vivo. Lo mantiene `arquitecto-datos`. El esquema se materializa en
> `database/migrations/`; este archivo explica el *porqué* y lo que no es evidente en el SQL.

Estado: **sin migraciones todavía** (fase 1).

## Convenciones

- InnoDB, `utf8mb4_unicode_ci`, PK `BIGINT UNSIGNED`.
- Importes `DECIMAL(12,2)`, cantidades `DECIMAL(10,3)`.
- `DATETIME` en UTC, `DATE` sin hora.
- `created_at`, `updated_at`, `created_by` donde proceda.
- `version INT UNSIGNED` en entidades editables (concurrencia optimista).
- `ON DELETE RESTRICT` en lo comercial; borrado lógico en catálogos y cuentas.

## Tablas y propósito

| Tabla | Propósito | Notas |
|---|---|---|
| _(pendiente de la fase 1)_ | | |

## Invariantes garantizados en BD

| Invariante | Implementación | ¿También en servicio? |
|---|---|---|
| Pago con un solo documento | `CHECK` XOR `project_id`/`sale_id` | Sí |
| Importe de pago > 0 | `CHECK` | Sí |
| Progreso 0-100 | `CHECK` | Sí |
| Folio único | `UNIQUE` + secuencia | Sí |
| Ocupación única por reserva | `UNIQUE(occupancy_id)` | Sí |
| Solapamiento de espacios | Transacción + `FOR UPDATE` | — (solo servicio) |

## Lo que la base no puede garantizar

Anotar aquí cada regla que dependa de la lógica de negocio (solapamientos, último administrador,
sobrepago), porque en esas el servicio es la única defensa.

## Índices

Cada índice debe justificarse con una consulta real. Pendiente de la fase 1:

| Índice | Consulta que lo justifica |
|---|---|
| | |
