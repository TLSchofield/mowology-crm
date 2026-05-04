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

    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        // Already completed — return the cached result idempotently
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

    // Aggregate answers
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

    $db->prepare(
        "UPDATE quiz_sessions SET completed_at=NOW(), correct_count=?, total_points=? WHERE id=?"
    )->execute([$correctCount, $totalPoints, $sessionId]);

    // Monthly rank — SQL subquery avoids fetching all users into PHP
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
