<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/answer.php
 *
 * POST /api/quiz/answer
 * Authorization: Bearer <jwt>
 * Body (JSON): { session_id, question_id, selected_option_id, time_taken_seconds }
 *
 * Records the answer, updates mastery, and returns correctness feedback.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "is_correct": true,
 *   "correct_option_id": 17,
 *   "selected_option_id": 12,
 *   "points_earned": 15,
 *   "mastery": { old_level, new_level, delta, level_name, streak }
 * }
 *
 * PRODUCTION SAFETY — READ BEFORE EDITING
 * ────────────────────────────────────────
 * This is the most sensitive write endpoint in the quiz system. Each call
 * permanently mutates quiz_answers AND quiz_user_mastery for the user.
 *
 * GUARD ORDER matters — do NOT reorder these checks:
 *   1. JWT auth       — no token = no write, ever
 *   2. Session ownership + open — prevents cross-user writes and post-finish tampering
 *   3. Question membership      — prevents mastery farming on arbitrary question IDs
 *   4. Duplicate detection      — prevents double-submits from network retries
 *   5. Correctness check        — server-authoritative, never trust client
 *   6. INSERT quiz_answers      — permanent, no delete path in normal flow
 *   7. updateMastery()          — atomic upsert in QuizHelpers.php; do NOT inline here
 *
 * The duplicate-answer check (step 4) is intentionally placed AFTER the
 * membership check (step 3) so the membership guard fires first on retries.
 *
 * time_taken_seconds is clamped 0–30 server-side. Client timestamps are untrusted.
 * Removing the clamp would let a client claim absurd speed bonuses (+2 mastery levels).
 *
 * selected_option_id may be null if the client sends a skip (time-out scenario).
 * A null selection is always treated as incorrect — do not add a default value.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Upward search for paths.php handles deployment in both local and production
// directory structures without hardcoded depth assumptions.
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

// ── Method gate ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
// requireJwt() exits with 401 if the Bearer token is missing or invalid.
// Never remove this — it is the only thing preventing unauthenticated writes.
$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];

// ── Input parsing ─────────────────────────────────────────────────────────────
// All values are cast immediately. selected_option_id is nullable (skip = wrong).
// time_taken is clamped server-side — never trust the client value for scoring.
$input            = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$sessionId        = (int)($input['session_id'] ?? 0);
$questionId       = (int)($input['question_id'] ?? 0);
$selectedOptionId = isset($input['selected_option_id']) ? (int)$input['selected_option_id'] : null;
$timeTaken        = max(0, min(30, (int)($input['time_taken_seconds'] ?? 30)));

if ($sessionId <= 0 || $questionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing session_id or question_id']);
    exit;
}

try {
    $db = Database::pdo();

    // ── Guard 1+2: session ownership and open status ──────────────────────────
    // completed_at IS NULL ensures the session is still active.
    // Removing user_id=? here would allow cross-user answer injection.
    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found or already complete']);
        exit;
    }

    // ── Guard 3: question membership ─────────────────────────────────────────
    // question_ids is a CSV of ints written at session start. Verifying here
    // prevents a user from submitting answers for questions outside their
    // session to farm mastery on arbitrary questions. Do not remove this check.
    $sessionQids = array_map('intval', explode(',', $session['question_ids']));
    if (!in_array($questionId, $sessionQids, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Question not part of this session']);
        exit;
    }

    // ── Guard 4: duplicate detection ─────────────────────────────────────────
    // iOS retries on network timeout. Without this, a retry after a successful
    // write would double-count the answer and trigger mastery twice.
    $dup = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
    $dup->execute([$sessionId, $questionId]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Already answered this question']);
        exit;
    }

    // ── Correctness check ────────────────────────────────────────────────────
    // Server determines correctness from quiz_options — the client's assertion
    // is never trusted. correct_option_id is returned so iOS can highlight
    // the right answer even when the user was wrong.
    $corrStmt = $db->prepare("SELECT id FROM quiz_options WHERE question_id=? AND is_correct=1 LIMIT 1");
    $corrStmt->execute([$questionId]);
    $correctOption   = $corrStmt->fetch(PDO::FETCH_ASSOC);
    $correctOptionId = $correctOption ? (int)$correctOption['id'] : null;
    $isCorrect       = ($selectedOptionId !== null && $selectedOptionId === $correctOptionId);

    // Speed bonus: ≤10s = 15pts, ≤20s = 13pts, else 10pts. Wrong = 0.
    // Changing these tiers also requires updating the matching logic in finish.php.
    $points = 0;
    if ($isCorrect) {
        $points = ($timeTaken <= 10) ? 15 : (($timeTaken <= 20) ? 13 : 10);
    }

    // ── Writes ───────────────────────────────────────────────────────────────
    // quiz_answers is an audit log — rows are never deleted in normal flow.
    // updateMastery() in QuizHelpers.php uses an atomic upsert; do not inline
    // the mastery write here or you re-introduce the SELECT+INSERT race.
    $db->prepare(
        "INSERT INTO quiz_answers (session_id, question_id, selected_option_id, is_correct, time_taken_seconds)
         VALUES (?,?,?,?,?)"
    )->execute([$sessionId, $questionId, $selectedOptionId, $isCorrect ? 1 : 0, $timeTaken]);

    $masteryUpdate = updateMastery($db, $userId, $questionId, $isCorrect, $timeTaken);

    // selected_option_id is returned so iOS can highlight the user's wrong
    // choice in red — without it the UI can only show green on the correct answer.
    http_response_code(200);
    echo json_encode([
        'success'            => true,
        'is_correct'         => $isCorrect,
        'correct_option_id'  => $correctOptionId,
        'selected_option_id' => $selectedOptionId,
        'points_earned'      => $points,
        'mastery'            => $masteryUpdate,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/answer] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record answer.']);
}
