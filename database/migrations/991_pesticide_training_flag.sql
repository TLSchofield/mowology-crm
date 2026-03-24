-- ============================================================================
-- Migration 991: BC Pesticide Applicator Training System
-- Adds per-user pesticide training toggle and creates the BC Pesticide
-- Applicator Certificate course in the existing cert system (Tier 2).
-- MySQL 5.7 compatible. Run OUTSIDE a transaction (DDL auto-commits).
-- ============================================================================

-- ── 1. Add pesticide_training_required flag to users ──────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS pesticide_training_required TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = this employee is required to complete BC Pesticide Applicator training';

-- ── 2. Add BC Pesticide Applicator quiz category ──────────────────────────
INSERT IGNORE INTO quiz_categories (name, description, icon, colour, sort_order)
VALUES (
    'BC Pesticide Applicator',
    'BC provincial pesticide applicator certificate exam preparation — all 10 exam matter domains.',
    'shield',
    '#e85d04',
    70
);

-- ── 3. Add BC Pesticide Applicator Certificate course (Tier 2 — Pesticide) ─
INSERT IGNORE INTO cert_courses
    (tier_id, name, slug, description, icon, colour,
     pass_score_pct, min_questions, max_questions,
     attempts_allowed, cooldown_hours, recert_months,
     is_required, sort_order)
SELECT
    t.id,
    'BC Pesticide Applicator Certificate',
    'bc-pesticide-applicator',
    'Prep for the BC provincial pesticide applicator certificate exam. Covers all 10 exam-matter domains: General Characteristics, Act & Regulations, Labelling, Human Health, Pesticide Safety, Environment, Pest Management, Application Technology, Emergency Response, and Professionalism.',
    'shield',
    '#e85d04',
    80,   -- pass score
    20,   -- min questions per session
    40,   -- max questions per session
    5,    -- attempts allowed
    48,   -- cooldown hours between attempts
    12,   -- recert months
    0,    -- not globally required — toggled per user
    35    -- sort after existing pesticide courses
FROM cert_tiers t WHERE t.slug = 'pesticide'
LIMIT 1;
