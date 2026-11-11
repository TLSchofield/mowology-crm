<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/finish.php
 *
 * POST /api/quiz/finish
 * Authorization: Bearer <jwt>
 * Body (JSON): { session_id }
 *
 * Marks session complete, calculates final score, updates streak, checks badges.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "correct": 8,
 *   "total": 10,
 *   "points": 120,
 *   "monthly_rank": 2,
 *   "monthly_total": 350,
 *   "streak": { current_streak, longest_streak, new_best },
 *   "new_badges": [ { key, name, icon } ]
 * }
 *
 * PRODUCTION SAFETY — READ BEFORE EDITING
 * ────────────────────────────────────────
 * This endpoint triggers four side effects in sequence. Each has its own
 * safety mechanism — do NOT reorder or collapse them:
 *
 *   1. Session guard (completed_at IS NULL)
 *      Prevents double-finish from iOS network retries. On a duplicate call
 *      the idempotent path returns the cached score from quiz_sessions rather
 *      than re-running streak/badge logic. Removing this turns every network
 *      retry into a duplicate streak tick.
 *
 *   2. Score aggregation (quiz_answers)
 *      Reads from the audit log — never modifies it. The CASE points formula
 *      here MUST stay in sync with the matching formula in answer.php.
 *      If they diverge, stored total_points (set by answer.php per-question)
 *      will disagree with recalculated totals.
 *
 *   3. Monthly rank (SQL subquery)
 *      Calculated via correlated subqueries — do NOT revert to a PHP loop
 *      over fetchAll(). At 50+ users the PHP approach fetches all monthly
 *      sessions into memory on every quiz completion.
 *
 *   4. updateDailyStreak() and checkAndAwardBadges()
 *      Both are idempotent (streak has a same-day guard; badges use INSERT IGNORE).
 *      They MUST be called after the session is marked complete so the badge
 *      checks see the updated quiz_sessions row. Do not move them before the UPDATE.
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
require_once APP_ROOT . '/Modules/Quiz/QuizHelpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];

$input     = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$sessionId = (int)($input['session_id'] ?? 0);

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing session_id']);
    exit;
}

try {
    $db        = Database::pdo();
    $monthYear = date('Y-m');

    // ── Idempotency guard ────────────────────────────────────────────────────
    // completed_at IS NULL is the single gate preventing double-execution.
    // On iOS network retry: session is found but already completed → the
    // idempotent path below returns the cached result without re-running
    // streak, badges, or rank calculation.
    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        // Already completed — return cached result. Streak and badge fields
        // are simplified (new_best=false, new_badges=[]) since this is a retry.
        $done = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
        $done->execute([$sessionId, $userId]);
        $existing = $done->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $streakRow = $db->prepare("SELECT current_streak, longest_streak FROM quiz_daily_streaks WHERE user_id=?");
            $streakRow->execute([$userId]);
            $sr = $streakRow->fetch(PDO::FETCH_ASSOC) ?: ['current_streak' => 0, 'longest_streak' => 0];
            http_response_code(200);
            echo json_encode([
                'success'       => true,
                'correct'       => (int)$existing['correct_count'],
                'total'         => (int)$existing['questions_count'],
                'points'        => (int)$existing['total_points'],
                'monthly_rank'  => 0,
                'monthly_total' => 0,
                'streak'        => ['current_streak' => (int)$sr['current_streak'], 'longest_streak' => (int)$sr['longest_streak'], 'new_best' => false],
                'new_badges'    => [],
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Session not found']);
        }
        exit;
    }

    // ── Score aggregation ────────────────────────────────────────────────────
    // Reads quiz_answers written by answer.php. The points CASE tiers (10/13/15)
    // must match those in answer.php — if you change one, change the other.
    $agg = $db->prepare(
        "SELECT COUNT(*) AS total, SUM(is_correct) AS correct,
                SUM(CASE WHEN is_correct=1 AND time_taken_seconds<=10 THEN 15
                         WHEN is_correct=1 AND time_taken_seconds<=20 THEN 13
                         WHEN is_correct=1 THEN 10 ELSE 0 END) AS points
         FROM quiz_answers WHERE session_id=?"
    );
    $agg->execute([$sessionId]);
    $agg = $agg->fetch(PDO::FETCH_ASSOC);

    $correctCount = (int)($agg['correct'] ?? 0);
    $totalPoints  = (int)($agg['points']  ?? 0);

    // Mark session complete — this is what the idempotency guard reads on retry.
    $db->prepare(
        "UPDATE quiz_sessions SET completed_at=NOW(), correct_count=?, total_points=? WHERE id=?"
    )->execute([$correctCount, $totalPoints, $sessionId]);

    // ── Monthly rank ─────────────────────────────────────────────────────────
    // Correlated subqueries: count how many users scored higher this month.
    // rank = 1 if no one scored higher (including ties at the top).
    // Do NOT revert to fetchAll() + PHP loop — O(n) memory at scale.
    $rankStmt = $db->prepare(
        "SELECT
             COALESCE(
                 (SELECT COUNT(*) + 1
                  FROM (SELECT user_id, SUM(total_points) AS pts
                        FROM quiz_sessions
                        WHERE month_year=? AND completed_at IS NOT NULL
                        GROUP BY user_id) ranked
                  WHERE ranked.pts > COALESCE(
                      (SELECT SUM(total_points) FROM quiz_sessions
                       WHERE user_id=? AND month_year=? AND completed_at IS NOT NULL), 0)
                 ), 1) AS my_rank,
             COALESCE(
                 (SELECT SUM(total_points) FROM quiz_sessions
                  WHERE user_id=? AND month_year=? AND completed_at IS NOT NULL), 0
             ) AS my_total"
    );
    $rankStmt->execute([$monthYear, $userId, $monthYear, $userId, $monthYear]);
    $rankRow        = $rankStmt->fetch(PDO::FETCH_ASSOC);
    $myRank         = (int)($rankRow['my_rank']  ?? 0);
    $myMonthlyTotal = (int)($rankRow['my_total'] ?? 0);

    // ── Streak + badges ──────────────────────────────────────────────────────
    // Both are idempotent — safe if called again on a duplicate request that
    // somehow bypassed the guard above. updateDailyStreak() short-circuits if
    // already called today. checkAndAwardBadges() uses INSERT IGNORE.
    $streakResult = updateDailyStreak($db, $userId);
    $newBadges    = checkAndAwardBadges($db, $userId);

    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'correct'       => $correctCount,
        'total'         => (int)$session['questions_count'],
        'points'        => $totalPoints,
        'monthly_rank'  => $myRank,
        'monthly_total' => $myMonthlyTotal,
        'streak'        => $streakResult,
        'new_badges'    => $newBadges,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/finish] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to finish session.']);
}
