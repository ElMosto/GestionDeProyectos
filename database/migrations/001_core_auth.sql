-- =============================================================================
-- Migración 001 — Núcleo: cuentas, seguridad, auditoría y soporte
-- Fecha: 2026-10-27
-- Autor: arquitecto-datos
-- Requisito: §5 (roles y cuentas), §6 (perfil), §14 (modelo relacional), §16 (seguridad)
-- Propósito:
--   roles, users, api_tokens (D-08, tokens opacos para Android), password_resets,
--   login_attempts (rate limit §16), audit_logs, settings, sequences (folios),
--   idempotency_requests y schema_migrations (control del ejecutor de migraciones).
-- Reversión: bloque DOWN al final del archivo (orden inverso de creación).
-- Notas:
--   * La columna `key` de settings / idempotency_requests es palabra reservada:
--     el backend DEBE citarla con backticks (`key`).
--   * `updated_at` usa CURRENT_TIMESTAMP, por lo que la sesión debe estar en UTC
--     (ver database/migrations/README.md).
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- schema_migrations: la escribe el ejecutor de migraciones, no esta migración.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(10) NOT NULL COMMENT 'Número correlativo de tres dígitos: 001',
  filename VARCHAR(120) NOT NULL,
  checksum CHAR(64) NOT NULL COMMENT 'SHA-256 del archivo aplicado; detecta ediciones posteriores',
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Migraciones aplicadas; la mantiene el ejecutor, nunca editar a mano';

-- -----------------------------------------------------------------------------
-- roles: catálogo cerrado de tres roles (D-01). Se siembra en seeds/001_roles.sql.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL COMMENT 'ADMINISTRADOR | LIDER_PROYECTO | COLABORADOR',
  name VARCHAR(64) NOT NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles globales fijos (§5, D-01)';

-- -----------------------------------------------------------------------------
-- users: cuentas de acceso. Nunca se eliminan físicamente (§5).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'password_hash() de PHP; nunca en claro ni reversible',
  phone VARCHAR(32) NULL,
  avatar_path VARCHAR(255) NULL COMMENT 'Ruta relativa dentro de storage/private; nombre generado por el servidor',
  job_title VARCHAR(120) NULL COMMENT 'Cargo descriptivo visible (§6)',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City' COMMENT 'IANA; solo para presentación',
  notification_prefs JSON NULL COMMENT 'Preferencias de notificación (§6); no datos sensibles',
  active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = desactivada; pierde acceso de inmediato',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  password_changed_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Concurrencia optimista: PATCH con versión esperada',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_active (role_id, active) COMMENT 'Listado de usuarios por rol activo (§15 GET /users)',
  KEY idx_users_active_name (active, name) COMMENT 'Directorio mínimo de usuarios activos para asignaciones e invitaciones (§5)',
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cuentas de acceso; el cliente nunca es una cuenta (§1)';

-- -----------------------------------------------------------------------------
-- api_tokens: D-08. Token opaco Bearer para la app Android. Se guarda el hash.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL COMMENT 'Etiqueta del dispositivo dada por el usuario',
  token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hexadecimal del token; el token en claro no se almacena',
  abilities JSON NULL COMMENT 'Lista blanca de acciones permitidas al token (mínimo privilegio)',
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL COMMENT 'NULL = sin caducidad absoluta, sujeta a revocación',
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY idx_api_tokens_user (user_id, revoked_at, expires_at) COMMENT 'Validación Bearer: token vigente del usuario',
  CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tokens opacos de la app móvil (D-08); jamás JWT';

-- -----------------------------------------------------------------------------
-- password_resets: clave temporal de un solo uso (restablecimiento por admin, §6).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL COMMENT 'Administrador que emitió la clave temporal; NULL si fue autoservicio',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id, used_at, expires_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_password_resets_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Claves temporales de un solo uso; se guarda el hash, nunca la clave';

-- -----------------------------------------------------------------------------
-- login_attempts: auditoría de intentos para rate limit por cuenta/IP (§16).
-- Sin FK a users: se registran correos inexistentes a propósito.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(180) NULL,
  ip_address VARCHAR(45) NULL COMMENT 'IPv4 o IPv6 textual',
  user_agent VARCHAR(255) NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email (email, created_at) COMMENT 'Rate limit por cuenta: últimos N intentos',
  KEY idx_login_attempts_ip (ip_address, created_at) COMMENT 'Rate limit por IP: últimos N intentos'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Intentos de acceso para rate limit; sin FK para cubrir correos desconocidos';

