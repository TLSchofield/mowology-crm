-- Migration 206: Add yearly recurrence support + plan crew assignments
-- Date: 2026-02-17

-- 1. Add 'yearly' to recurrence_pattern ENUM
ALTER TABLE job_plans
  MODIFY recurrence_pattern ENUM('weekly', 'biweekly', 'monthly', 'yearly', 'custom') NULL;

-- 2. Add 'years' to recurrence_interval_unit ENUM
ALTER TABLE job_plans
  MODIFY recurrence_interval_unit ENUM('days', 'weeks', 'months', 'years') DEFAULT 'weeks';

-- 3. Create plan_crew_assignments junction table for multi-staff assignment
CREATE TABLE plan_crew_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(50) DEFAULT 'crew' COMMENT 'crew or lead',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_plan_user (plan_id, user_id),
    INDEX idx_plan (plan_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (plan_id) REFERENCES job_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
