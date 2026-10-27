---
name: Esquema MariaDB y migraciones
description: Convenciones de DDL, migraciones versionadas, claves foráneas, CHECK, índices, folios por secuencia, borrado lógico y control de concurrencia para el esquema MariaDB del proyecto.
---

# Esquema MariaDB y migraciones

## Convenciones de columnas

| Dato | Tipo |
|---|---|
| PK / FK | `BIGINT UNSIGNED` (mismo tipo en ambos lados) |
| Importes | `DECIMAL(12,2)` |
| Cantidades | `DECIMAL(10,3)` |
| Fechas con hora | `DATETIME` **en UTC** |
| Fechas sin hora | `DATE` |
| Enumeraciones | `ENUM` solo si el conjunto es cerrado y estable; si puede crecer, tabla catálogo |
| Textos cortos | `VARCHAR(n)` con `n` justificado |
| Descripciones | `TEXT` |
| Booleanos | `TINYINT(1) NOT NULL DEFAULT 0` |
| JSON | `JSON` solo para `payload`/`value_json`/`before_json`/`after_json` |

Toda tabla: `id`, `created_at`, `updated_at` y `created_by` cuando proceda. Toda entidad editable:
`version INT UNSIGNED NOT NULL DEFAULT 1`.

Motor `InnoDB`, `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

## Migraciones

- Archivo por cambio: `database/migrations/001_crear_base.sql`, numeración correlativa de tres
  dígitos, nunca renumerar ni editar una migración ya aplicada.
- Cada archivo: bloque de aplicación idempotente en lo posible (`CREATE TABLE IF NOT EXISTS`) y
  comentario de cabecera con propósito, autor y fecha.
- Los cambios destructivos (`DROP`, `MODIFY` que pierde datos) van en migración separada y con
  nota de respaldo previo.
- `database/seeds/` solo datos iniciales (roles, settings). El dataset de demostración va en un
  archivo aparte y claramente marcado.
- Toda migración se prueba **desde una base vacía** antes de considerarse lista.

## Restricciones que deben vivir en la base

No delegues al servicio lo que la base puede impedir:

- `payments`: exactamente uno de `project_id`/`sale_id` no nulo.
  ```sql
  CONSTRAINT chk_payment_document CHECK (
    (project_id IS NULL) <> (sale_id IS NULL)
  ),
  CONSTRAINT chk_payment_amount CHECK (amount > 0)
  ```
- `progress BETWEEN 0 AND 100`; `quantity > 0`; precios y cargos `>= 0`.
- Fin con hora > inicio. En tareas y etapas se permite `inicio = fin` el mismo día.
- `UNIQUE` en folios, `email`, `reservations.sale_id`, `reservations.occupancy_id`,
  `space_blocks.occupancy_id`.
- `tasks`: si `stage_id` es `NULL` es independiente; el proyecto **no** se guarda en la tarea, se
  deriva de la etapa para que no puedan contradecirse.
- Índices del §14 declarados explícitamente, más los que exijan consultas reales.

Los `CHECK` de MariaDB pueden ignorarse en versiones antiguas o al usar `INSERT IGNORE`. Verifica
en la versión real del hosting y **complementa siempre** con validación de servicio; documenta en
`docs/esquema.md` qué queda solo en código.

## Borrado y conservación

- `ON DELETE RESTRICT` en todo lo comercial. Sin `CASCADE` hacia dinero.
- Catálogos y cuentas: borrado lógico (`active = 0`), nunca físico si tienen historia.
- No se puede desactivar al último administrador activo: se valida en servicio **y** se documenta.

## Folios

Nunca `MAX(id)+1`. Usar una tabla de secuencias o `AUTO_INCREMENT` reservado por tipo:

```sql
CREATE TABLE sequences (
  name VARCHAR(32) PRIMARY KEY,
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
```

`UPDATE sequences SET next_value = LAST_INSERT_ID(next_value + 1) WHERE name = ?` dentro de la
transacción, y tomar `LAST_INSERT_ID()`. El folio se forma con prefijo y año: `PRY-2026-000123`.

## Concurrencia

- Bloqueo de filas con `SELECT ... FOR UPDATE` **dentro** de transacción, siempre en el mismo orden
  (por `id` ascendente) cuando haya más de una fila.
- `space_occupancies` es la única fuente de verdad de ocupación: reservas, bloqueos y reuniones
  escriben ahí. Ver skill `reservas-concurrencia`.
- Reintento solo para deadlocks y en un único punto (helper de transacción).

## Verificación

```sql
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB';
SELECT * FROM information_schema.CHECK_CONSTRAINTS;
```

Estas dos consultas deben salir vacías/correctas antes de cerrar la fase 1.
