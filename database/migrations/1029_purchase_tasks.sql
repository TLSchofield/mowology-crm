-- Migration 1029 — Purchase Tasks (Procurement Workflow)
-- ─────────────────────────────────────────────────────
-- Adds the purchase_tasks and purchase_task_items tables to support
-- the crew procurement workflow visible on the schedule page.
--
-- A purchase task represents a vendor run or supply pickup assigned
-- to a crew member on a specific date. Items are the individual
-- products/supplies to be picked up.
--
-- Run via: /crm/api/run-migration-1029.php

CREATE TABLE IF NOT EXISTS purchase_tasks (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    task_number         VARCHAR(20)  NOT NULL DEFAULT '',
    title               VARCHAR(255) NOT NULL DEFAULT '',
    task_date           DATE         NOT NULL,
    vendor_name         VARCHAR(255) NULL,
    location_address    VARCHAR(512) NULL,
    location_label      VARCHAR(255) NULL,
    lat                 DECIMAL(10,7) NULL,
    lng                 DECIMAL(11,7) NULL,
    purchase_status     ENUM('pending','in_transit','purchased','cancelled') NOT NULL DEFAULT 'pending',
    procurement_mode    ENUM('vendor_run','supplier_pickup','online_order','other') NOT NULL DEFAULT 'vendor_run',
    priority            ENUM('normal','urgent','low') NOT NULL DEFAULT 'normal',
    estimated_total     DECIMAL(10,2) NULL DEFAULT NULL,
    assigned_to_id      INT NULL DEFAULT NULL,
    notes               TEXT NULL DEFAULT NULL,
    created_by_id       INT NULL DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_pt_task_date      (task_date),
    INDEX idx_pt_assigned_to    (assigned_to_id),
    INDEX idx_pt_status         (purchase_status),
    INDEX idx_pt_created_by     (created_by_id),

    FOREIGN KEY fk_pt_assigned  (assigned_to_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY fk_pt_created   (created_by_id)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Crew vendor runs and supply pickups visible on the schedule.';

CREATE TABLE IF NOT EXISTS purchase_task_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    task_id         INT          NOT NULL,
    description     VARCHAR(512) NOT NULL DEFAULT '',
    quantity        DECIMAL(8,2) NOT NULL DEFAULT 1,
    unit            VARCHAR(50)  NULL,
    unit_price      DECIMAL(10,2) NULL DEFAULT NULL,
    is_purchased    TINYINT(1)   NOT NULL DEFAULT 0,
    notes           VARCHAR(512) NULL,
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_pti_task      (task_id),
    INDEX idx_pti_purchased (is_purchased),

    FOREIGN KEY fk_pti_task (task_id) REFERENCES purchase_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Individual items within a purchase task (supply list).';
