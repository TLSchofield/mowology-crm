<?php
/**
 * /public/crm/api/run-migration-quiz3.php
 * Knowledge Quiz v3 — Framework Upgrade
 *
 * Adds:
 *   - question_type ENUM column to quiz_questions
 *   - learning_level TINYINT column to quiz_questions
 *   - quiz_badges table (badge definitions)
 *   - quiz_user_badges table (earned badges per user)
 *   - quiz_daily_streaks table (consecutive-day streak tracking)
 *   - Seeds 10 badge definitions
 *
 * Run once via browser as admin. Delete after running.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

session_write_close();
$db = getDB();

$results = [];
$errors  = [];

// ── 1. Add question_type column to quiz_questions ─────────────────────────────
$colCheck = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'quiz_questions'
       AND COLUMN_NAME  = 'question_type'"
)->fetchColumn();

if (!$colCheck) {
    try {
        $db->exec(
            "ALTER TABLE quiz_questions
             ADD COLUMN question_type ENUM(
                'multiple_choice','photo_id','scenario','sequence',
                'reverse_recall','customer_explanation','quality_judgement'
             ) NOT NULL DEFAULT 'multiple_choice'
             COMMENT 'Question format type'
             AFTER difficulty"
        );
        $results[] = '✅ Added question_type column to quiz_questions';
    } catch (PDOException $e) {
        $errors[] = 'question_type column: ' . $e->getMessage();
    }
} else {
    $results[] = '⏭ question_type column already exists';
}

// ── 2. Add learning_level column to quiz_questions ────────────────────────────
$llCheck = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'quiz_questions'
       AND COLUMN_NAME  = 'learning_level'"
)->fetchColumn();

if (!$llCheck) {
    try {
        $db->exec(
            "ALTER TABLE quiz_questions
             ADD COLUMN learning_level TINYINT NOT NULL DEFAULT 1
             COMMENT '1=Recognition,2=Diagnosis,3=Treatment,4=Timing,5=CustomerExplanation,6=FieldDecision'
             AFTER question_type"
        );
        $results[] = '✅ Added learning_level column to quiz_questions';
    } catch (PDOException $e) {
        $errors[] = 'learning_level column: ' . $e->getMessage();
    }
} else {
    $results[] = '⏭ learning_level column already exists';
}

// ── 3. Create quiz_badges table ───────────────────────────────────────────────
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS quiz_badges (
          id                        INT AUTO_INCREMENT PRIMARY KEY,
          badge_key                 VARCHAR(50)  NOT NULL,
          name                      VARCHAR(100) NOT NULL,
          description               VARCHAR(255) NOT NULL,
          icon                      VARCHAR(10)  NOT NULL DEFAULT '🏅',
          requirement_type          ENUM('category_correct','total_mastered','streak_days',
                                        'speed_correct','perfect_session') NOT NULL,
          requirement_value         INT          NOT NULL DEFAULT 1,
          requirement_category_name VARCHAR(100) NULL
                                    COMMENT 'Partial name match for category-specific badges',
          created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_badge_key (badge_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $results[] = '✅ quiz_badges table ready';
} catch (PDOException $e) {
    $errors[] = 'quiz_badges: ' . $e->getMessage();
}

// ── 4. Create quiz_user_badges table ─────────────────────────────────────────
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS quiz_user_badges (
          id        INT AUTO_INCREMENT PRIMARY KEY,
          user_id   INT         NOT NULL,
          badge_key VARCHAR(50) NOT NULL,
          earned_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uk_user_badge (user_id, badge_key),
          INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $results[] = '✅ quiz_user_badges table ready';
} catch (PDOException $e) {
    $errors[] = 'quiz_user_badges: ' . $e->getMessage();
}

// ── 5. Create quiz_daily_streaks table ────────────────────────────────────────
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS quiz_daily_streaks (
          id               INT  AUTO_INCREMENT PRIMARY KEY,
          user_id          INT  NOT NULL,
          current_streak   INT  NOT NULL DEFAULT 0,
          longest_streak   INT  NOT NULL DEFAULT 0,
          last_active_date DATE NULL,
          updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uk_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $results[] = '✅ quiz_daily_streaks table ready';
} catch (PDOException $e) {
    $errors[] = 'quiz_daily_streaks: ' . $e->getMessage();
}

// ── 6. Seed badge definitions ─────────────────────────────────────────────────
$badges = [
    // key,              name,                  description,                                       icon, req_type,              req_val, cat_name
    ['weed_hunter',    'Weed Hunter',         'Get 20 correct answers in Weed Identification',   '🌿', 'category_correct',  20,  'Weed'],
    ['plant_expert',   'Plant Expert',        'Get 30 correct answers in Plant & Grass ID',      '🌳', 'category_correct',  30,  'Plant'],
    ['turf_doctor',    'Turf Doctor',         'Get 15 correct answers in Turf & Soil',           '🪱', 'category_correct',  15,  'Turf'],
    ['soil_master',    'Soil Master',         'Get 20 correct answers in Equipment & Tools',     '🌾', 'category_correct',  20,  'Equipment'],
    ['pest_hunter',    'Pest Hunter',         'Get 15 correct answers in Pest & Disease',        '🔬', 'category_correct',  15,  'Pest'],
    ['speed_tech',     'Speed Technician',    'Answer 10 questions in under 5 seconds',          '⚡', 'speed_correct',     10,  null],
    ['week_streak',    '7-Day Streak',        'Study 7 days in a row',                           '🔥', 'streak_days',       7,   null],
    ['month_streak',   '30-Day Streak',       'Study 30 days in a row',                         '💎', 'streak_days',       30,  null],
    ['mastery_50',     'Knowledge Builder',   'Master 50 questions',                             '🎓', 'total_mastered',    50,  null],
    ['mastery_100',    'Knowledge Expert',    'Master 100 questions',                            '🏆', 'total_mastered',    100, null],
    ['perfect_game',   'Perfect Round',       'Complete a session with all correct answers',     '⭐', 'perfect_session',   1,   null],
];

$stmt   = $db->prepare(
    "INSERT IGNORE INTO quiz_badges
     (badge_key, name, description, icon, requirement_type, requirement_value, requirement_category_name)
     VALUES (?,?,?,?,?,?,?)"
);
$seeded = 0;
foreach ($badges as $b) {
    $stmt->execute($b);
    $seeded += $stmt->rowCount();
}
$results[] = "✅ Seeded {$seeded} new badge definitions (11 total defined)";

// ── Done ──────────────────────────────────────────────────────────────────────
echo json_encode([
    'success' => empty($errors),
    'migration' => 'quiz-v3-framework-upgrade',
    'steps' => $results,
    'errors' => $errors,
    'done' => true,
], JSON_PRETTY_PRINT);
