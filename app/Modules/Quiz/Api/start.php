<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/start.php
 *
 * POST /api/quiz/start
 * Authorization: Bearer <jwt>
 * Body (JSON): { category_id?: int|null, session_length?: 3|5|10, mode?: "seasonal"|"random" }
 *
 * Creates a new quiz session and returns the session ID + metadata.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "session_id": 42,
 *   "total": 10,
 *   "season_label": "...",
 *   "season_icon": "...",
 *   "season_tagline": "..."
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

$input         = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$categoryId    = isset($input['category_id']) && $input['category_id'] !== null && $input['category_id'] !== ''
                   ? (int)$input['category_id'] : null;
$sessionLength = (int)($input['session_length'] ?? 10);
if (!in_array($sessionLength, [3, 5, 10], true)) $sessionLength = 10;
$mode = $input['mode'] ?? 'seasonal';

try {
    $db         = Database::pdo();
    $monthYear  = date('Y-m');

    if ($mode === 'random') {
        if ($categoryId !== null) {
            $stmt = $db->prepare("SELECT id FROM quiz_questions WHERE category_id=? AND is_active=1 ORDER BY RAND() LIMIT {$sessionLength}");
            $stmt->execute([$categoryId]);
        } else {
            $stmt = $db->query("SELECT id FROM quiz_questions WHERE is_active=1 ORDER BY RAND() LIMIT {$sessionLength}");
        }
        $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Seasonal blending (default)
        $qids = selectSeasonalQuestions($db, $userId, $categoryId, $sessionLength);
    }

    if (count($qids) < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'No questions available for this category yet.']);
        exit;
    }

    $questionIds = implode(',', array_map('intval', $qids));
    $db->prepare(
        "INSERT INTO quiz_sessions (user_id, category_id, question_ids, questions_count, month_year)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$userId, $categoryId, $questionIds, count($qids), $monthYear]);

    $sessionId = (int)$db->lastInsertId();
    $ctx       = getSeasonContext();

    http_response_code(200);
    echo json_encode([
        'success'        => true,
        'session_id'     => $sessionId,
        'total'          => count($qids),
        'season_label'   => $ctx['season_label'],
        'season_icon'    => $ctx['season_icon'],
        'season_tagline' => $ctx['season_tagline'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/start] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to start quiz session.']);
}
