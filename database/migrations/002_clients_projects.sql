-- =============================================================================
-- Migración 002 — Clientes, proyectos, etapas, tareas y cargos
-- Fecha: 2026-10-27
-- Autor: arquitecto-datos
-- Requisito: §7 (clientes), §8 (proyectos/etapas/tareas/cargos), §14, D-02
-- Propósito:
--   clients, projects, project_members, project_stages, tasks, task_comments,
--   project_charges.
-- Reversión: bloque DOWN al final del archivo (orden inverso de creación).
-- Notas de diseño:
--   * `tasks` NO tiene project_id: el proyecto se deriva de la etapa. Así es
--     imposible una tarea con etapa de otro proyecto o con proyecto y sin etapa
--     (§8). Una tarea con stage_id NULL es independiente.
--   * Los cargos adicionales no crean cobro (D-02): solo suman valor comercial.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- clients: registros comerciales, nunca cuentas de acceso (§1, §7).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('NORMAL','BANDA') NOT NULL DEFAULT 'NORMAL',
  name VARCHAR(160) NOT NULL COMMENT 'Nombre comercial o nombre de la banda',
  contact_name VARCHAR(120) NULL COMMENT 'Contacto principal',
  representative_name VARCHAR(120) NULL COMMENT 'Representante de la banda (§7)',
  phone VARCHAR(32) NULL COMMENT 'No es único: dos clientes pueden compartir teléfono (§7)',
  email VARCHAR(180) NULL COMMENT 'No es único por la misma razón',
  address VARCHAR(255) NULL,
  notes TEXT NULL COMMENT 'Incluye integrantes de la banda como dato opcional (§7)',
  active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Baja lógica; conserva historia comercial',
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clients_name (name) COMMENT 'Búsqueda de duplicados y directorio por nombre (§7)',
  KEY idx_clients_type_active (type, active) COMMENT 'Listado de bandas activas para el flujo de reservas (§12)',
  KEY idx_clients_phone (phone) COMMENT 'Advertencia de duplicado por teléfono (§7)',
  KEY idx_clients_email (email) COMMENT 'Advertencia de duplicado por correo (§7)',
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_clients_band_data CHECK (
    type <> 'BANDA' OR (representative_name IS NOT NULL AND phone IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Clientes NORMAL/BANDA; borrado lógico únicamente';

-- -----------------------------------------------------------------------------
-- projects: unidad de trabajo y de cobro. Folio por secuencia (§14).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio VARCHAR(24) NOT NULL COMMENT 'PRY-2026-000123, generado por sequences',
  type ENUM('INTERNO','EXTERNO') NOT NULL,
  client_id BIGINT UNSIGNED NULL,
  billable TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Condición cobrable, independiente de interno/externo (§2.2)',
  leader_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NOT NULL COMMENT 'Obligatoria por §8; por eso no admite NULL',
  status ENUM('BORRADOR','PLANIFICADO','EN_CURSO','PAUSADO','FINALIZADO','CANCELADO') NOT NULL DEFAULT 'BORRADOR',
  priority ENUM('BAJA','MEDIA','ALTA','URGENTE') NOT NULL DEFAULT 'MEDIA',
  planned_start DATE NULL,
  planned_end DATE NULL,
  actual_start DATE NULL,
  actual_end DATE NULL,
  base_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Importe base; los cargos se suman aparte (D-02)',
  currency CHAR(3) NOT NULL DEFAULT 'MXN',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_folio (folio),
  KEY idx_projects_leader_status (leader_id, status) COMMENT '§14: proyectos que lidera un usuario, filtrados por estado',
  KEY idx_projects_client_status (client_id, status) COMMENT 'Ficha de cliente: proyectos del cliente por estado (§7)',
  KEY idx_projects_status_planned_end (status, planned_end) COMMENT 'Listados de proyectos activos y vencimientos de agenda (§13)',
  KEY idx_projects_type_status (type, status) COMMENT 'Dashboard: importe comercial de externos/cobrables (§11)',
  CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_projects_leader FOREIGN KEY (leader_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_projects_external_document CHECK (
    type <> 'EXTERNO' OR (client_id IS NOT NULL AND base_amount > 0)
  ),
  CONSTRAINT chk_projects_base_amount CHECK (base_amount >= 0),
  CONSTRAINT chk_projects_planned_dates CHECK (
    planned_start IS NULL OR planned_end IS NULL OR planned_end >= planned_start
  ),
  CONSTRAINT chk_projects_actual_dates CHECK (
    actual_start IS NULL OR actual_end IS NULL OR actual_end >= actual_start
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Proyectos internos/externos; finalizar no liquida el pago (§8)';

-- -----------------------------------------------------------------------------
-- project_members: participantes del proyecto. Otorga visibilidad y permite
-- asignar tareas de proyecto (§8).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_members (project_id, user_id),
  KEY idx_project_members_user (user_id, project_id) COMMENT 'Mis proyectos: membresías del usuario (§15 GET /projects)',
  CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_project_members_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Membresías; la baja se hace borrando la fila de la relación (no es documento comercial)';

-- -----------------------------------------------------------------------------
-- project_stages: etapas ordenables sin cambiar identificadores (§8).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_stages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Reordenar = actualizar sort_order, nunca el id',
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('PENDIENTE','EN_CURSO','COMPLETADA','CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_stages_project (project_id, sort_order) COMMENT 'Gantt y listado de etapas en orden (§9)',
  CONSTRAINT fk_project_stages_project FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_project_stages_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_project_stages_dates CHECK (
    start_date IS NULL OR end_date IS NULL OR end_date >= start_date
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Etapas del proyecto; sus fechas deben caber en el proyecto (validación de servicio)';

-- -----------------------------------------------------------------------------
-- tasks: tarea de proyecto (stage_id) o tarea independiente (stage_id NULL).
-- El proyecto se deriva de la etapa: no existe columna project_id (§8, §14).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stage_id BIGINT UNSIGNED NULL COMMENT 'NULL = tarea independiente creada por el administrador',
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  assignee_id BIGINT UNSIGNED NOT NULL,
  priority ENUM('BAJA','MEDIA','ALTA','URGENTE') NOT NULL DEFAULT 'MEDIA',
  status ENUM('PENDIENTE','EN_CURSO','BLOQUEADA','COMPLETADA','CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Porcentaje 0-100 del avance operativo, no financiero',
  start_date DATE NULL,
  end_date DATE NULL,
  actual_start DATE NULL,
  actual_end DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tasks_assignee_status_end (assignee_id, status, end_date) COMMENT '§14: mis tareas y tareas vencidas/próximas (§13, §17)',
  KEY idx_tasks_stage_status (stage_id, status) COMMENT 'Avance de la etapa: tareas no canceladas de la etapa (§8)',
  KEY idx_tasks_status_end (status, end_date) COMMENT 'Agenda e hitos del proyecto por rango de fechas (§13)',
  CONSTRAINT fk_tasks_stage FOREIGN KEY (stage_id) REFERENCES project_stages (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_tasks_progress CHECK (progress <= 100),
  CONSTRAINT chk_tasks_planned_dates CHECK (
    start_date IS NULL OR end_date IS NULL OR end_date >= start_date
  ),
  CONSTRAINT chk_tasks_actual_dates CHECK (
    actual_start IS NULL OR actual_end IS NULL OR actual_end >= actual_start
  ),
  CONSTRAINT chk_tasks_completed_progress CHECK (status <> 'COMPLETADA' OR progress = 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tarea con etapa o independiente; nunca con proyecto explícito';

-- -----------------------------------------------------------------------------
-- task_comments: comentarios cronológicos de una tarea (§8).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_task_comments_task (task_id, created_at) COMMENT 'Hilo de la tarea en orden cronológico (§15 GET /tasks/{id}/comments)',
  CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tasks (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_task_comments_author FOREIGN KEY (author_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comentarios inmutables de tarea';

-- -----------------------------------------------------------------------------
-- project_charges: cargos adicionales. Aumentan el valor comercial, NO son cobros
-- ni cuentas por cobrar por sí mismos (D-02, §8).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_charges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  concept VARCHAR(200) NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL COMMENT 'Importe congelado (redondeo por partida)',
  charge_date DATE NOT NULL,
  status ENUM('ACTIVO','ANULADO') NOT NULL DEFAULT 'ACTIVO' COMMENT 'Cobrado/pendiente se deriva de payments, no se almacena',
  author_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_charges_project (project_id, charge_date) COMMENT 'Cargos del proyecto para valor comercial (§11)',
  CONSTRAINT fk_project_charges_project FOREIGN KEY (project_id) REFERENCES projects (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_project_charges_author FOREIGN KEY (author_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_project_charges_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_project_charges_quantity CHECK (quantity > 0),
  CONSTRAINT chk_project_charges_unit_price CHECK (unit_price >= 0),
  CONSTRAINT chk_project_charges_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cargos adicionales de proyecto; nunca generan pago';

-- =============================================================================
-- DOWN (reversión manual, orden inverso)
-- DROP TABLE IF EXISTS project_charges;
-- DROP TABLE IF EXISTS task_comments;
-- DROP TABLE IF EXISTS tasks;
-- DROP TABLE IF EXISTS project_stages;
-- DROP TABLE IF EXISTS project_members;
-- DROP TABLE IF EXISTS projects;
-- DROP TABLE IF EXISTS clients;
-- =============================================================================
