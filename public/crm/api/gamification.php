<?php
/**
 * /public/crm/api/gamification.php
 * Gamification API — team management, scoring, leaderboard, badges.
 *
 * Actions (GET):
 *   leaderboard        — Current week leaderboard
 *   leaderboard&week=YYYY-MM-DD — Specific week
 *   teams              — All teams with members
 *   history&team_id=X  — Score history for a team (sparkline)
 *   badges             — All badge definitions
 *   team_badges&team_id=X — Badges earned by a team
 *
 * Actions (POST):
 *   recalculate        — Recalculate scores for current (or ?week=) week
 *   create_team        — Create a new crew team
 *   update_team        — Update team name/colour/emoji
 *   add_member         — Add user to team
 *   remove_member      — Remove user from team
 */

declare(strict_types=1);

// Bootstrap — paths, then auth via public shim (same pattern as other API files)
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
require_once APP_ROOT . '/Core/Auth/authz.php';

requireLogin();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    if ($method === 'GET') {
        switch ($action) {
            case 'leaderboard':
                handleLeaderboard($db);
                break;
            case 'teams':
                handleTeams($db);
                break;
            case 'history':
                handleHistory($db);
                break;
            case 'badges':
                handleBadges($db);
                break;
            case 'team_badges':
                handleTeamBadges($db);
                break;
            default:
                // Default: leaderboard for current week
                handleLeaderboard($db);
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        verifyCSRFToken($input['csrf_token'] ?? '');
        session_write_close();

        switch ($action) {
            case 'recalculate':
                requirePermission('settings.edit');
                handleRecalculate($db, $input);
                break;
            case 'create_team':
                requirePermission('team.edit');
                handleCreateTeam($db, $input);
                break;
            case 'update_team':
                requirePermission('team.edit');
                handleUpdateTeam($db, $input);
                break;
            case 'add_member':
                requirePermission('team.edit');
                handleAddMember($db, $input);
                break;
            case 'remove_member':
                requirePermission('team.edit');
                handleRemoveMember($db, $input);
                break;
            default:
                throw new Exception('Unknown action: ' . htmlspecialchars($action));
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


// ── GET handlers ──────────────────────────────────────────────────────────────

function handleLeaderboard(PDO $db): void
{
    require_once APP_ROOT . '/Services/Gamification/CalculateCrewScores.php';

    $week = $_GET['week'] ?? getCurrentWeekStart();
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
        $week = getCurrentWeekStart();
    }

    $leaderboard = getLeaderboard($week, $db);
    echo json_encode(['success' => true, 'week' => $week, 'leaderboard' => $leaderboard]);
}

function handleTeams(PDO $db): void
{
    $stmt = $db->query("
        SELECT ct.id, ct.name, ct.colour, ct.emoji, ct.is_active,
               GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') AS member_names,
               COUNT(ctm.user_id) AS member_count
        FROM crew_teams ct
        LEFT JOIN crew_team_members ctm ON ctm.team_id = ct.id
        LEFT JOIN users u ON u.id = ctm.user_id
        GROUP BY ct.id
        ORDER BY ct.name
    ");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add member detail arrays
    foreach ($teams as &$team) {
        $mStmt = $db->prepare("
            SELECT u.id, u.full_name, u.role
            FROM crew_team_members ctm
            JOIN users u ON u.id = ctm.user_id
            WHERE ctm.team_id = ?
            ORDER BY u.full_name
        ");
        $mStmt->execute([$team['id']]);
        $team['members'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($team);

    // Also return all users not yet on any team
    $unassignedStmt = $db->query("
        SELECT u.id, u.full_name, u.role
        FROM users u
        WHERE u.is_active = 1
          AND u.id NOT IN (SELECT user_id FROM crew_team_members)
        ORDER BY u.full_name
    ");
    $unassigned = $unassignedStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'teams' => $teams, 'unassigned' => $unassigned]);
}

function handleHistory(PDO $db): void
{
    require_once APP_ROOT . '/Services/Gamification/CalculateCrewScores.php';
    $teamId = (int)($_GET['team_id'] ?? 0);
    if (!$teamId) throw new Exception('team_id required');
    $weeks = min(16, max(4, (int)($_GET['weeks'] ?? 8)));
    $history = getTeamScoreHistory($teamId, $weeks, $db);
    echo json_encode(['success' => true, 'team_id' => $teamId, 'history' => $history]);
}

function handleBadges(PDO $db): void
{
    $stmt = $db->query("SELECT * FROM crew_badges WHERE is_active = 1 ORDER BY tier DESC, name");
    $badges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'badges' => $badges]);
}

function handleTeamBadges(PDO $db): void
{
    $teamId = (int)($_GET['team_id'] ?? 0);
    if (!$teamId) throw new Exception('team_id required');

    $stmt = $db->prepare("
        SELECT cb.slug, cb.name, cb.icon, cb.colour, cb.tier, cb.description,
               cba.awarded_at, cba.week_start, cba.note
        FROM crew_badge_awards cba
        JOIN crew_badges cb ON cb.id = cba.badge_id
        WHERE cba.team_id = ?
        ORDER BY cba.awarded_at DESC
    ");
    $stmt->execute([$teamId]);
    $awards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'team_id' => $teamId, 'badges' => $awards]);
}


// ── POST handlers ─────────────────────────────────────────────────────────────

function handleRecalculate(PDO $db, array $input): void
{
    require_once APP_ROOT . '/Services/Gamification/CalculateCrewScores.php';

    $week = $input['week'] ?? getCurrentWeekStart();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
        $week = getCurrentWeekStart();
    }

    $results = calculateAllTeamScores($week, $db);

    echo json_encode([
        'success' => true,
        'week'    => $week,
        'teams_calculated' => count($results),
        'results' => $results,
    ]);
}

function handleCreateTeam(PDO $db, array $input): void
{
    $name   = trim($input['name'] ?? '');
    $colour = $input['colour'] ?? '#2D8659';
    $emoji  = $input['emoji'] ?? null;

    if (!$name) throw new Exception('Team name is required');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colour)) $colour = '#2D8659';

    $db->prepare("INSERT INTO crew_teams (name, colour, emoji) VALUES (?, ?, ?)")
       ->execute([$name, $colour, $emoji ?: null]);

    $teamId = (int)$db->lastInsertId();
    echo json_encode(['success' => true, 'team_id' => $teamId, 'message' => 'Team created']);
}

