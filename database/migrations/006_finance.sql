-- =============================================================================
-- Migración 006 — Pagos, reembolsos y gastos
-- Fecha: 2026-10-27
-- Autor: arquitecto-datos
-- Requisito: §11 (pagos y control financiero), §8 (gastos), §14, D-03
-- Propósito: payments, refunds, expenses.
-- Reversión: bloque DOWN al final del archivo (orden inverso de creación).
-- Notas de diseño:
--   * Un pago pertenece exactamente a un documento: proyecto XOR venta.
--     Una reserva se cobra por su venta, nunca por una segunda ruta (§11).
--   * Nada se borra: anular ≠ reembolsar ≠ cancelar (§11). Por eso hay estado,
--     motivo y responsable en la propia fila.
--   * El sobrepago y el exceso de reembolso son sumas multi-fila: solo el
--     servicio puede impedirlos (ver docs/esquema.md).
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- payments: cobro manual sobre un proyecto o una venta (§11).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio VARCHAR(24) NOT NULL COMMENT 'PAG-2026-000123, generado por sequences',
  project_id BIGINT UNSIGNED NULL,
  sale_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL COMMENT 'Siempre positivo; signo negativo no representa una anulación',
  effective_at DATETIME NOT NULL COMMENT 'UTC: fecha efectiva del cobro; es la que usa el dashboard por periodo (§11)',
  method ENUM('EFECTIVO','TRANSFERENCIA','TARJETA','OTRO') NOT NULL,
  reference VARCHAR(120) NULL COMMENT 'Referencia bancaria o nota; nunca número de tarjeta (§11)',
  notes TEXT NULL,
  status ENUM('REGISTRADO','ANULADO') NOT NULL DEFAULT 'REGISTRADO',
  registered_by BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL COMMENT 'Idempotency-Key de la petición; única en toda la tabla',
  voided_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  void_reason VARCHAR(255) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_folio (folio),
  UNIQUE KEY uq_payments_idempotency (idempotency_key),
  KEY idx_payments_effective_status (effective_at, status) COMMENT '§14: cobros reales netos por periodo (§11 dashboard)',
  KEY idx_payments_project (project_id) COMMENT '§14: pagos de un proyecto y cálculo de saldo',
  KEY idx_payments_sale (sale_id) COMMENT '§14: pagos de una venta y cálculo de saldo',
  KEY idx_payments_registered_by (registered_by, effective_at) COMMENT 'Pagos registrados por un usuario (§15 GET /finance/transactions)',
  CONSTRAINT fk_payments_project FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_payments_registered_by FOREIGN KEY (registered_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_payments_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_payments_one_document CHECK ((project_id IS NULL) <> (sale_id IS NULL)),
  CONSTRAINT chk_payments_amount CHECK (amount > 0),
  CONSTRAINT chk_payments_void_data CHECK (
    status <> 'ANULADO' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cobro manual; el estado del documento se calcula desde aquí (§11, D-03)';

-- -----------------------------------------------------------------------------
-- refunds: devolución real vinculada a un pago (§11). No es una anulación.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio VARCHAR(24) NOT NULL COMMENT 'REE-2026-000123, generado por sequences (decisión de esquema, ver docs/esquema.md)',
  payment_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL COMMENT 'Limitado al importe del pago aún no reembolsado (validación de servicio)',
  effective_at DATETIME NOT NULL COMMENT 'UTC',
  reason VARCHAR(255) NOT NULL,
  status ENUM('REGISTRADO','ANULADO') NOT NULL DEFAULT 'REGISTRADO',
  registered_by BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  voided_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refunds_folio (folio),
  UNIQUE KEY uq_refunds_idempotency (idempotency_key),
  KEY idx_refunds_payment (payment_id, effective_at) COMMENT 'Neto reembolsado de un pago: saldo reembolsable (§11)',
  KEY idx_refunds_effective (effective_at, status) COMMENT 'Reembolsos del periodo en el dashboard (§11)',
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_refunds_registered_by FOREIGN KEY (registered_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_refunds_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_refunds_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reembolso real; suma negativa al neto cobrado del documento';

-- -----------------------------------------------------------------------------
-- expenses: salidas reales del estudio. Nunca modifican el precio al cliente (§8).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio VARCHAR(24) NOT NULL COMMENT 'GAS-2026-000123, generado por sequences (decisión de esquema, ver docs/esquema.md)',
  project_id BIGINT UNSIGNED NULL COMMENT 'NULL = gasto general del estudio',
  concept VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL COMMENT 'Fecha del gasto, sin hora',
  responsible_id BIGINT UNSIGNED NOT NULL COMMENT 'Responsable del gasto (§8)',
  status ENUM('PENDIENTE','PAGADO') NOT NULL DEFAULT 'PENDIENTE',
  paid_at DATETIME NULL COMMENT 'UTC; obligatorio cuando status = PAGADO',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expenses_folio (folio),
  KEY idx_expenses_date_status (expense_date, status) COMMENT 'Gastos pagados/pendientes del periodo (§11 dashboard)',
  KEY idx_expenses_project (project_id, expense_date) COMMENT 'Gastos internos por proyecto; nunca se muestran al cliente (§9)',
  KEY idx_expenses_responsible (responsible_id, status) COMMENT 'Gastos a cargo de un usuario',
  CONSTRAINT fk_expenses_project FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_expenses_responsible FOREIGN KEY (responsible_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_expenses_amount CHECK (amount > 0),
  CONSTRAINT chk_expenses_paid_at CHECK (status <> 'PAGADO' OR paid_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Gastos del estudio; reducen el resultado de caja, no el precio';

-- =============================================================================
-- DOWN (reversión manual, orden inverso)
-- DROP TABLE IF EXISTS expenses;
-- DROP TABLE IF EXISTS refunds;
-- DROP TABLE IF EXISTS payments;
-- =============================================================================
