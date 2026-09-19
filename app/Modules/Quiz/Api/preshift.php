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
 *
 * PRODUCTION SAFETY — READ BEFORE EDITING
 * ────────────────────────────────────────
 * This endpoint gates crew access to the app and the TimeClock tab.
 * Getting it wrong in either direction is bad:
 *   - False positive (gate fires when it shouldn't) → crew can't clock in
 *   - False negative (gate skips when required)     → pre-shift training bypassed
 *
 * GET path:
 *   If quiz_preshift_settings has no rows, is_enabled defaults to FALSE.
 *   This is intentional fail-open: missing config never blocks crew.
 *   Do not change the default to true — it would lock out the entire team
 *   if the settings table is ever emptied.
 *
 *   session_length is clamped to [3, 10] server-side. iOS passes this value
 *   directly to the quiz start call — if you change the clamp range, update
 *   the allowlist in start.php too.
 *
 * POST path:
 *   Uses INSERT IGNORE with (user_id, log_date) uniqueness — calling this
 *   twice on the same day is safe and idempotent. Do not change to INSERT
 *   without the IGNORE or the pre-shift gate can be re-opened mid-day.
 *
 *   session_id is optional. If provided, the session must belong to this user
 *   (enforced by the WHERE user_id=? check). score stats are pulled from the
 *   completed session for reporting purposes only — they do not affect the gate.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
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

        // Fail-open: no settings row → required = false, crew is not blocked.
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
                'success'         => true,
                'required'        => false,
                'completed_today' => false,
                'session_length'  => $sessionLength,
            ]);
            exit;
        }

        // log_date uses server date — ensures "today" is consistent regardless
        // of iOS timezone. All crew are in the same timezone as the server.
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

        // Pull score stats from the completed session for audit log only.
        // These values do NOT affect whether the gate opens — completion does.
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

        // INSERT IGNORE makes this idempotent — safe to call multiple times.
        // The (user_id, log_date) unique constraint prevents duplicate rows.
        // Do NOT remove IGNORE — without it a network retry would throw a
        // duplicate key error and the iOS app would get a 500.
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
