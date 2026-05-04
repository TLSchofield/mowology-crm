<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/question.php
 *
 * GET /api/quiz/question?session_id=<id>&q=<num>
 * Authorization: Bearer <jwt>
 *
 * Returns question text, shuffled options, and mastery metadata for question #q.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "question_num": 3,
 *   "total": 10,
 *   "already_answered": false,
 *   "question": { id, text, images[], difficulty, category_name, category_colour, mastery_level, mastery_name },
 *   "options": [ { id, option_text }, ... ]
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
$sessionId = (int)($_GET['session_id'] ?? 0);
$qNum      = max(1, (int)($_GET['q'] ?? 1));

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing session_id']);
    exit;
}

try {
    $db = Database::pdo();

    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }
    if ($session['completed_at'] !== null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Session already complete']);
        exit;
    }

    $qids = array_map('intval', explode(',', $session['question_ids']));
    $idx  = $qNum - 1;
    if ($idx < 0 || $idx >= count($qids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Question number out of range']);
        exit;
    }
    $questionId = $qids[$idx];

    $qstmt = $db->prepare(
        "SELECT q.id, q.question_text, q.image_path, q.difficulty,
                c.name AS category_name, c.colour AS category_colour,
                COALESCE(m.mastery_level, 0) AS mastery_level
         FROM quiz_questions q
         JOIN quiz_categories c ON c.id = q.category_id
         LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE q.id = ?"
    );
    $qstmt->execute([$userId, $questionId]);
    $question = $qstmt->fetch(PDO::FETCH_ASSOC);
    if (!$question) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Question not found']);
        exit;
    }

    $ostmt = $db->prepare("SELECT id, option_text FROM quiz_options WHERE question_id=? ORDER BY sort_order");
    $ostmt->execute([$questionId]);
    $options = $ostmt->fetchAll(PDO::FETCH_ASSOC);
    shuffle($options);

    $answered = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
    $answered->execute([$sessionId, $questionId]);
    $alreadyAnswered = (bool)$answered->fetch();

    $meta   = masteryMeta();
    $images = getQuestionImages($db, (int)$question['id'], $question['image_path'] ?: null);

    http_response_code(200);
    echo json_encode([
        'success'          => true,
        'question_num'     => $qNum,
        'total'            => (int)$session['questions_count'],
        'already_answered' => $alreadyAnswered,
        'question'         => [
            'id'              => (int)$question['id'],
            'text'            => $question['question_text'],
            'images'          => $images,
            'difficulty'      => $question['difficulty'],
            'category_name'   => $question['category_name'],
            'category_colour' => $question['category_colour'],
            'mastery_level'   => (int)$question['mastery_level'],
            'mastery_name'    => $meta[(int)$question['mastery_level']]['name'],
        ],
        'options' => $options,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/question] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load question.']);
}
