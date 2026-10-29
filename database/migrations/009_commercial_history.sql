-- =============================================================================
-- Migration 009: commercial document history and pending expense cancellation
-- Author: arquitecto-datos; Date: 2026-10-28
-- Requirements: sections 11, 14, 18, 19.G/H; decisions D-28/D-29.
-- No historical backfill: missing evidence must produce HISTORY_INCOMPLETE.
-- Project ISSUE is explicit at document creation, including operational BORRADOR.
-- All instants are UTC. No redundant issued/cancelled columns on documents.
-- Preflight: existing non-PAGADO expenses must have paid_at IS NULL.
-- DDL commits implicitly. Back up first; see conditional, destructive DOWN below.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS commercial_document_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NULL,
  sale_id BIGINT UNSIGNED NULL,
  audit_log_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('ISSUE','CHANGE','CANCEL','REOPEN','BASELINE') NOT NULL,
  occurred_at DATETIME NOT NULL COMMENT 'UTC instant of evidenced change, never invented backdating',
  issued_at DATETIME NULL COMMENT 'UTC first explicit commercial emission; NULL only where unknown',
  total DECIMAL(12,2) NOT NULL,
  billable TINYINT(1) NOT NULL,
  source_type ENUM('INTERNO','EXTERNO','ORDINARIA','RESERVA') NOT NULL,
  client_id BIGINT UNSIGNED NULL,
  responsible_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  cancelled TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commercial_events_audit (audit_log_id),
  KEY idx_commercial_events_project_time (project_id, occurred_at, id),
  KEY idx_commercial_events_sale_time (sale_id, occurred_at, id),
  CONSTRAINT fk_commercial_events_project FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_commercial_events_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_commercial_events_audit FOREIGN KEY (audit_log_id) REFERENCES audit_logs (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_commercial_events_client FOREIGN KEY (client_id) REFERENCES clients (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_commercial_events_responsible FOREIGN KEY (responsible_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_commercial_events_document CHECK ((project_id IS NULL) <> (sale_id IS NULL)),
  CONSTRAINT chk_commercial_events_total CHECK (total >= 0),
  CONSTRAINT chk_commercial_events_billable CHECK (billable IN (0, 1)),
  CONSTRAINT chk_commercial_events_cancelled CHECK (cancelled IN (0, 1)),
  CONSTRAINT chk_commercial_events_source CHECK (
    (project_id IS NOT NULL AND source_type IN ('INTERNO','EXTERNO'))
    OR (sale_id IS NOT NULL AND source_type IN ('ORDINARIA','RESERVA') AND billable = 1)
  ),
  CONSTRAINT chk_commercial_events_time CHECK (issued_at IS NULL OR issued_at <= occurred_at),
  CONSTRAINT chk_commercial_events_issue CHECK (
    kind <> 'ISSUE' OR (issued_at IS NOT NULL AND cancelled = 0)
  ),
  CONSTRAINT chk_commercial_events_cancel CHECK (kind <> 'CANCEL' OR cancelled = 1),
  CONSTRAINT chk_commercial_events_reopen CHECK (kind <> 'REOPEN' OR cancelled = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable commercial snapshots; not cash movements; no fabricated backfill';

-- Single-statement triggers: compatible with PDO and semicolon migration splitters.
-- These reject UPDATE/DELETE, including no-op updates. TRUNCATE is DDL and is NOT
-- prevented; the production application must not have DROP/ALTER/TRIGGER privileges.
CREATE TRIGGER IF NOT EXISTS trg_commercial_events_no_update
  BEFORE UPDATE ON commercial_document_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Commercial document events are immutable';

CREATE TRIGGER IF NOT EXISTS trg_commercial_events_no_delete
  BEFORE DELETE ON commercial_document_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Commercial document events are immutable';

ALTER TABLE expenses
  MODIFY COLUMN status ENUM('PENDIENTE','PAGADO','CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
  ADD CONSTRAINT chk_expenses_paid_at_state CHECK (
    (status = 'PAGADO' AND paid_at IS NOT NULL)
    OR (status <> 'PAGADO' AND paid_at IS NULL)
  ),
  ADD KEY idx_expenses_status_paid (status, paid_at)
    COMMENT 'Finance: paid cash outflow by paid_at UTC range, not expense_date';

-- =============================================================================
-- DOWN: MANUAL ONLY. Stop writes and revert dependent backend first.
-- DESTRUCTIVE: exporting/backing up commercial_document_events is mandatory;
-- dropping it destroys financial history and invalidates historical coverage.
-- Preflight: SELECT COUNT(*) FROM expenses WHERE status = 'CANCELADO';
-- Must be ZERO before reverting the ENUM. Never erase/relabel cancellations to
-- force rollback. Restore the backup instead if rollback is not compatible.
-- ALTER TABLE expenses
--   DROP INDEX idx_expenses_status_paid,
--   DROP CONSTRAINT chk_expenses_paid_at_state,
--   MODIFY COLUMN status ENUM('PENDIENTE','PAGADO') NOT NULL DEFAULT 'PENDIENTE';
-- DROP TRIGGER IF EXISTS trg_commercial_events_no_delete;
-- DROP TRIGGER IF EXISTS trg_commercial_events_no_update;
-- DROP TABLE IF EXISTS commercial_document_events;
-- Delete version 009 from schema_migrations only after a successful manual DOWN.
-- Do not execute DOWN on shared databases as a migration test.
-- =============================================================================
