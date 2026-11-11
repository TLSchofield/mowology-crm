-- Migration 1172: Charlie (Foreman) — conflict rules between the heads
-- Date: 2026-10-06
-- Purpose: when two heads' proposals collide, Tim's rules decide (ConflictRules.php).
--   Rules are editable on /crm/foreman_calendar_appstack.php; every ruling is logged and
--   visible; an Override releases the proposal and is logged too; a rule overridden twice
--   in 60 days gets a proposed rewrite (a Charlie question).
--     charlie_rules   — Tim's rules, their numbers (params: json-encoded text), on/off
--     charlie_rulings — every hold / escalation, one row per rule, proposal and day
-- Live now: collections_first, one_message_week. Seeded OFF with what they wait for:
--   protected_time, margin_floor, route_full (phases B3/B4).
-- MySQL 5.7 compatible (params is TEXT decoded in PHP, no JSON functions).

CREATE TABLE IF NOT EXISTS charlie_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  title VARCHAR(120) NOT NULL,
  text VARCHAR(500) NOT NULL,
  params TEXT NULL,
  escalate_text VARCHAR(255) NULL,
  never_escalate TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  phase VARCHAR(10) NOT NULL DEFAULT 'now' COMMENT 'now | later',
  needs VARCHAR(255) NULL COMMENT 'what a later rule is waiting for',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_rule_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS charlie_rulings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rule_slug VARCHAR(40) NOT NULL,
  proposal_key VARCHAR(120) NOT NULL,
  head VARCHAR(20) NULL,
  contact_id INT NULL,
  verdict VARCHAR(12) NOT NULL COMMENT 'held | escalated',
  reason VARCHAR(500) NOT NULL,
  detail TEXT NULL COMMENT 'json-encoded facts behind the ruling (days late, days since)',
  ruling_date DATE NOT NULL,
  overridden_at DATETIME NULL,
  overridden_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_ruling (rule_slug, proposal_key, ruling_date),
  INDEX idx_charlie_ruling_override (rule_slug, overridden_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO charlie_rules (slug, title, text, params, escalate_text, never_escalate, enabled, phase, needs) VALUES
('collections_first', 'Collections before upsell',
  'Collections before upsell: no sales message to a client while one of their invoices is more than 14 days late.',
  '{"late_days":14}', NULL, 1, 1, 'now', NULL),
('one_message_week', 'One message per client per week',
  'One message per client per week — the highest-priority one goes, the rest wait.',
  '{"window_days":7}', 'Replies to a customer who wrote in, legal notices and contract copies always go.', 0, 1, 'now', NULL),
('protected_time', 'Protected time wins',
  'New job vs protected time: protected time wins, offer the next slot.',
  NULL, 'An existing contract at risk.', 0, 0, 'later', 'A protected-time calendar (none yet) and Otto proposing schedule changes'),
('margin_floor', 'No discounts below the margin floor',
  'Discount below the margin floor: block it and propose the next tier.',
  NULL, 'A dense street with near-zero drive cost.', 0, 0, 'later', 'A margin-floor setting and Sam proposing priced quotes'),
('route_full', 'Route full: pause the ads there',
  'Route full: pause paid ads and neighbour offers for that area, keep reviews and retention.',
  NULL, 'A high-value commercial lead.', 0, 0, 'later', 'Otto''s capacity by area, Mia''s neighbour offers and a paid-ads head');
