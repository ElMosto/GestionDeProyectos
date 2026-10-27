-- =============================================================================
-- Migración 004 — Espacios, ocupación y reservas
-- Fecha: 2026-10-27
-- Autor: arquitecto-datos
-- Requisito: §12 (espacios y reservas), §14, D-04; skill reservas-concurrencia
-- Propósito: spaces, space_hours, space_occupancies, reservations, space_blocks.
-- Reversión: bloque DOWN al final del archivo (orden inverso de creación).
-- Notas de diseño:
--   * space_occupancies es la ÚNICA fuente de verdad de ocupación: reservas,
--     mantenimiento y reuniones con espacio escriben aquí.
--   * Intervalos semiabiertos [inicio, fin). El solapamiento NO se puede expresar
--     como CHECK/UNIQUE en MariaDB: se valida en el servicio con transacción y
--     SELECT ... FOR UPDATE sobre spaces. Como defensa parcial, la BD impide dos
--     ocupaciones bloqueantes con el mismo espacio e idéntico intervalo exacto
--     (columna generada blocking_slot).
--   * Toda reserva exige exactamente una venta: sale_id NOT NULL y UNIQUE.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- spaces: espacios físicos del estudio, con su tarifa vigente (§12).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS spaces (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Tarifa vigente; las reservas congelan su propia copia',
  block_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Granularidad del calendario en minutos (§12: bloques de 30)',
  active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Un espacio con reservas futuras no se desactiva sin resolverlas',
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_spaces_name (name),
  KEY idx_spaces_active (active, name) COMMENT 'Selector de espacios disponibles (§15 GET /spaces)',
  CONSTRAINT fk_spaces_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_spaces_hourly_rate CHECK (hourly_rate >= 0),
  CONSTRAINT chk_spaces_block_minutes CHECK (block_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Espacios reservables; nunca se borran si tienen historia';

-- -----------------------------------------------------------------------------
-- space_hours: horario disponible semanal. Horas locales del estudio.
-- weekday ISO-8601: 1 = lunes ... 7 = domingo.
-- Una ventana nocturna que cruza medianoche se modela con dos filas.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS space_hours (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id BIGINT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL COMMENT 'ISO-8601: 1 lunes ... 7 domingo',
  local_start_time TIME NOT NULL COMMENT 'Hora local del estudio, no UTC',
  local_end_time TIME NOT NULL COMMENT 'Debe ser posterior a local_start_time; sin cruce de medianoche',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_space_hours (space_id, weekday, local_start_time),
  KEY idx_space_hours_space (space_id, weekday) COMMENT 'Cálculo de disponibilidad de un día (§15 GET /spaces/{id}/availability)',
  CONSTRAINT fk_space_hours_space FOREIGN KEY (space_id) REFERENCES spaces (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_space_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT chk_space_hours_range CHECK (local_end_time > local_start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ventanas de disponibilidad semanal en hora local';

-- -----------------------------------------------------------------------------
-- space_occupancies: única fuente de verdad de ocupación (§12, §14).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS space_occupancies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id BIGINT UNSIGNED NOT NULL,
  start_at DATETIME NOT NULL COMMENT 'UTC, intervalo semiabierto [start_at, end_at)',
  end_at DATETIME NOT NULL COMMENT 'UTC',
  kind ENUM('RESERVATION','MAINTENANCE','MEETING') NOT NULL,
  status ENUM('CONFIRMED','IN_PROGRESS','FINISHED','CANCELLED') NOT NULL DEFAULT 'CONFIRMED'
    COMMENT 'Solo CONFIRMED e IN_PROGRESS bloquean el horario',
  notes VARCHAR(255) NULL COMMENT 'No debe contener datos financieros: el calendario de espacios es público interno (§12)',
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  blocking_slot VARCHAR(80) AS (
    IF(status IN ('CONFIRMED','IN_PROGRESS'),
       CONCAT(space_id, '|', start_at, '|', end_at),
       NULL)
  ) PERSISTENT COMMENT 'Defensa parcial: impide duplicar el mismo intervalo exacto de un espacio',
  PRIMARY KEY (id),
  UNIQUE KEY uq_space_occupancies_blocking_slot (blocking_slot),
  KEY idx_space_occupancies_conflict (space_id, status, start_at, end_at) COMMENT '§14: consulta de solapamiento y calendario de espacios',
  KEY idx_space_occupancies_range (start_at, end_at) COMMENT 'Agenda por rango visible de todas las ocupaciones (§13)',
  CONSTRAINT fk_space_occupancies_space FOREIGN KEY (space_id) REFERENCES spaces (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_space_occupancies_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_space_occupancies_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ocupación de espacios; el solapamiento real se valida en el servicio';

-- -----------------------------------------------------------------------------
-- reservations: reserva de banda. Una por ocupación y una por venta (§12, D-04).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occupancy_id BIGINT UNSIGNED NOT NULL COMMENT 'Horario y espacio viven en space_occupancies',
  band_client_id BIGINT UNSIGNED NOT NULL COMMENT 'Cliente BANDA; la condición se valida en servicio (FK no puede mirar clients.type)',
  sale_id BIGINT UNSIGNED NOT NULL COMMENT 'Exactamente una venta tipo RESERVA (§12, D-04)',
  responsible_id BIGINT UNSIGNED NOT NULL,
  hourly_rate_snapshot DECIMAL(12,2) NOT NULL COMMENT 'Tarifa congelada al reservar',
  total DECIMAL(12,2) NOT NULL COMMENT 'tarifa_hora * minutos / 60, redondeado una vez al final',
  status ENUM('CONFIRMED','IN_PROGRESS','FINISHED','CANCELLED') NOT NULL DEFAULT 'CONFIRMED'
    COMMENT 'El borrador no existe aquí: un borrador es una venta BORRADOR sin ocupación',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservations_occupancy (occupancy_id),
  UNIQUE KEY uq_reservations_sale (sale_id),
  KEY idx_reservations_band (band_client_id, status) COMMENT 'Reservas de una banda para su ficha y ticket (§10, §12)',
  KEY idx_reservations_responsible (responsible_id, status) COMMENT 'Reservas a cargo del usuario en su agenda (§13)',
  KEY idx_reservations_status_created (status, created_at) COMMENT 'Listados operativos de reservas (§15 GET /reservations)',
  CONSTRAINT fk_reservations_occupancy FOREIGN KEY (occupancy_id) REFERENCES space_occupancies (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_reservations_band FOREIGN KEY (band_client_id) REFERENCES clients (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_reservations_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_reservations_responsible FOREIGN KEY (responsible_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_reservations_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_reservations_hourly_rate CHECK (hourly_rate_snapshot >= 0),
  CONSTRAINT chk_reservations_total CHECK (total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reserva confirmada de banda; el pago es independiente del uso (§2.4)';

-- -----------------------------------------------------------------------------
-- space_blocks: bloqueos por mantenimiento u otra causa, unidos a su ocupación.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS space_blocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occupancy_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_space_blocks_occupancy (occupancy_id),
  KEY idx_space_blocks_created (created_at) COMMENT 'Listado de bloqueos vigentes y recientes (§15 GET /space-blocks)',
  CONSTRAINT fk_space_blocks_occupancy FOREIGN KEY (occupancy_id) REFERENCES space_occupancies (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_space_blocks_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bloqueo de mantenimiento; cancelar cambia la ocupación, no borra la fila';

-- =============================================================================
-- DOWN (reversión manual, orden inverso)
-- DROP TABLE IF EXISTS space_blocks;
-- DROP TABLE IF EXISTS reservations;
-- DROP TABLE IF EXISTS space_occupancies;
-- DROP TABLE IF EXISTS space_hours;
-- DROP TABLE IF EXISTS spaces;
-- =============================================================================
