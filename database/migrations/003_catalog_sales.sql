-- =============================================================================
-- Migración 003 — Catálogo de productos/extras y ventas
-- Fecha: 2026-10-27
-- Autor: arquitecto-datos
-- Requisito: §10 (productos, extras, ventas, fórmula de importes), §14
-- Propósito:
--   products, extras, product_extras, sales, sale_items, sale_item_extras.
-- Reversión: bloque DOWN al final del archivo (orden inverso de creación).
-- Notas de diseño:
--   * Los importes se congelan en la partida (name_snapshot/unit_price) para que
--     cambiar el catálogo no altere tickets ya emitidos (§10).
--   * line_total se almacena calculado por el servicio con redondeo por partida;
--     la BD solo garantiza que no sea negativo (la fórmula es multi-fila).
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- products: catálogo. Sin inventario en la v1 (§10).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(40) NULL COMMENT 'Opcional pero único cuando existe (§10)',
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  type ENUM('PRODUCTO','SERVICIO') NOT NULL DEFAULT 'PRODUCTO',
  unit VARCHAR(32) NOT NULL DEFAULT 'pieza',
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Precio actual; las ventas guardan su propia copia',
  active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Baja lógica; no se borra si tiene ventas',
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_active_name (active, name) COMMENT 'Selector de productos activos en el alta de venta (§17)',
  CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_products_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de productos y servicios';

-- -----------------------------------------------------------------------------
-- extras: catálogo de adicionales por partida (§10).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS extras (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_extras_active_name (active, name) COMMENT 'Selector de extras activos (§17)',
  CONSTRAINT fk_extras_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_extras_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Extras permitidos; el precio histórico vive en la venta';

-- -----------------------------------------------------------------------------
-- product_extras: qué extras admite cada producto (§10).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_extras (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  extra_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_extras (product_id, extra_id),
  KEY idx_product_extras_extra (extra_id, product_id) COMMENT 'Impacto de desactivar un extra en el catálogo',
  CONSTRAINT fk_product_extras_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_product_extras_extra FOREIGN KEY (extra_id) REFERENCES extras (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relación producto-extra; reemplazable con PUT /products/{id}/extras';

-- -----------------------------------------------------------------------------
-- sales: documento comercial. Folio por secuencia.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio VARCHAR(24) NOT NULL COMMENT 'VTA-2026-000123',
  type ENUM('ORDINARIA','RESERVA') NOT NULL DEFAULT 'ORDINARIA',
  client_id BIGINT UNSIGNED NULL COMMENT 'Obligatorio en RESERVA y siempre BANDA (validación de servicio)',
  responsible_id BIGINT UNSIGNED NOT NULL,
  status ENUM('BORRADOR','EMITIDA','CANCELADA') NOT NULL DEFAULT 'BORRADOR',
  issued_at DATETIME NULL COMMENT 'Fecha de emisión UTC; el dashboard usa esta fecha para el importe comercial (§11)',
  currency CHAR(3) NOT NULL DEFAULT 'MXN',
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma de line_total con redondeo por partida (§10)',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_folio (folio),
  KEY idx_sales_responsible_issued (responsible_id, issued_at) COMMENT '§14: ventas propias por fecha',
  KEY idx_sales_client_issued (client_id, issued_at) COMMENT 'Estado de cuenta del cliente (§11 receivables)',
  KEY idx_sales_status_issued (status, issued_at) COMMENT 'Dashboard: ventas emitidas en un periodo (§11)',
  KEY idx_sales_type_issued (type, issued_at) COMMENT 'Dashboard: separar venta ordinaria y reserva (§11)',
  CONSTRAINT fk_sales_client FOREIGN KEY (client_id) REFERENCES clients (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_sales_responsible FOREIGN KEY (responsible_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_sales_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_sales_total CHECK (total >= 0),
  CONSTRAINT chk_sales_reservation_client CHECK (type <> 'RESERVA' OR client_id IS NOT NULL),
  CONSTRAINT chk_sales_issued_at CHECK (status = 'BORRADOR' OR issued_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Venta ordinaria o de reserva; nunca se borra, se cancela (§10, §11)';

-- -----------------------------------------------------------------------------
-- sale_items: partidas con copia histórica de nombre y precio.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL COMMENT 'NULL si el concepto libre fue autorizado por el administrador (§10)',
  name_snapshot VARCHAR(160) NOT NULL COMMENT 'Nombre al momento de la venta',
  quantity DECIMAL(10,3) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL COMMENT 'Precio base histórico',
  line_total DECIMAL(12,2) NOT NULL COMMENT 'quantity * (unit_price + extras por unidad), redondeado a 2 decimales',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Orden estable de impresión del ticket',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sale_items_sale (sale_id, sort_order) COMMENT 'Armado del ticket y recálculo del total (§10)',
  KEY idx_sale_items_product (product_id, created_at) COMMENT 'Qué se ha vendido de un producto (sin inventario, solo consulta)',
  CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_sale_items_quantity CHECK (quantity > 0),
  CONSTRAINT chk_sale_items_unit_price CHECK (unit_price >= 0),
  CONSTRAINT chk_sale_items_line_total CHECK (line_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Partidas de venta con snapshot de catálogo';

-- -----------------------------------------------------------------------------
-- sale_item_extras: extras por unidad dentro de una partida (§10).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_item_extras (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_item_id BIGINT UNSIGNED NOT NULL,
  extra_id BIGINT UNSIGNED NULL,
  name_snapshot VARCHAR(120) NOT NULL,
  quantity_per_unit DECIMAL(10,3) NOT NULL COMMENT 'Ej. 1 chocolate por licuado',
  unit_price DECIMAL(12,2) NOT NULL COMMENT 'Precio histórico del extra',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sale_item_extras_item (sale_item_id) COMMENT 'Desglose de extras de una partida en el ticket (§10)',
  KEY idx_sale_item_extras_extra (extra_id) COMMENT 'Histórico de uso de un extra (no impide desactivarlo)',
  CONSTRAINT fk_sale_item_extras_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_sale_item_extras_extra FOREIGN KEY (extra_id) REFERENCES extras (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_sale_item_extras_quantity CHECK (quantity_per_unit > 0),
  CONSTRAINT chk_sale_item_extras_unit_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Extras históricos por partida; no se recalculan desde el catálogo';

-- =============================================================================
-- DOWN (reversión manual, orden inverso)
-- DROP TABLE IF EXISTS sale_item_extras;
-- DROP TABLE IF EXISTS sale_items;
-- DROP TABLE IF EXISTS sales;
-- DROP TABLE IF EXISTS product_extras;
-- DROP TABLE IF EXISTS extras;
-- DROP TABLE IF EXISTS products;
-- =============================================================================
