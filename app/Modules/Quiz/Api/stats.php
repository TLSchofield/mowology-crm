<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/stats.php
 *
 * GET /api/quiz/stats
 * Authorization: Bearer <jwt>
 *
 * Returns personal monthly stats, rank tier, streaks, badge counts,
 * and per-category accuracy breakdown.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "games_played": 5,
 *   "monthly_points": 450,
 *   "total_correct": 38,
 *   "total_questions": 50,
 *   "best_game": 130,
 *   "total_mastered": 12,
 *   "current_streak": 3,
 *   "longest_streak": 7,
 *   "badge_count": 4,
 *   "total_badges": 12,
 *   "rank_tier": { tier, name, icon, next_at, next_name },
 *   "category_accuracy": [ { name, colour, total, correct, pct } ]
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

$jwtUser   = requireJwt();
$userId    = (int)$jwtUser['id'];
$monthYear = date('Y-m');

try {
    $db = Database::pdo();

    // Monthly session stats
    $statsStmt = $db->prepare(
        "SELECT COUNT(*)             AS games_played,
                SUM(total_points)    AS monthly_points,
                SUM(correct_count)   AS total_correct,
                SUM(questions_count) AS total_questions,
                MAX(total_points)    AS best_game
         FROM quiz_sessions
         WHERE user_id=? AND month_year=? AND completed_at IS NOT NULL"
    );
    $statsStmt->execute([$userId, $monthYear]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Total mastered
    $masteredStmt = $db->prepare("SELECT COUNT(*) FROM quiz_user_mastery WHERE user_id=? AND mastery_level >= 4");
    $masteredStmt->execute([$userId]);
    $totalMastered = (int)$masteredStmt->fetchColumn();

    // Streak
    $streakRow = $db->prepare("SELECT current_streak, longest_streak FROM quiz_daily_streaks WHERE user_id=?");
    $streakRow->execute([$userId]);
    $streakData    = $streakRow->fetch(PDO::FETCH_ASSOC);
    $currentStreak = (int)($streakData['current_streak'] ?? 0);
    $longestStreak = (int)($streakData['longest_streak'] ?? 0);

    // Badge counts
    $badgesEarned = $db->prepare("SELECT COUNT(*) FROM quiz_user_badges WHERE user_id=?");
    $badgesEarned->execute([$userId]);
    $badgeCount  = (int)$badgesEarned->fetchColumn();
    $totalBadges = (int)$db->query("SELECT COUNT(*) FROM quiz_badges")->fetchColumn();

    // Category accuracy
    $catAccStmt = $db->prepare(
        "SELECT c.name, c.colour,
                COUNT(a.id)           AS total_answers,
                SUM(a.is_correct)     AS correct_answers
         FROM quiz_answers a
         JOIN quiz_sessions s ON s.id = a.session_id
         JOIN quiz_questions q ON q.id = a.question_id
         JOIN quiz_categories c ON c.id = q.category_id
         WHERE s.user_id = ?
         GROUP BY c.id, c.name, c.colour
         ORDER BY c.sort_order"
    );
    $catAccStmt->execute([$userId]);
    $categoryAccuracy = [];
    foreach ($catAccStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total   = (int)$row['total_answers'];
        $correct = (int)$row['correct_answers'];
        $categoryAccuracy[] = [
            'name'    => $row['name'],
            'colour'  => $row['colour'],
            'total'   => $total,
            'correct' => $correct,
            'pct'     => $total > 0 ? round(($correct / $total) * 100) : 0,
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success'           => true,
        'games_played'      => (int)($stats['games_played']  ?? 0),
        'monthly_points'    => (int)($stats['monthly_points'] ?? 0),
        'total_correct'     => (int)($stats['total_correct']  ?? 0),
        'total_questions'   => (int)($stats['total_questions'] ?? 0),
        'best_game'         => (int)($stats['best_game']      ?? 0),
        'total_mastered'    => $totalMastered,
        'current_streak'    => $currentStreak,
        'longest_streak'    => $longestStreak,
        'badge_count'       => $badgeCount,
        'total_badges'      => $totalBadges,
        'rank_tier'         => rankTierInfo($totalMastered),
        'category_accuracy' => $categoryAccuracy,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/stats] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load stats.']);
}
