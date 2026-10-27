-- =============================================================================
-- Seed 002 — Configuración inicial mínima (datos iniciales, no demostración)
-- Fecha: 2026-10-27
-- Requisito: §1 (una moneda MXN), §6 (zona horaria), D-12
-- Aplica sobre: database/migrations/001_core_auth.sql
-- Idempotente y NO destructivo: si la clave ya existe no se sobrescribe, para no
-- pisar la configuración que el administrador haya cambiado desde la interfaz.
-- `key` es palabra reservada: va entre backticks.
-- Los importes, cuando existan en settings, se guardan como cadena: "150.00".
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO settings (`key`, value_json, description) VALUES
  ('currency',    '"MXN"',                    'Moneda única del sistema; importes como cadena decimal'),
  ('studio_name', '""',                       'Nombre del estudio para tickets y reportes; el administrador lo completa'),
  ('timezone',    '"America/Mexico_City"',    'Zona horaria IANA del estudio; la base guarda siempre UTC')
ON DUPLICATE KEY UPDATE
  `key` = VALUES(`key`);
