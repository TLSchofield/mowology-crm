-- Migration 516: Gamification Engine — Crew Teams, Scoring, Badges
-- Date: 2026-02-20
-- MySQL 5.7+ compatible (no window functions, no JSON functions, no IF NOT EXISTS on indexes)

-- ── 1. Permanent crew teams (admin-defined, not dynamic) ──────────────────────

CREATE TABLE IF NOT EXISTS crew_teams (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    colour      VARCHAR(7)   NOT NULL DEFAULT '#2D8659' COMMENT 'Hex colour for UI',
    emoji       VARCHAR(10)  NULL     COMMENT 'Optional team emoji',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 2. Team membership (user → team, many-to-one) ─────────────────────────────

CREATE TABLE IF NOT EXISTS crew_team_members (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    team_id    INT NOT NULL,
    user_id    INT NOT NULL,
    joined_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_team_user (team_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_team (team_id),

    FOREIGN KEY (team_id) REFERENCES crew_teams(id)  ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 3. Weekly team scores ─────────────────────────────────────────────────────
-- One row per team per ISO week. Recalculated by cron each Sunday night.

CREATE TABLE IF NOT EXISTS crew_weekly_scores (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    team_id             INT  NOT NULL,
    week_start          DATE NOT NULL COMMENT 'Monday of the scored week (ISO)',

    -- Component scores (0-100 each)
    score_ocr_accuracy  DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'OCR match_confidence avg',
    score_speed         DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Submission within 4h of job',
    score_photos        DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Receipt photo attach rate',
    score_anomaly_free  DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Low-anomaly rate',
    score_budget        DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Under-budget vs estimate',

    -- Composite weighted total (0-100)
    total_score         DECIMAL(5,2) NOT NULL DEFAULT 0,

    -- Raw counters for transparency
    expenses_submitted  INT NOT NULL DEFAULT 0,
    expenses_with_photo INT NOT NULL DEFAULT 0,
    expenses_anomalous  INT NOT NULL DEFAULT 0,
    expenses_on_budget  INT NOT NULL DEFAULT 0,
    avg_ocr_confidence  DECIMAL(5,2) NULL,
    avg_submission_hours DECIMAL(6,2) NULL,

    calculated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_team_week (team_id, week_start),
    INDEX idx_week (week_start),
    INDEX idx_total (total_score),

    FOREIGN KEY (team_id) REFERENCES crew_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 4. Badge definitions ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS crew_badges (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Machine key: e.g. perfect_week',
    name        VARCHAR(100) NOT NULL,
    description TEXT         NULL,
    icon        VARCHAR(50)  NOT NULL COMMENT 'Feather icon name or emoji',
    colour      VARCHAR(7)   NOT NULL DEFAULT '#2D8659',
    tier        ENUM('bronze','silver','gold','platinum') NOT NULL DEFAULT 'bronze',
    criteria    TEXT         NULL COMMENT 'Human-readable earning criteria',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 5. Badge awards (team earns badge) ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS crew_badge_awards (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    badge_id    INT  NOT NULL,
    team_id     INT  NOT NULL,
    awarded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    week_start  DATE NULL COMMENT 'Week that triggered the award (if weekly badge)',
    note        TEXT NULL,

    INDEX idx_badge  (badge_id),
    INDEX idx_team   (team_id),
    INDEX idx_week   (week_start),

    FOREIGN KEY (badge_id) REFERENCES crew_badges(id)  ON DELETE CASCADE,
    FOREIGN KEY (team_id)  REFERENCES crew_teams(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 6. Seed badge definitions ────────────────────────────────────────────────

INSERT IGNORE INTO crew_badges (slug, name, description, icon, colour, tier, criteria) VALUES
('perfect_week',    'Perfect Week',      'Score 100/100 for a full week',                         'award',      '#f59e0b', 'gold',     'Total score = 100 for any ISO week'),
('speed_demon',     'Speed Demon',       'All receipts submitted within 1h of purchase all week', 'zap',        '#3b82f6', 'silver',   'avg_submission_hours < 1.0 for the week'),
('clean_sheet',     'Clean Sheet',       'Zero anomaly flags all week',                           'shield',     '#10b981', 'gold',     'expenses_anomalous = 0 for the week'),
('photo_pro',       'Photo Pro',         '100% receipt photo attach rate for the week',           'camera',     '#8b5cf6', 'silver',   'expenses_with_photo = expenses_submitted'),
('budget_hero',     'Budget Hero',       'Under material budget on every linked job all week',    'trending-up','#2D8659', 'gold',     'score_budget = 100 for the week'),
('first_team',      'First Team',        'Rank #1 on the leaderboard for 4+ consecutive weeks',  'star',       '#f59e0b', 'platinum', 'Rank 1 for 4 consecutive weeks'),
('consistent',      'Consistent',        'Score 80+ for 8 consecutive weeks',                     'check-circle','#6b7280','silver',   'total_score >= 80 for 8 straight weeks');
