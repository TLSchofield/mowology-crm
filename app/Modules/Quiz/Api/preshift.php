<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/preshift.php
 *
 * GET /api/quiz/preshift
 *   Check whether today's pre-shift quiz is required and if it's been completed.
 *   Response: { success, required, completed_today, session_length }
 *
 * POST /api/quiz/preshift
 *   Body (JSON): { session_id }
 *   Mark the pre-shift quiz as complete for today.
 *   Response: { success, message }
 *
 * Authorization: Bearer <jwt>
 *
 * The pre-shift requirement is driven by quiz_preshift_settings:
 *   is_enabled   — global on/off toggle (admin-controlled)
 *   session_length — how many questions (default 5)
 *   pass_threshold — minimum score % (informational only; gate unlocks on completion)
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];

try {
    $db    = Database::pdo();
    $today = date('Y-m-d');

    // ── GET: check today's status ────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // Load global pre-shift settings
        $settingStmt = $db->query(
            "SELECT is_enabled, session_length, pass_threshold
             FROM quiz_preshift_settings
             ORDER BY id DESC LIMIT 1"
        );
        $setting = $settingStmt ? $settingStmt->fetch(PDO::FETCH_ASSOC) : null;

        $isEnabled     = $setting ? (bool)$setting['is_enabled'] : false;
        $sessionLength = $setting ? max(3, min(10, (int)$setting['session_length'])) : 5;

        if (!$isEnabled) {
            http_response_code(200);
            echo json_encode([
                'success'        => true,
                'required'       => false,
                'completed_today'=> false,
                'session_length' => $sessionLength,
            ]);
            exit;
        }

        // Check if user already completed today
        $logStmt = $db->prepare(
            "SELECT id FROM quiz_preshift_log WHERE user_id=? AND log_date=? LIMIT 1"
        );
        $logStmt->execute([$userId, $today]);
        $completedToday = (bool)$logStmt->fetch();

        http_response_code(200);
        echo json_encode([
            'success'         => true,
            'required'        => true,
            'completed_today' => $completedToday,
            'session_length'  => $sessionLength,
        ]);
        exit;
    }

    // ── POST: mark pre-shift complete ────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input     = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
        $sessionId = isset($input['session_id']) ? (int)$input['session_id'] : null;

        // Pull stats from the session if provided
        $questionsAsked   = 0;
        $questionsCorrect = 0;
        $scorePct         = 0;

        if ($sessionId) {
            $sessStmt = $db->prepare(
                "SELECT questions_count, correct_count FROM quiz_sessions WHERE id=? AND user_id=?"
            );
            $sessStmt->execute([$sessionId, $userId]);
            $sess = $sessStmt->fetch(PDO::FETCH_ASSOC);
            if ($sess) {
                $questionsAsked   = (int)$sess['questions_count'];
                $questionsCorrect = (int)$sess['correct_count'];
                $scorePct         = $questionsAsked > 0
                    ? round(($questionsCorrect / $questionsAsked) * 100)
                    : 0;
            }
        }

        // Upsert: if already logged today, ignore (idempotent)
        $db->prepare(
            "INSERT IGNORE INTO quiz_preshift_log
             (user_id, log_date, session_id, questions_asked, questions_correct, score_pct, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$userId, $today, $sessionId, $questionsAsked, $questionsCorrect, $scorePct]);

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Pre-shift quiz marked complete.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET or POST required']);

} catch (Throwable $e) {
    error_log('[quiz/preshift] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to process pre-shift status.']);
}
