-- =============================================================================
-- Migración 007 — Obligatoriedad de tareas para finalizar proyectos (§8, §14)
-- Fecha: 2026-10-28
-- Autor: arquitecto-datos
-- Todas las tareas existentes quedan obligatorias: propuesta conservadora para §8.
-- No cambia avance, estados, asignación, ni crea documentos o tablas de historial.
-- MariaDB 10.4+: DDL con commit implícito; respaldar antes de aplicar o revertir.
-- =============================================================================

ALTER TABLE tasks
  ADD COLUMN IF NOT EXISTS is_required TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Obligatoria para finalizar el proyecto; independiente si stage_id es NULL'
    AFTER progress,
  ADD CONSTRAINT chk_tasks_is_required CHECK (is_required IN (0, 1));

-- =============================================================================
-- DOWN (manual, con respaldo previo; pierde la clasificación obligatoria/opcional)
-- ALTER TABLE tasks
--   DROP CONSTRAINT chk_tasks_is_required,
--   DROP COLUMN is_required;
-- Eliminar el registro 007 de schema_migrations solo tras completar la reversión,
-- si se desea reaplicar. El ejecutor no implementa down automático.
-- =============================================================================
