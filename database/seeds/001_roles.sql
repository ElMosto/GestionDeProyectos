-- =============================================================================
-- Seed 001 — Roles globales (datos iniciales mínimos, no demostración)
-- Fecha: 2026-10-27
-- Requisito: §2.1, §5, D-01
-- Aplica sobre: database/migrations/001_core_auth.sql
-- Idempotente: se puede ejecutar varias veces sin duplicar ni fallar.
-- NO crea usuarios ni contraseñas: el primer administrador se crea con el
-- instalador de un solo uso (§18), nunca con un seed.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO roles (id, code, name, description) VALUES
  (1, 'ADMINISTRADOR',   'Administrador',   'Gestiona cuentas, configuración, catálogos y todas las operaciones de alto impacto'),
  (2, 'LIDER_PROYECTO',  'Líder de proyecto', 'Crea y dirige sus proyectos, etapas, tareas, miembros, ventas y reservas propias'),
  (3, 'COLABORADOR',     'Colaborador',     'Trabaja en los proyectos de los que es miembro y en las tareas que tiene asignadas')
ON DUPLICATE KEY UPDATE
  code = VALUES(code),
  name = VALUES(name),
  description = VALUES(description);
