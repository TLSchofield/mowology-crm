-- Migration 519: Create visit_gps_points and visit_audit_log
-- Migration 700 used DELIMITER // stored procedures which are incompatible with the
-- migration runner's PDO exec approach. These two tables were never created as a result.

CREATE TABLE IF NOT EXISTS visit_gps_points (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    visit_id    INT NOT NULL,
    ts          DATETIME NOT NULL,
    lat         DECIMAL(10,8) NOT NULL,
    lng         DECIMAL(11,8) NOT NULL,
    accuracy_m  DECIMAL(8,2) NULL,
    speed_mps   DECIMAL(6,2) NULL,
    heading     DECIMAL(5,2) NULL,
    altitude_m  DECIMAL(8,2) NULL,
    source      VARCHAR(30) DEFAULT 'gps',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vgp_visit (visit_id),
    INDEX idx_vgp_visit_ts (visit_id, ts),
    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS visit_audit_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    visit_id     INT NOT NULL,
    user_id      INT NOT NULL,
    action       VARCHAR(60) NOT NULL,
    payload_json TEXT NULL,
    ip_address   VARCHAR(45) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_val_visit (visit_id),
    INDEX idx_val_action (action),
    INDEX idx_val_user (user_id),
    INDEX idx_val_created (created_at),
    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
