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
 *
 * PRODUCTION SAFETY — READ BEFORE EDITING
 * ────────────────────────────────────────
 * This endpoint is the entry point for every quiz session. It writes one row
 * to quiz_sessions and commits the question order. Everything downstream
 * (question.php, answer.php, finish.php) depends on the question_ids column
 * written here — do not change the storage format without updating all consumers.
 *
 * session_length is intentionally server-validated to [3, 5, 10].
 * The iOS app sends the value from quiz_preshift_settings.session_length —
 * if you widen this allowlist, update the preshift settings validation too.
 *
 * question_ids is stored as a CSV of integers (e.g., "42,17,88,5,31").
 * It is read back in question.php and answer.php using explode+intval.
 * Do NOT change this format to JSON or another type without updating both readers.
 *
 * The "random" mode uses ORDER BY RAND() — acceptable for small question pools
 * (hundreds of questions). Do not use it on tables with thousands of rows.
 *
 * The "seasonal" mode calls selectSeasonalQuestions() in QuizHelpers.php,
 * which runs 4 separate queries. This is intentional — it applies weighted
 * blending logic that cannot be expressed as a single query.
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

// ── Input parsing ─────────────────────────────────────────────────────────────
// category_id null = "any category" (used by pre-shift gate and Play Any button).
// session_length defaults to 10 but iOS sends the value from preshift settings.
// Allowlist enforced server-side — do not trust client to send a valid value.
$input         = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$categoryId    = isset($input['category_id']) && $input['category_id'] !== null && $input['category_id'] !== ''
                   ? (int)$input['category_id'] : null;
$sessionLength = (int)($input['session_length'] ?? 10);
if (!in_array($sessionLength, [3, 5, 10], true)) $sessionLength = 10;
$mode = $input['mode'] ?? 'seasonal';

try {
    $db        = Database::pdo();
    $monthYear = date('Y-m');

    // ── Question selection ────────────────────────────────────────────────────
    // "random": ORDER BY RAND() — simple but O(n) sort. Fine for small pools.
    // "seasonal": 4-pool weighted blending via QuizHelpers.selectSeasonalQuestions().
    if ($mode === 'random') {
        if ($categoryId !== null) {
            $stmt = $db->prepare("SELECT id FROM quiz_questions WHERE category_id=? AND is_active=1 ORDER BY RAND() LIMIT {$sessionLength}");
            $stmt->execute([$categoryId]);
        } else {
            $stmt = $db->query("SELECT id FROM quiz_questions WHERE is_active=1 ORDER BY RAND() LIMIT {$sessionLength}");
        }
        $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $qids = selectSeasonalQuestions($db, $userId, $categoryId, $sessionLength);
    }

    if (count($qids) < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'No questions available for this category yet.']);
        exit;
    }

    // ── Session write ─────────────────────────────────────────────────────────
    // question_ids stored as CSV. answer.php and question.php both read this
    // column — do not change the format without updating those files.
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