-- -----------------------------------------------------------------------------
-- audit_logs: bitácora append-only (§16). Referencias polimórficas informativas.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id BIGINT UNSIGNED NULL COMMENT 'NULL = acción del sistema (migración, cron)',
  action VARCHAR(60) NOT NULL COMMENT 'Verbo estable en inglés: PAYMENT_VOID, ROLE_CHANGE, PRICE_UPDATE',
  entity_type VARCHAR(40) NOT NULL COMMENT 'Nombre lógico: project, sale, payment...',
  entity_id BIGINT UNSIGNED NULL COMMENT 'Referencia informativa, NO clave foránea (§14)',
  before_json JSON NULL COMMENT 'Estado previo sin hashes, tokens ni datos de tarjeta (§16)',
  after_json JSON NULL,
  request_id VARCHAR(64) NULL COMMENT 'Correlación con el error/log HTTP',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id, created_at) COMMENT 'Historial de un documento concreto',
  KEY idx_audit_actor (actor_id, created_at) COMMENT 'Auditoría por usuario para revisión administrativa',
  KEY idx_audit_action (action, created_at) COMMENT 'Filtro de acciones sensibles (anulaciones, roles)',
  KEY idx_audit_request (request_id) COMMENT 'Depurar una petición HTTP concreta',
  CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora inmutable; sin updated_at a propósito';

-- -----------------------------------------------------------------------------
-- settings: configuración clave/valor. `key` es palabra reservada (backticks).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(80) NOT NULL COMMENT 'Identificador estable en snake_case: currency, studio_name, timezone',
  value_json JSON NOT NULL COMMENT 'Valor tipado; los importes siempre como cadena decimal',
  description VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (`key`),
  CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuración del estudio; una fila por clave';

-- -----------------------------------------------------------------------------
-- sequences: folios por secuencia. PROHIBIDO MAX(id)+1 (§14, skill esquema-mariadb).
-- next_value es el valor que se asignará al PRÓXIMO folio.
-- Consumo (dentro de la transacción del documento):
--   UPDATE sequences SET last_used = LAST_INSERT_ID(next_value), next_value = next_value + 1
--    WHERE name = ?;  SELECT LAST_INSERT_ID();
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sequences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(32) NOT NULL COMMENT 'project, sale, payment, refund, expense',
  prefix VARCHAR(12) NOT NULL COMMENT 'PRY, VTA, PAG, REE, GAS',
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Valor que recibirá el siguiente folio',
  last_used BIGINT UNSIGNED NULL COMMENT 'Último valor entregado; lo escribe la misma sentencia que avanza next_value',
  description VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sequences_name (name),
  CONSTRAINT chk_sequences_next_value CHECK (next_value >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Contadores de folio por tipo de documento; se leen y avanzan con bloqueo de fila';

-- -----------------------------------------------------------------------------
-- idempotency_requests: rejugar la misma respuesta ante reintentos (§11, §15).
-- Clave compuesta por actor + operación + clave del cliente.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS idempotency_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id BIGINT UNSIGNED NOT NULL,
  operation VARCHAR(60) NOT NULL COMMENT 'sale.issue, payment.create, reservation.create, refund.create',
  `key` VARCHAR(100) NOT NULL COMMENT 'Idempotency-Key enviada por el cliente',
  request_hash CHAR(64) NOT NULL COMMENT 'SHA-256 del cuerpo normalizado; detecta reutilización con contenido distinto',
  status ENUM('PROCESSING','COMPLETED','FAILED') NOT NULL DEFAULT 'PROCESSING',
  response_status SMALLINT UNSIGNED NULL COMMENT 'Código HTTP original para rejugar',
  response_json JSON NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_actor_op_key (actor_id, operation, `key`),
  KEY idx_idempotency_created (created_at) COMMENT 'Limpieza de registros antiguos por cron',
  CONSTRAINT fk_idempotency_actor FOREIGN KEY (actor_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de claves de idempotencia; mismo efecto y misma respuesta al repetir';

-- =============================================================================
-- DOWN (reversión manual, ejecutar solo tras respaldo; orden inverso)
-- DROP TABLE IF EXISTS idempotency_requests;
-- DROP TABLE IF EXISTS sequences;
-- DROP TABLE IF EXISTS settings;
-- DROP TABLE IF EXISTS audit_logs;
-- DROP TABLE IF EXISTS login_attempts;
-- DROP TABLE IF EXISTS password_resets;
-- DROP TABLE IF EXISTS api_tokens;
-- DROP TABLE IF EXISTS users;
-- DROP TABLE IF EXISTS roles;
-- DROP TABLE IF EXISTS schema_migrations;
-- =============================================================================
