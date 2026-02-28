-- Migration 120: Employee Time Clock & Job Timer System
-- Creates tables for shift tracking, job-level time entries, timesheets, and settings

-- ============================================================
-- 1. time_clock_entries — Global shift clock-in/clock-out
-- ============================================================
CREATE TABLE time_clock_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    clock_in_lat DECIMAL(10,7) NULL,
    clock_in_lng DECIMAL(10,7) NULL,
    clock_out_lat DECIMAL(10,7) NULL,
    clock_out_lng DECIMAL(10,7) NULL,
    total_minutes INT NULL,
    notes TEXT NULL,
    status ENUM('active','completed','edited','void') NOT NULL DEFAULT 'active',
    edited_by INT NULL,
    edited_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, clock_in),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 2. job_time_entries — Per-job timer entries
-- ============================================================
CREATE TABLE job_time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    user_id INT NOT NULL,
    clock_entry_id INT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT NULL,
    start_lat DECIMAL(10,7) NULL,
    start_lng DECIMAL(10,7) NULL,
    end_lat DECIMAL(10,7) NULL,
    end_lng DECIMAL(10,7) NULL,
    auto_started TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status ENUM('active','completed','edited','void') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job (job_id),
    INDEX idx_user_date (user_id, start_time),
    INDEX idx_clock_entry (clock_entry_id),
    INDEX idx_status (status),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (clock_entry_id) REFERENCES time_clock_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 3. timesheets — Weekly aggregated timesheets for approval
-- ============================================================
CREATE TABLE timesheets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    total_shift_minutes INT NOT NULL DEFAULT 0,
    total_job_minutes INT NOT NULL DEFAULT 0,
    total_travel_minutes INT NOT NULL DEFAULT 0,
    status ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'pending',
    submitted_at DATETIME NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    reviewer_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_week (user_id, week_start),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 4. time_clock_settings — Admin-configurable settings
-- ============================================================
CREATE TABLE time_clock_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default settings
INSERT INTO time_clock_settings (setting_key, setting_value) VALUES
('enabled_roles', 'admin,manager,user'),
('gps_proximity_meters', '150'),
('auto_clock_out_hours', '12'),
('require_gps_for_clock_in', '0'),
('require_gps_for_job_start', '1');
