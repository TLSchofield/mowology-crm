-- Migration 992: Visit crew assignments — multi-staff per visit
-- Date: 2026-05-01

CREATE TABLE IF NOT EXISTS visit_crew_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(50) DEFAULT 'crew' COMMENT 'crew or lead',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_visit_user (visit_id, user_id),
    INDEX idx_visit (visit_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
