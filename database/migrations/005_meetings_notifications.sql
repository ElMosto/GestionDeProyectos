-- =============================================================================
-- Migración 005 — Reuniones, invitaciones y notificaciones
-- Fecha: 2026-10-27
-- Autor: arquitecto-datos
-- Requisito: §13 (agenda, reuniones, notificaciones), §14
-- Propósito: meetings, meeting_invitations, notifications.
-- Reversión: bloque DOWN al final del archivo (orden inverso de creación).
-- Notas de diseño:
--   * Una reunión que usa un espacio del estudio también crea una ocupación
--     (kind = MEETING) y la referencia con occupancy_id UNIQUE. Sin espacio,
--     occupancy_id queda NULL.
--   * Aceptar/rechazar es individual: una fila por invitado.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- meetings: reunión organizada por un usuario activo (§13).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meetings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organizer_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  start_at DATETIME NOT NULL COMMENT 'UTC',
  end_at DATETIME NOT NULL COMMENT 'UTC',
  modality ENUM('PRESENCIAL','VIRTUAL','HIBRIDA') NOT NULL DEFAULT 'PRESENCIAL',
  location VARCHAR(200) NULL COMMENT 'Lugar o enlace; nunca se envían invitaciones a clientes (§13)',
  occupancy_id BIGINT UNSIGNED NULL COMMENT 'Solo si usa un espacio del estudio; mismo bloqueo transaccional que una reserva',
  status ENUM('PROGRAMADA','CANCELADA','FINALIZADA') NOT NULL DEFAULT 'PROGRAMADA',
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meetings_occupancy (occupancy_id),
  KEY idx_meetings_organizer (organizer_id, start_at) COMMENT 'Reuniones que organiza un usuario en su agenda (§13)',
  KEY idx_meetings_range (start_at, end_at) COMMENT 'Consulta de agenda por rango visible (§15 GET /agenda)',
  CONSTRAINT fk_meetings_organizer FOREIGN KEY (organizer_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_meetings_occupancy FOREIGN KEY (occupancy_id) REFERENCES space_occupancies (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_meetings_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_meetings_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reunión interna; el invitado la ve al aceptar (§13)';

-- -----------------------------------------------------------------------------
-- meeting_invitations: respuesta individual por invitado (§13).
-- Un cambio de horario devuelve la invitación a PENDIENTE y limpia responded_at.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT UNSIGNED NOT NULL,
  invitee_id BIGINT UNSIGNED NOT NULL,
  response ENUM('PENDIENTE','ACEPTADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  responded_at DATETIME NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meeting_invitations (meeting_id, invitee_id),
  KEY idx_meeting_invitations_invitee (invitee_id, response) COMMENT '§14: invitaciones pendientes/aceptadas de un usuario para su agenda',
  CONSTRAINT fk_meeting_invitations_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_meeting_invitations_invitee FOREIGN KEY (invitee_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_meeting_invitations_responded CHECK (
    response = 'PENDIENTE' OR responded_at IS NOT NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Invitación por persona; rechazar no cancela la reunión';

-- -----------------------------------------------------------------------------
-- notifications: avisos dentro de la app con leído/no leído (§13).
-- Referencias polimórficas informativas, sin FK a la entidad (§14).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL COMMENT 'TASK_ASSIGNED, MEETING_INVITED, RESERVATION_CHANGED, PAYMENT_REGISTERED...',
  entity_type VARCHAR(40) NULL COMMENT 'Referencia informativa: task, meeting, reservation, sale',
  entity_id BIGINT UNSIGNED NULL,
  payload JSON NULL COMMENT 'Datos mínimos para pintar el aviso; nunca importes de terceros ni datos sensibles',
  read_at DATETIME NULL COMMENT 'NULL = no leída',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read_created (user_id, read_at, created_at) COMMENT '§14: bandeja y contador de no leídas',
  KEY idx_notifications_entity (entity_type, entity_id) COMMENT 'Marcar como leídas al cancelarse el evento origen',
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notificaciones persistidas; sin updated_at (solo read_at cambia)';

-- =============================================================================
-- DOWN (reversión manual, orden inverso)
-- DROP TABLE IF EXISTS notifications;
-- DROP TABLE IF EXISTS meeting_invitations;
-- DROP TABLE IF EXISTS meetings;
-- =============================================================================
