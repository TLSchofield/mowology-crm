-- ============================================================
-- Migration 1032: Social Campaign Scheduler
-- Adds smart auto-scheduling / campaign system to social posts
-- ============================================================

-- Recurring campaign definitions
CREATE TABLE IF NOT EXISTS social_schedules (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    source_post_id      INT NOT NULL,
    name                VARCHAR(255) NOT NULL DEFAULT '',
    service_type        VARCHAR(100) NULL,
    cadence_type        ENUM('seasonal','fixed') NOT NULL DEFAULT 'seasonal',
    cadence_days        INT NULL,                   -- for fixed: every N days
    lookforward_months  TINYINT NOT NULL DEFAULT 3,
    posts_per_month_json TEXT NULL,                 -- JSON override of monthly counts
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    last_generated_date DATE NULL,
    created_by          INT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Learned / seeded optimal posting time slots per service type + platform
CREATE TABLE IF NOT EXISTS social_time_slots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    service_type    VARCHAR(100) NULL,      -- NULL = all types
    platform        VARCHAR(50)  NULL,      -- NULL = all platforms
    day_of_week     TINYINT NOT NULL,       -- 0=Sun … 6=Sat
    hour            TINYINT NOT NULL,       -- 0-23
    score           DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    sample_count    INT NOT NULL DEFAULT 0,
    is_seed         TINYINT(1) NOT NULL DEFAULT 1, -- 1 = industry default, 0 = learned
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slot (service_type, platform, day_of_week, hour),
    KEY idx_score (service_type, score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Link auto-generated posts back to their schedule
ALTER TABLE social_posts
    ADD COLUMN schedule_id       INT NULL DEFAULT NULL AFTER template_id,
    ADD COLUMN is_trial          TINYINT(1) NOT NULL DEFAULT 0 AFTER schedule_id,
    ADD COLUMN is_auto_scheduled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_trial,
    ADD KEY idx_schedule (schedule_id),
    ADD CONSTRAINT fk_sp_schedule FOREIGN KEY (schedule_id) REFERENCES social_schedules(id) ON DELETE SET NULL;

-- ── Seed default time slots for landscaping (Vancouver market) ──────────
-- Scores are relative weights; higher = better slot
-- Source: local service industry benchmarks (Tue-Sat, morning + evening peaks)
INSERT INTO social_time_slots (service_type, platform, day_of_week, hour, score, sample_count, is_seed) VALUES
-- All service types, all platforms (fallback)
(NULL, NULL, 6,  9, 2.8, 0, 1),  -- Sat 9am  (homeowners planning weekend)
(NULL, NULL, 4, 19, 2.5, 0, 1),  -- Thu 7pm  (evening inspiration)
(NULL, NULL, 3, 11, 2.3, 0, 1),  -- Wed 11am (mid-week browse)
(NULL, NULL, 5,  8, 2.1, 0, 1),  -- Fri 8am  (TGIF mindset)
(NULL, NULL, 2, 18, 2.0, 0, 1),  -- Tue 6pm  (settle-in)
(NULL, NULL, 0, 10, 1.8, 0, 1),  -- Sun 10am (weekend morning)
(NULL, NULL, 1,  9, 1.5, 0, 1),  -- Mon 9am  (week-ahead planning)
-- Hedge trimming specific (more weekend-weighted — visual transformation)
('Hedge Trimming', NULL, 6,  9, 3.5, 0, 1),
('Hedge Trimming', NULL, 0, 10, 3.0, 0, 1),
('Hedge Trimming', NULL, 4, 19, 2.8, 0, 1),
('Hedge Trimming', NULL, 3, 11, 2.5, 0, 1),
('Hedge Trimming', NULL, 5,  8, 2.3, 0, 1),
('Hedge Trimming', NULL, 2, 18, 2.0, 0, 1),
('Hedge Trimming', NULL, 1,  9, 1.7, 0, 1),
-- Lawn Care (high frequency — needs spread across week)
('Lawn Care', NULL, 2,  8, 3.2, 0, 1),
('Lawn Care', NULL, 4, 17, 3.0, 0, 1),
('Lawn Care', NULL, 6,  9, 2.9, 0, 1),
('Lawn Care', NULL, 3, 12, 2.6, 0, 1),
('Lawn Care', NULL, 1, 18, 2.3, 0, 1),
('Lawn Care', NULL, 5,  8, 2.1, 0, 1),
('Lawn Care', NULL, 0, 10, 1.9, 0, 1),
-- Strata / Commercial
('General Landscaping', NULL, 1,  8, 3.0, 0, 1),
('General Landscaping', NULL, 3,  8, 2.8, 0, 1),
('General Landscaping', NULL, 5,  8, 2.5, 0, 1),
('General Landscaping', NULL, 2, 17, 2.2, 0, 1),
('General Landscaping', NULL, 4, 17, 2.0, 0, 1),
('General Landscaping', NULL, 6, 10, 1.8, 0, 1),
('General Landscaping', NULL, 0, 11, 1.5, 0, 1)
ON DUPLICATE KEY UPDATE score = VALUES(score), is_seed = 1;
