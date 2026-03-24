<?php
/**
 * Migration 991 — BC Pesticide Applicator Training System
 * Adds pesticide_training_required column to users,
 * creates the quiz category, and creates the cert course.
 * Admin only. Idempotent.
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
    echo json_encode(['error' => 'Admin only']);
    exit;
}

session_write_close();

$db  = getDB();
$log = [];
$ok  = true;

try {
    // 1. Add column to users (IF NOT EXISTS handled in SQL via SHOW COLUMNS)
    $cols = $db->query("SHOW COLUMNS FROM users LIKE 'pesticide_training_required'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN pesticide_training_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = employee required to complete BC Pesticide training'");
        $log[] = '✓ Added pesticide_training_required column to users';
    } else {
        $log[] = '— Column pesticide_training_required already exists';
    }

    // 2. Add quiz category
    $catCheck = $db->query("SELECT id FROM quiz_categories WHERE name = 'BC Pesticide Applicator' LIMIT 1")->fetch();
    if (!$catCheck) {
        $db->exec("INSERT INTO quiz_categories (name, description, icon, colour, sort_order)
            VALUES ('BC Pesticide Applicator',
                    'BC provincial pesticide applicator certificate exam preparation — all 10 exam matter domains.',
                    'shield', '#e85d04', 70)");
        $log[] = '✓ Created quiz category: BC Pesticide Applicator';
    } else {
        $log[] = '— Quiz category already exists (id=' . $catCheck['id'] . ')';
    }

    // 3. Add cert course
    $courseCheck = $db->query("SELECT id FROM cert_courses WHERE slug = 'bc-pesticide-applicator' LIMIT 1")->fetch();
    if (!$courseCheck) {
        $tier = $db->query("SELECT id FROM cert_tiers WHERE slug = 'pesticide' LIMIT 1")->fetch();
        if (!$tier) throw new RuntimeException('Tier 2 (pesticide) not found — run migration 990 first');
        $db->prepare("
            INSERT INTO cert_courses
                (tier_id, name, slug, description, icon, colour,
                 pass_score_pct, min_questions, max_questions,
                 attempts_allowed, cooldown_hours, recert_months,
                 is_required, sort_order)
            VALUES (?, 'BC Pesticide Applicator Certificate', 'bc-pesticide-applicator',
                    'Prep for the BC provincial pesticide applicator certificate exam. Covers all 10 exam-matter domains.',
                    'shield', '#e85d04', 80, 20, 40, 5, 48, 12, 0, 35)
        ")->execute([$tier['id']]);
        $log[] = '✓ Created cert course: BC Pesticide Applicator Certificate';
    } else {
        $log[] = '— Cert course already exists (id=' . $courseCheck['id'] . ')';
    }

    $log[] = '';
    $log[] = 'Next step: run /crm/api/run-migration-pesticide-quiz-seed.php to seed the 60 exam questions.';

} catch (Throwable $e) {
    $ok  = false;
    $log[] = '✗ ERROR: ' . $e->getMessage();
}

echo json_encode(['ok' => $ok, 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
