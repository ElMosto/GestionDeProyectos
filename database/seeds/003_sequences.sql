-- =============================================================================
-- Seed 003 — Secuencias de folio (datos iniciales mínimos)
-- Fecha: 2026-10-27
-- Requisito: §14 (folios por secuencia, nunca MAX(id)+1)
-- Aplica sobre: database/migrations/001_core_auth.sql
-- Idempotente y NO destructivo: no reinicia contadores ya usados.
--
-- Formato del folio: PREFIJO-AAAA-000000  ->  PRY-2026-000123
--   1) Dentro de la transacción del documento:
--        UPDATE sequences
--           SET last_used  = LAST_INSERT_ID(next_value),
--               next_value = next_value + 1
--         WHERE name = 'project';
--        SELECT LAST_INSERT_ID();            -- número a formatear
--   2) El valor de next_value NO se reinicia cada año: el contador es único por
--      tipo de documento y el año solo forma parte del texto del folio.
--      Así el folio sigue siendo monotónico y no hay carrera al cambiar de año.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO sequences (name, prefix, next_value, description) VALUES
  ('project', 'PRY', 1, 'Folio de proyecto: PRY-AAAA-000000'),
  ('sale',    'VTA', 1, 'Folio de venta: VTA-AAAA-000000'),
  ('payment', 'PAG', 1, 'Folio de pago: PAG-AAAA-000000'),
  ('refund',  'REE', 1, 'Folio de reembolso: REE-AAAA-000000'),
  ('expense', 'GAS', 1, 'Folio de gasto: GAS-AAAA-000000')
ON DUPLICATE KEY UPDATE
  name = VALUES(name);
