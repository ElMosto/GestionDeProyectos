-- =============================================================================
-- Migración 008 — Cancelar borradores sin inventar una fecha de emisión (§10, §14)
-- Fecha: 2026-10-28
-- Autor: arquitecto-datos
-- Solo EMITIDA exige issued_at. CANCELADA conserva la fecha histórica si se emitió,
-- o NULL si se canceló desde BORRADOR. No modifica filas, columnas, índices ni FK.
-- Las transiciones, el motivo y la auditoría siguen a cargo del servicio (§10-11).
-- MariaDB 10.4+: DDL con commit implícito; respaldar antes de aplicar o revertir.
-- =============================================================================

ALTER TABLE sales
  DROP CONSTRAINT chk_sales_issued_at,
  ADD CONSTRAINT chk_sales_issued_at CHECK (status <> 'EMITIDA' OR issued_at IS NOT NULL);

-- =============================================================================
-- DOWN (manual, con respaldo previo)
-- Primero comprobar:
-- SELECT id, folio FROM sales WHERE status = 'CANCELADA' AND issued_at IS NULL;
-- Si devuelve filas, NO revertir: la restricción anterior rechaza datos ahora válidos.
-- No inventar fechas ni eliminar historia para posibilitar la reversión.
-- Si no devuelve filas:
-- ALTER TABLE sales
--   DROP CONSTRAINT chk_sales_issued_at,
--   ADD CONSTRAINT chk_sales_issued_at CHECK (status = 'BORRADOR' OR issued_at IS NOT NULL);
-- Eliminar el registro 008 de schema_migrations solo tras completar la reversión,
-- si se desea reaplicar. El ejecutor no implementa down automático.
-- =============================================================================
