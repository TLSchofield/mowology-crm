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
 *   "points_earned": 15,
 *   "mastery": { old_level, new_level, delta, level_name, streak }
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

    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found or already complete']);
        exit;
    }

    // Prevent duplicate answers
    $dup = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
    $dup->execute([$sessionId, $questionId]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Already answered this question']);
        exit;
    }

    // Determine correctness
    $corrStmt = $db->prepare("SELECT id FROM quiz_options WHERE question_id=? AND is_correct=1 LIMIT 1");
    $corrStmt->execute([$questionId]);
    $correctOption   = $corrStmt->fetch(PDO::FETCH_ASSOC);
    $correctOptionId = $correctOption ? (int)$correctOption['id'] : null;
    $isCorrect       = ($selectedOptionId !== null && $selectedOptionId === $correctOptionId);

    $points = 0;
    if ($isCorrect) {
        $points = ($timeTaken <= 10) ? 15 : (($timeTaken <= 20) ? 13 : 10);
    }

    $db->prepare(
        "INSERT INTO quiz_answers (session_id, question_id, selected_option_id, is_correct, time_taken_seconds)
         VALUES (?,?,?,?,?)"
    )->execute([$sessionId, $questionId, $selectedOptionId, $isCorrect ? 1 : 0, $timeTaken]);

    $masteryUpdate = updateMastery($db, $userId, $questionId, $isCorrect, $timeTaken);

    http_response_code(200);
    echo json_encode([
        'success'           => true,
        'is_correct'        => $isCorrect,
        'correct_option_id' => $correctOptionId,
        'points_earned'     => $points,
        'mastery'           => $masteryUpdate,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/answer] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record answer.']);
}
