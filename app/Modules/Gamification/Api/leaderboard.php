<?php
declare(strict_types=1);

/**
 * app/Modules/Gamification/Api/leaderboard.php
 *
 * Mobile Leaderboard API — JWT authenticated, all roles.
 *
 * GET /api/gamification/leaderboard[?week=YYYY-MM-DD]
 *
 * Returns the weekly team leaderboard (read-only).
 * If week is omitted, returns the current week (Monday-anchored).
 *
 * Response:
 *   {success, week, leaderboard: [{
 *     team_id, team_name, team_colour, team_emoji,
 *     total_score, rank, badge_count, members: [string],
 *     recent_badges: [{name, icon, colour, tier, awarded_at}]
 *   }]}
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
require_once APP_ROOT . '/Services/Gamification/CalculateCrewScores.php';

requireJwt();   // any authenticated user may view the leaderboard
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET only']);
    exit;
}

$week = $_GET['week'] ?? getCurrentWeekStart();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    $week = getCurrentWeekStart();
}

$leaderboard = getLeaderboard($week, $db);

// Normalise types for Swift Decodable
foreach ($leaderboard as &$entry) {
    $entry['team_id']    = (int)$entry['team_id'];
    $entry['rank']       = (int)$entry['rank'];
    $entry['badge_count'] = (int)$entry['badge_count'];
    $entry['total_score'] = $entry['total_score'] !== null ? (float)$entry['total_score'] : null;
    // member names are already strings; badge sub-arrays are already associative
}
unset($entry);

echo json_encode([
    'success'     => true,
    'week'        => $week,
    'leaderboard' => $leaderboard,
]);
