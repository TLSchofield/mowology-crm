<?php
/**
 * One-time migration runner — Migration 990
 * Crew Certification System: tiers, courses, job-type gating, variant questions.
 *
 * Run once at: https://mowology.ca/crm/api/run-migration-quiz-certification.php
 * Requires CRM admin login.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (($user = getCurrentUser())['role'] !== 'admin') {
    http_response_code(403); echo 'Admin only'; exit;
}

header('Content-Type: text/plain; charset=utf-8');
session_write_close();
$db = getDB();

$statements = [

// 1 — cert_tiers
"CREATE TABLE IF NOT EXISTS cert_tiers (
    id              INT          AUTO_INCREMENT PRIMARY KEY,
    tier_level      TINYINT      NOT NULL,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(50)  NOT NULL,
    description     TEXT         NULL,
    icon            VARCHAR(50)  NOT NULL DEFAULT 'award',
    colour          VARCHAR(7)   NOT NULL DEFAULT '#2D8659',
    pass_score_pct  TINYINT      NOT NULL DEFAULT 80,
    recert_months   INT          NOT NULL DEFAULT 12,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tier_level (tier_level),
    UNIQUE KEY uq_tier_slug  (slug),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 2 — cert_courses
"CREATE TABLE IF NOT EXISTS cert_courses (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    tier_id          INT          NOT NULL,
    name             VARCHAR(150) NOT NULL,
    slug             VARCHAR(100) NOT NULL,
    description      TEXT         NULL,
    icon             VARCHAR(50)  NOT NULL DEFAULT 'book-open',
    colour           VARCHAR(7)   NOT NULL DEFAULT '#2D8659',
    pass_score_pct   TINYINT      NOT NULL DEFAULT 80,
    min_questions    TINYINT      NOT NULL DEFAULT 10,
    max_questions    TINYINT      NOT NULL DEFAULT 25,
    attempts_allowed TINYINT      NOT NULL DEFAULT 3,
    cooldown_hours   INT          NOT NULL DEFAULT 24,
    recert_months    INT          NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    is_required      TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order       INT          NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_slug (slug),
    INDEX idx_tier   (tier_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 3 — cert_course_questions
"CREATE TABLE IF NOT EXISTS cert_course_questions (
    id          INT        AUTO_INCREMENT PRIMARY KEY,
    course_id   INT        NOT NULL,
    question_id INT        NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    weight      TINYINT    NOT NULL DEFAULT 1,
    added_at    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    added_by    INT        NULL,
    UNIQUE KEY uk_course_question (course_id, question_id),
    INDEX idx_course   (course_id),
    INDEX idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 4 — cert_service_type_requirements
"CREATE TABLE IF NOT EXISTS cert_service_type_requirements (
    id               INT       AUTO_INCREMENT PRIMARY KEY,
    service_type_id  INT       NOT NULL,
    min_tier_level   TINYINT   NOT NULL DEFAULT 1,
    course_id        INT       NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_service_course (service_type_id, course_id),
    INDEX idx_service (service_type_id),
    INDEX idx_tier    (min_tier_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 5 — cert_exam_sessions
"CREATE TABLE IF NOT EXISTS cert_exam_sessions (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    user_id          INT          NOT NULL,
    course_id        INT          NOT NULL,
    question_ids     VARCHAR(1024) NOT NULL,
    questions_count  TINYINT      NOT NULL DEFAULT 0,
    correct_count    INT          NOT NULL DEFAULT 0,
    score_pct        TINYINT      NOT NULL DEFAULT 0,
    passed           TINYINT(1)   NULL,
    attempt_number   TINYINT      NOT NULL DEFAULT 1,
    started_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at     TIMESTAMP    NULL,
    time_limit_secs  INT          NULL,
    INDEX idx_user        (user_id),
    INDEX idx_course      (course_id),
    INDEX idx_user_course (user_id, course_id),
    INDEX idx_passed      (user_id, passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 6 — cert_exam_answers
"CREATE TABLE IF NOT EXISTS cert_exam_answers (
    id                  INT        AUTO_INCREMENT PRIMARY KEY,
    exam_session_id     INT        NOT NULL,
    question_id         INT        NOT NULL,
    selected_option_id  INT        NULL,
    is_correct          TINYINT(1) NOT NULL DEFAULT 0,
    time_taken_seconds  INT        NOT NULL DEFAULT 0,
    answered_at         TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exam     (exam_session_id),
    INDEX idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 7 — cert_records
"CREATE TABLE IF NOT EXISTS cert_records (
    id               INT         AUTO_INCREMENT PRIMARY KEY,
    user_id          INT         NOT NULL,
    course_id        INT         NOT NULL,
    tier_id          INT         NOT NULL,
    exam_session_id  INT         NULL,
    status           ENUM('active','expired','revoked','pending') NOT NULL DEFAULT 'active',
    best_score_pct   TINYINT     NOT NULL DEFAULT 0,
    issued_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at       TIMESTAMP   NULL,
    revoked_at       TIMESTAMP   NULL,
    revoked_by       INT         NULL,
    revoke_reason    VARCHAR(255) NULL,
    recert_notified_at TIMESTAMP NULL,
    UNIQUE KEY uk_user_course (user_id, course_id),
    INDEX idx_user    (user_id),
    INDEX idx_course  (course_id),
    INDEX idx_status  (status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 8 — cert_tier_achievements
"CREATE TABLE IF NOT EXISTS cert_tier_achievements (
    id          INT       AUTO_INCREMENT PRIMARY KEY,
    user_id     INT       NOT NULL,
    tier_id     INT       NOT NULL,
    achieved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_tier (user_id, tier_id),
    INDEX idx_user (user_id),
    INDEX idx_tier (tier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 9 — quiz_question_variants
"CREATE TABLE IF NOT EXISTS quiz_question_variants (
    id            INT        AUTO_INCREMENT PRIMARY KEY,
    parent_id     INT        NOT NULL,
    variant_type  ENUM('rephrase','reverse','application','customer_explanation') NOT NULL DEFAULT 'rephrase',
    question_text TEXT       NOT NULL,
    learn_notes   TEXT       NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_by    INT        NULL,
    created_at    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent (parent_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 10 — quiz_variant_options
"CREATE TABLE IF NOT EXISTS quiz_variant_options (
    id                            INT          AUTO_INCREMENT PRIMARY KEY,
    variant_id                    INT          NOT NULL,
    option_text                   VARCHAR(255) NOT NULL,
    is_correct                    TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order                    INT          NOT NULL DEFAULT 0,
    distractor_source_question_id INT          NULL,
    INDEX idx_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

// 11 — seed cert_tiers
"INSERT IGNORE INTO cert_tiers (tier_level, name, slug, description, icon, colour, pass_score_pct, recert_months, sort_order) VALUES
    (1, 'Tier 1 — Basic Lawn Care',       'basic',      'Core lawn mowing, safety, equipment, and basic weed/pest ID.', 'star',   '#2D8659', 80, 12, 10),
    (2, 'Tier 2 — Pesticide Application', 'pesticide',  'Chemical application, WHMIS 2015, PPE, and Acelepryn cert.',   'shield', '#e85d04', 85, 12, 20),
    (3, 'Tier 3 — Crew Supervisor',       'supervisor', 'Lead jobs, advanced plant/disease ID, client communication.',  'award',  '#1A5F4A', 90, 24, 30)",

// 12 — seed cert_courses
"INSERT IGNORE INTO cert_courses (tier_id, name, slug, description, pass_score_pct, min_questions, max_questions, attempts_allowed, cooldown_hours, recert_months, is_required, sort_order)
SELECT t.id, c.name, c.slug, c.description, c.pass_score_pct, c.min_q, c.max_q, c.attempts, c.cooldown, c.recert, c.required, c.sort
FROM (
    SELECT 'basic' AS tier_slug, 'Lawn Mowing Fundamentals' AS name, 'lawn-mowing' AS slug, 'Cutting heights, patterns, blade maintenance, stripe technique.' AS description, 80 AS pass_score_pct, 10 AS min_q, 15 AS max_q, 3 AS attempts, 24 AS cooldown, 12 AS recert, 1 AS required, 10 AS sort
    UNION ALL SELECT 'basic','Equipment Safety','equipment-safety','Safe operation of mowers, trimmers, blowers. PPE basics.',80,10,15,3,24,12,1,20
    UNION ALL SELECT 'basic','Basic Weed & Pest ID','basic-weed-pest','Identify 10 common lawn weeds, chafer grub damage, turf issues.',80,10,15,3,24,12,1,30
    UNION ALL SELECT 'pesticide','Acelepryn Application','acelepryn','Chlorantraniliprole safety, timing, rates, and PPE requirements.',85,15,25,3,48,12,1,40
    UNION ALL SELECT 'pesticide','WHMIS 2015 & PPE','whmis-ppe','GHS labels, SDS sheet interpretation, PPE for chemical work.',85,10,20,3,48,12,1,50
    UNION ALL SELECT 'pesticide','Hedge Trimming Certification','hedge-trimming','Timing, techniques, plant biology, shape principles.',80,10,15,3,24,12,0,60
    UNION ALL SELECT 'supervisor','Supervisor Field Leadership','supervisor-field','Team delegation, quality checks, conflict resolution.',90,15,20,2,72,24,1,70
    UNION ALL SELECT 'supervisor','Advanced Plant & Disease ID','advanced-plant-id','50 plants, 20 diseases, treatment pathways.',90,20,25,2,72,24,1,80
    UNION ALL SELECT 'supervisor','Client Communication','client-comms','Explain services, handle complaints, identify upsell opportunities.',90,10,15,2,72,24,1,90
) c
JOIN cert_tiers t ON t.slug = c.tier_slug",

// 13 — seed service type gating (basic)
"INSERT IGNORE INTO cert_service_type_requirements (service_type_id, min_tier_level, course_id)
SELECT st.id, 1, NULL FROM service_types st WHERE st.slug IN ('lawn_care','maintenance','cleanup','seasonal_cleanup')",

// 14 — seed hedge trimming gating (Tier 2 + course)
"INSERT IGNORE INTO cert_service_type_requirements (service_type_id, min_tier_level, course_id)
SELECT st.id, 2, cc.id FROM service_types st JOIN cert_courses cc ON cc.slug = 'hedge-trimming' WHERE st.slug = 'hedge_trimming'",

// 15 — seed garden maintenance gating (Tier 2)
"INSERT IGNORE INTO cert_service_type_requirements (service_type_id, min_tier_level, course_id)
SELECT st.id, 2, NULL FROM service_types st WHERE st.slug = 'garden_maintenance'",

// 16 — auto-map existing quiz questions to courses by category name
"INSERT IGNORE INTO cert_course_questions (course_id, question_id, is_required, weight)
SELECT cc.id, qq.id, 0, 1
FROM cert_courses cc
JOIN quiz_categories cat ON (
    (cc.slug = 'acelepryn'        AND cat.name LIKE '%Pest%')
 OR (cc.slug = 'basic-weed-pest'  AND cat.name LIKE '%Pest%')
 OR (cc.slug = 'equipment-safety' AND cat.name LIKE '%Safety%')
 OR (cc.slug = 'lawn-mowing'      AND cat.name LIKE '%Turf%')
 OR (cc.slug = 'advanced-plant-id' AND (cat.name LIKE '%Plant%' OR cat.name LIKE '%Pest%'))
)
JOIN quiz_questions qq ON qq.category_id = cat.id AND qq.is_active = 1",

];

$ok = 0; $errors = 0;
foreach ($statements as $i => $stmt) {
    try {
        $db->exec($stmt);
        echo "OK: statement " . ($i + 1) . "\n";
        $ok++;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate')) {
            echo "SKIP (duplicate): statement " . ($i + 1) . "\n";
            $ok++;
        } else {
            echo "ERROR statement " . ($i + 1) . ": " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}
echo "--- Migration 990 complete: {$ok} OK, {$errors} errors ---\n";