function handleUpdateTeam(PDO $db, array $input): void
{
    $teamId = (int)($input['team_id'] ?? 0);
    if (!$teamId) throw new Exception('team_id required');

    $fields = [];
    $params = [];

    if (isset($input['name'])) {
        $fields[] = 'name = ?';
        $params[]  = trim($input['name']);
    }
    if (isset($input['colour'])) {
        $colour = $input['colour'];
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colour)) $colour = '#2D8659';
        $fields[] = 'colour = ?';
        $params[]  = $colour;
    }
    if (array_key_exists('emoji', $input)) {
        $fields[] = 'emoji = ?';
        $params[]  = $input['emoji'] ?: null;
    }
    if (isset($input['is_active'])) {
        $fields[] = 'is_active = ?';
        $params[]  = (int)$input['is_active'];
    }

    if (!$fields) throw new Exception('Nothing to update');

    $params[] = $teamId;
    $db->prepare("UPDATE crew_teams SET " . implode(', ', $fields) . " WHERE id = ?")
       ->execute($params);

    echo json_encode(['success' => true, 'message' => 'Team updated']);
}

function handleAddMember(PDO $db, array $input): void
{
    $teamId = (int)($input['team_id'] ?? 0);
    $userId = (int)($input['user_id'] ?? 0);
    if (!$teamId || !$userId) throw new Exception('team_id and user_id required');

    // Remove from any existing team first
    $db->prepare("DELETE FROM crew_team_members WHERE user_id = ?")->execute([$userId]);

    $db->prepare("INSERT INTO crew_team_members (team_id, user_id) VALUES (?, ?)")
       ->execute([$teamId, $userId]);

    echo json_encode(['success' => true, 'message' => 'Member added']);
}

function handleRemoveMember(PDO $db, array $input): void
{
    $teamId = (int)($input['team_id'] ?? 0);
    $userId = (int)($input['user_id'] ?? 0);
    if (!$teamId || !$userId) throw new Exception('team_id and user_id required');

    $db->prepare("DELETE FROM crew_team_members WHERE team_id = ? AND user_id = ?")
       ->execute([$teamId, $userId]);

    echo json_encode(['success' => true, 'message' => 'Member removed']);
}
