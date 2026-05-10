<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/quiz.php
 *
 * Mobile Knowledge Quiz API — JWT authenticated
 *
 * GET  /api/schedule/quiz?action=question&session_id=N&q=N  — fetch one question
 *
 * POST /api/schedule/quiz
 *   {"action": "start",    "session_length": 10}
 *   {"action": "answer",   "session_id": N, "question_id": N,
 *                          "selected_option_id": N, "time_taken_seconds": N}
 *   {"action": "finish",   "session_id": N}
 *
 * Responses match QuizModels.swift. No CSRF required (JWT authenticated).
 * Pass threshold: ≥ 70% correct (7/10 by default).
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

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];
$db      = getDB();

function qzOk(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function qzErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── Mastery helpers ───────────────────────────────────────────────────────────

function quizMasteryMeta(): array
{
    return [
        0 => ['name' => 'Unseen',   'review_hours' => null],
        1 => ['name' => 'Learning', 'review_hours' => 1],
        2 => ['name' => 'Familiar', 'review_hours' => 24],
        3 => ['name' => 'Good',     'review_hours' => 72],
        4 => ['name' => 'Mastered', 'review_hours' => 168],
        5 => ['name' => 'Expert',   'review_hours' => 336],
    ];
}

function quizUpdateMastery(PDO $db, int $userId, int $questionId, bool $correct, int $timeTaken): array
{
    $stmt = $db->prepare("SELECT * FROM quiz_user_mastery WHERE user_id = ? AND question_id = ?");
    $stmt->execute([$userId, $questionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $currentLevel  = $existing ? (int)$existing['mastery_level']  : 0;
    $currentStreak = $existing ? (int)$existing['correct_streak'] : 0;

    if ($correct) {
        $advance  = ($timeTaken <= 10) ? 2 : 1;
        $newLevel  = min(5, $currentLevel + $advance);
        $newStreak = $currentStreak + 1;
    } else {
        $floor     = $currentLevel >= 1 ? 1 : 0;
        $newLevel  = max($floor, $currentLevel - 1);
        $newStreak = 0;
    }

    $meta       = quizMasteryMeta();
    $hours      = $meta[$newLevel]['review_hours'] ?? null;
    $nextReview = $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;

    if ($existing) {
        $db->prepare(
            "UPDATE quiz_user_mastery
             SET mastery_level=?, correct_streak=?,
                 total_attempts=total_attempts+1,
                 total_correct=total_correct+?,
                 last_seen_at=NOW(), next_review_at=?, updated_at=NOW()
             WHERE user_id=? AND question_id=?"
        )->execute([$newLevel, $newStreak, $correct ? 1 : 0, $nextReview, $userId, $questionId]);
    } else {
        $db->prepare(
            "INSERT INTO quiz_user_mastery
             (user_id, question_id, mastery_level, correct_streak, total_attempts, total_correct, last_seen_at, next_review_at)
             VALUES (?,?,?,?,1,?,NOW(),?)"
        )->execute([$userId, $questionId, $newLevel, $newStreak, $correct ? 1 : 0, $nextReview]);
    }

    return [
        'old_level'  => $currentLevel,
        'new_level'  => $newLevel,
        'delta'      => $newLevel - $currentLevel,
        'level_name' => $meta[$newLevel]['name'],
        'streak'     => $newStreak,
    ];
}

// ── Question selection — mastery-weighted random (mobile simplified) ───────────

function quizSelectQuestions(PDO $db, int $userId, int $sessionLength): array
{
    $stmt = $db->prepare(
        "SELECT q.id,
                COALESCE(m.mastery_level, 0) AS mlvl,
                CASE WHEN m.next_review_at IS NOT NULL AND m.next_review_at <= NOW() THEN 0 ELSE 1 END AS not_due
         FROM quiz_questions q
         LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE q.is_active = 1
         ORDER BY not_due ASC, mlvl ASC, RAND()
         LIMIT ?"
    );
    $stmt->execute([$userId, $sessionLength]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ── Routing ───────────────────────────────────────────────────────────────────

$method    = $_SERVER['REQUEST_METHOD'];
$monthYear = date('Y-m');

// ── GET: question ─────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $action    = $_GET['action'] ?? '';
    $sessionId = (int)($_GET['session_id'] ?? 0);
    $qNum      = (int)($_GET['q'] ?? 1);

    if ($action !== 'question') qzErr('Use ?action=question');
    if ($sessionId <= 0)        qzErr('Invalid session_id');

    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session)                     qzErr('Session not found', 404);
    if ($session['completed_at'])      qzErr('Session already complete');

    $qids = array_map('intval', explode(',', $session['question_ids']));
    $idx  = $qNum - 1;
    if ($idx < 0 || $idx >= count($qids)) qzErr('Question number out of range');
    $questionId = $qids[$idx];

    $qstmt = $db->prepare(
        "SELECT q.id, q.question_text, q.difficulty,
                c.name AS category_name, c.colour AS category_colour,
                COALESCE(m.mastery_level, 0) AS mastery_level
         FROM quiz_questions q
         JOIN quiz_categories c ON c.id = q.category_id
         LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE q.id = ?"
    );
    $qstmt->execute([$userId, $questionId]);
    $question = $qstmt->fetch(PDO::FETCH_ASSOC);
    if (!$question) qzErr('Question not found', 404);

    $ostmt = $db->prepare("SELECT id, option_text FROM quiz_options WHERE question_id=? ORDER BY sort_order");
    $ostmt->execute([$questionId]);
    $options = $ostmt->fetchAll(PDO::FETCH_ASSOC);
    shuffle($options);

    $meta = quizMasteryMeta();

    qzOk([
        'question_num' => $qNum,
        'total'        => (int)$session['questions_count'],
        'question' => [
            'id'              => (int)$question['id'],
            'text'            => $question['question_text'],
            'difficulty'      => $question['difficulty'],
            'category_name'   => $question['category_name'],
            'category_colour' => $question['category_colour'],
            'mastery_level'   => (int)$question['mastery_level'],
            'mastery_name'    => $meta[(int)$question['mastery_level']]['name'],
        ],
        'options' => $options,
    ]);
}

// ── POST actions ──────────────────────────────────────────────────────────────

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// ── POST: start ───────────────────────────────────────────────────────────────

if ($action === 'start') {
    $sessionLength = (int)($input['session_length'] ?? 10);
    if (!in_array($sessionLength, [3, 5, 10], true)) $sessionLength = 10;

    $qids = quizSelectQuestions($db, $userId, $sessionLength);
    if (count($qids) < 1) {
        qzErr('No questions available. Ask an admin to add some.');
    }

    $questionIds = implode(',', $qids);

    $ins = $db->prepare(
        "INSERT INTO quiz_sessions (user_id, question_ids, questions_count, month_year)
         VALUES (?, ?, ?, ?)"
    );
    $ins->execute([$userId, $questionIds, count($qids), $monthYear]);
    $sessionId = (int)$db->lastInsertId();

    qzOk([
        'session_id' => $sessionId,
        'total'      => count($qids),
    ]);
}

// ── POST: answer ──────────────────────────────────────────────────────────────

if ($action === 'answer') {
    $sessionId        = (int)($input['session_id']         ?? 0);
    $questionId       = (int)($input['question_id']        ?? 0);
    $selectedOptionId = isset($input['selected_option_id']) ? (int)$input['selected_option_id'] : null;
    $timeTaken        = max(0, min(30, (int)($input['time_taken_seconds'] ?? 30)));

    if ($sessionId <= 0 || $questionId <= 0) qzErr('Invalid parameters');

    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) qzErr('Session not found or already complete', 404);

    $dup = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
    $dup->execute([$sessionId, $questionId]);
    if ($dup->fetch()) qzErr('Already answered this question');

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

    $masteryUpdate = quizUpdateMastery($db, $userId, $questionId, $isCorrect, $timeTaken);

    qzOk([
        'is_correct'        => $isCorrect,
        'correct_option_id' => $correctOptionId,
        'points_earned'     => $points,
        'mastery'           => $masteryUpdate,
    ]);
}

// ── POST: finish ──────────────────────────────────────────────────────────────

if ($action === 'finish') {
    $sessionId = (int)($input['session_id'] ?? 0);
    if ($sessionId <= 0) qzErr('Invalid session_id');

    $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
    $sess->execute([$sessionId, $userId]);
    $session = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$session) qzErr('Session not found', 404);

    $agg = $db->prepare(
        "SELECT COUNT(*) AS total, SUM(is_correct) AS correct
         FROM quiz_answers WHERE session_id=?"
    );
    $agg->execute([$sessionId]);
    $agg = $agg->fetch(PDO::FETCH_ASSOC);

    $correctCount = (int)($agg['correct'] ?? 0);
    $total        = (int)($agg['total']   ?? 0);

    $db->prepare(
        "UPDATE quiz_sessions SET completed_at=NOW(), correct_count=? WHERE id=?"
    )->execute([$correctCount, $sessionId]);

    $passMark  = (int)ceil((int)$session['questions_count'] * 0.70);
    $passed    = $correctCount >= $passMark;

    qzOk([
        'correct'    => $correctCount,
        'total'      => (int)$session['questions_count'],
        'passed'     => $passed,
        'pass_mark'  => $passMark,
    ]);
}

qzErr('Unknown action. Use start, answer, or finish');
