<?php
/**
 * /public/crm/api/quiz.php
 * Knowledge Quiz JSON API — all quiz actions.
 *
 * GET  actions: categories, question, questions, get_question, leaderboard,
 *               my_stats, mastery_stats, learn_cards, prizes
 * POST actions: start, answer, finish, mark_learned, save_category,
 *               save_question, upload_image, delete_question, award_prize
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

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
requireLogin();
$user      = getCurrentUser();
$userId    = (int)$user['id'];
$monthYear = date('Y-m');
$isAdmin   = ($user['role'] ?? '') === 'admin';

session_write_close();

$db     = getDB();
$action = $_GET['action'] ?? '';

// ── Helpers ───────────────────────────────────────────────────────────────────

function qOk(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function qErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function requireAdminQ(bool $isAdmin): void
{
    if (!$isAdmin) qErr('Admin only', 403);
}

function postInput(): array
{
    return (array)(json_decode(file_get_contents('php://input'), true) ?? []);
}

function verifyCsrf(array $input): void
{
    if (function_exists('verifyCSRFToken')) {
        verifyCSRFToken($input['csrf_token'] ?? '');
    }
}

/**
 * Mastery level names and review intervals in hours.
 */
function masteryMeta(): array
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

/**
 * Compute new mastery level after an answer.
 * Speed bonus: ≤10s correct → advance 2 levels; else advance 1.
 * Wrong: drop 1 level (floor = 1 if already seen, 0 if unseen).
 */
function computeNewMastery(int $current, bool $correct, int $timeTaken, int $streak): array
{
    if ($correct) {
        $advance = ($timeTaken <= 10) ? 2 : 1;
        $newLevel  = min(5, $current + $advance);
        $newStreak = $streak + 1;
    } else {
        $floor    = $current >= 1 ? 1 : 0;
        $newLevel  = max($floor, $current - 1);
        $newStreak = 0;
    }
    $meta = masteryMeta();
    $hours = $meta[$newLevel]['review_hours'] ?? null;
    $nextReview = $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;
    return ['level' => $newLevel, 'streak' => $newStreak, 'next_review' => $nextReview];
}

/**
 * Upsert quiz_user_mastery for one answer.
 */
function updateMastery(PDO $db, int $userId, int $questionId, bool $correct, int $timeTaken): array
{
    // Fetch existing
    $stmt = $db->prepare("SELECT * FROM quiz_user_mastery WHERE user_id = ? AND question_id = ?");
    $stmt->execute([$userId, $questionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $currentLevel  = $existing ? (int)$existing['mastery_level']  : 0;
    $currentStreak = $existing ? (int)$existing['correct_streak'] : 0;
    $totalAttempts = $existing ? (int)$existing['total_attempts'] : 0;
    $totalCorrect  = $existing ? (int)$existing['total_correct']  : 0;

    $computed = computeNewMastery($currentLevel, $correct, $timeTaken, $currentStreak);

    $newLevel   = $computed['level'];
    $newStreak  = $computed['streak'];
    $nextReview = $computed['next_review'];

    if ($existing) {
        $db->prepare(
            "UPDATE quiz_user_mastery
             SET mastery_level=?, correct_streak=?, total_attempts=?, total_correct=?,
                 last_seen_at=NOW(), next_review_at=?, updated_at=NOW()
             WHERE user_id=? AND question_id=?"
        )->execute([
            $newLevel, $newStreak,
            $totalAttempts + 1,
            $totalCorrect + ($correct ? 1 : 0),
            $nextReview,
            $userId, $questionId,
        ]);
    } else {
        $db->prepare(
            "INSERT INTO quiz_user_mastery
             (user_id, question_id, mastery_level, correct_streak, total_attempts, total_correct, last_seen_at, next_review_at)
             VALUES (?,?,?,?,?,?,NOW(),?)"
        )->execute([
            $userId, $questionId,
            $newLevel, $newStreak,
            1,
            $correct ? 1 : 0,
            $nextReview,
        ]);
    }

    $meta = masteryMeta();
    return [
        'old_level'        => $currentLevel,
        'new_level'        => $newLevel,
        'delta'            => $newLevel - $currentLevel,
        'level_name'       => $meta[$newLevel]['name'],
        'streak'           => $newStreak,
    ];
}

// ── Routing ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: categories (with mastery breakdown per user) ─────────────────────
    case 'categories':
        $rows = $db->prepare(
            "SELECT c.id, c.name, c.description, c.icon, c.colour, c.sort_order,
                    COUNT(q.id) AS question_count,
                    SUM(CASE WHEN m.mastery_level >= 4 THEN 1 ELSE 0 END) AS mastered_count,
                    SUM(CASE WHEN m.mastery_level BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS learning_count,
                    SUM(CASE WHEN m.mastery_level IS NULL OR m.mastery_level = 0 THEN 1 ELSE 0 END) AS unseen_count,
                    SUM(CASE WHEN m.next_review_at <= NOW() AND m.mastery_level > 0 THEN 1 ELSE 0 END) AS due_count
             FROM quiz_categories c
             LEFT JOIN quiz_questions q ON q.category_id = c.id AND q.is_active = 1
             LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE c.is_active = 1
             GROUP BY c.id
             ORDER BY c.sort_order, c.name"
        );
        $rows->execute([$userId]);
        $cats = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cats as &$c) {
            $c['question_count']  = (int)$c['question_count'];
            $c['mastered_count']  = (int)$c['mastered_count'];
            $c['learning_count']  = (int)$c['learning_count'];
            $c['unseen_count']    = (int)$c['unseen_count'];
            $c['due_count']       = (int)$c['due_count'];
        }
        unset($c);
        qOk(['categories' => $cats]);

    // ── GET: flashcard learn cards for a category ─────────────────────────────
    case 'learn_cards':
        $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== ''
                        ? (int)$_GET['category_id'] : null;

        // Fetch unseen (mastery_level=0 or no row) + overdue (next_review_at <= NOW)
        // Ordered by: unseen first, then by next_review_at ascending, limit 15
        if ($categoryId !== null) {
            $stmt = $db->prepare(
                "SELECT q.id, q.question_text, q.learn_notes, q.image_path, q.difficulty,
                        c.name AS category_name, c.colour AS category_colour,
                        COALESCE(m.mastery_level, 0) AS mastery_level
                 FROM quiz_questions q
                 JOIN quiz_categories c ON c.id = q.category_id
                 LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                 WHERE q.category_id = ? AND q.is_active = 1
                   AND (m.mastery_level IS NULL OR m.mastery_level = 0
                        OR (m.next_review_at IS NOT NULL AND m.next_review_at <= NOW()))
                 ORDER BY COALESCE(m.mastery_level, 0) ASC, COALESCE(m.next_review_at, '1970-01-01') ASC
                 LIMIT 15"
            );
            $stmt->execute([$userId, $categoryId]);
        } else {
            $stmt = $db->prepare(
                "SELECT q.id, q.question_text, q.learn_notes, q.image_path, q.difficulty,
                        c.name AS category_name, c.colour AS category_colour,
                        COALESCE(m.mastery_level, 0) AS mastery_level
                 FROM quiz_questions q
                 JOIN quiz_categories c ON c.id = q.category_id
                 LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                 WHERE q.is_active = 1
                   AND (m.mastery_level IS NULL OR m.mastery_level = 0
                        OR (m.next_review_at IS NOT NULL AND m.next_review_at <= NOW()))
                 ORDER BY COALESCE(m.mastery_level, 0) ASC, RAND()
                 LIMIT 15"
            );
            $stmt->execute([$userId]);
        }
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cards as &$card) {
            $card['mastery_level'] = (int)$card['mastery_level'];
        }
        unset($card);
        qOk(['cards' => $cards, 'total' => count($cards)]);

    // ── POST: mark flashcard as learned ───────────────────────────────────────
    case 'mark_learned':
        $input      = postInput();
        verifyCsrf($input);
        $questionId = (int)($input['question_id'] ?? 0);
        $result     = $input['result'] ?? ''; // 'got_it' | 'still_learning'

        if ($questionId <= 0) qErr('Invalid question_id');
        if (!in_array($result, ['got_it', 'still_learning'])) qErr('Invalid result');

        // "got_it" → mastery=1, review in 1h. "still_learning" → mastery=1, review in 30min.
        $reviewHours = ($result === 'got_it') ? 1 : 0.5;
        $nextReview  = date('Y-m-d H:i:s', time() + (int)($reviewHours * 3600));

        $existing = $db->prepare("SELECT mastery_level FROM quiz_user_mastery WHERE user_id=? AND question_id=?");
        $existing->execute([$userId, $questionId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Only update if still at level 0 or 1 (don't downgrade higher mastery from flashcard)
            if ((int)$row['mastery_level'] <= 1) {
                $db->prepare(
                    "UPDATE quiz_user_mastery SET mastery_level=1, last_seen_at=NOW(), next_review_at=?, updated_at=NOW()
                     WHERE user_id=? AND question_id=?"
                )->execute([$nextReview, $userId, $questionId]);
            }
        } else {
            $db->prepare(
                "INSERT INTO quiz_user_mastery (user_id, question_id, mastery_level, last_seen_at, next_review_at)
                 VALUES (?,?,1,NOW(),?)"
            )->execute([$userId, $questionId, $nextReview]);
        }

        $meta = masteryMeta();
        qOk(['mastery_level' => 1, 'level_name' => $meta[1]['name']]);

    // ── GET: mastery stats per category (or all) for current user ─────────────
    case 'mastery_stats':
        $catFilter = isset($_GET['category_id']) && $_GET['category_id'] !== ''
                       ? (int)$_GET['category_id'] : null;

        $where  = $catFilter !== null ? 'AND q.category_id = ?' : '';
        $params = $catFilter !== null ? [$userId, $catFilter] : [$userId];

        $stmt = $db->prepare(
            "SELECT q.category_id,
                    COUNT(q.id)  AS total,
                    SUM(CASE WHEN COALESCE(m.mastery_level,0) = 0 THEN 1 ELSE 0 END) AS unseen,
                    SUM(CASE WHEN COALESCE(m.mastery_level,0) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS learning,
                    SUM(CASE WHEN COALESCE(m.mastery_level,0) >= 4 THEN 1 ELSE 0 END) AS mastered,
                    SUM(CASE WHEN m.next_review_at <= NOW() AND COALESCE(m.mastery_level,0) > 0 THEN 1 ELSE 0 END) AS due
             FROM quiz_questions q
             LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE q.is_active = 1 $where
             GROUP BY q.category_id"
        );
        $stmt->execute($params);
        $stats = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats[(int)$row['category_id']] = [
                'total'    => (int)$row['total'],
                'unseen'   => (int)$row['unseen'],
                'learning' => (int)$row['learning'],
                'mastered' => (int)$row['mastered'],
                'due'      => (int)$row['due'],
            ];
        }
        qOk(['stats' => $stats]);

    // ── POST: start quiz session ───────────────────────────────────────────────
    case 'start':
        $input      = postInput();
        verifyCsrf($input);
        $categoryId = isset($input['category_id']) && $input['category_id'] !== '' && $input['category_id'] !== null
                        ? (int)$input['category_id'] : null;
        $mode = $input['mode'] ?? 'random'; // 'random' | 'test' (priority: due/weak first)

        if ($mode === 'test') {
            // Priority order: due for review → lowest mastery → random
            if ($categoryId !== null) {
                $stmt = $db->prepare(
                    "SELECT q.id,
                            COALESCE(m.mastery_level, 0) AS mlvl,
                            CASE WHEN m.next_review_at IS NOT NULL AND m.next_review_at <= NOW() THEN 0 ELSE 1 END AS not_due
                     FROM quiz_questions q
                     LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                     WHERE q.category_id = ? AND q.is_active = 1
                     ORDER BY not_due ASC, mlvl ASC, RAND()
                     LIMIT 10"
                );
                $stmt->execute([$userId, $categoryId]);
            } else {
                $stmt = $db->prepare(
                    "SELECT q.id,
                            COALESCE(m.mastery_level, 0) AS mlvl,
                            CASE WHEN m.next_review_at IS NOT NULL AND m.next_review_at <= NOW() THEN 0 ELSE 1 END AS not_due
                     FROM quiz_questions q
                     LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                     WHERE q.is_active = 1
                     ORDER BY not_due ASC, mlvl ASC, RAND()
                     LIMIT 10"
                );
                $stmt->execute([$userId]);
            }
        } else {
            // Random mode (original behaviour)
            if ($categoryId !== null) {
                $stmt = $db->prepare("SELECT id FROM quiz_questions WHERE category_id=? AND is_active=1 ORDER BY RAND() LIMIT 10");
                $stmt->execute([$categoryId]);
            } else {
                $stmt = $db->query("SELECT id FROM quiz_questions WHERE is_active=1 ORDER BY RAND() LIMIT 10");
            }
        }

        $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($qids) < 1) {
            qErr('No questions available for this category yet. Ask an admin to add some!');
        }

        $questionCount = count($qids);
        $questionIds   = implode(',', $qids);

        $ins = $db->prepare(
            "INSERT INTO quiz_sessions (user_id, category_id, question_ids, questions_count, month_year)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$userId, $categoryId, $questionIds, $questionCount, $monthYear]);
        $sessionId = (int)$db->lastInsertId();

        qOk(['session_id' => $sessionId, 'total' => $questionCount]);

    // ── GET: single question ───────────────────────────────────────────────────
    case 'question':
        $sessionId = (int)($_GET['session_id'] ?? 0);
        $qNum      = (int)($_GET['q'] ?? 1);

        if ($sessionId <= 0) qErr('Invalid session');

        $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
        $sess->execute([$sessionId, $userId]);
        $session = $sess->fetch(PDO::FETCH_ASSOC);
        if (!$session) qErr('Session not found', 404);
        if ($session['completed_at'] !== null) qErr('Session already complete');

        $qids = array_map('intval', explode(',', $session['question_ids']));
        $idx  = $qNum - 1;
        if ($idx < 0 || $idx >= count($qids)) qErr('Question number out of range');
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
        if (!$question) qErr('Question not found', 404);

        $ostmt = $db->prepare("SELECT id, option_text FROM quiz_options WHERE question_id=? ORDER BY sort_order");
        $ostmt->execute([$questionId]);
        $options = $ostmt->fetchAll(PDO::FETCH_ASSOC);
        shuffle($options);

        $answered = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
        $answered->execute([$sessionId, $questionId]);
        $alreadyAnswered = (bool)$answered->fetch();

        $meta = masteryMeta();
        qOk([
            'question_num'     => $qNum,
            'total'            => (int)$session['questions_count'],
            'already_answered' => $alreadyAnswered,
            'question' => [
                'id'               => (int)$question['id'],
                'text'             => $question['question_text'],
                'image_path'       => $question['image_path'],
                'difficulty'       => $question['difficulty'],
                'category_name'    => $question['category_name'],
                'category_colour'  => $question['category_colour'],
                'mastery_level'    => (int)$question['mastery_level'],
                'mastery_name'     => $meta[(int)$question['mastery_level']]['name'],
            ],
            'options' => $options,
        ]);

    // ── POST: submit answer ────────────────────────────────────────────────────
    case 'answer':
        $input            = postInput();
        verifyCsrf($input);
        $sessionId        = (int)($input['session_id'] ?? 0);
        $questionId       = (int)($input['question_id'] ?? 0);
        $selectedOptionId = isset($input['selected_option_id']) ? (int)$input['selected_option_id'] : null;
        $timeTaken        = max(0, min(30, (int)($input['time_taken_seconds'] ?? 30)));

        if ($sessionId <= 0 || $questionId <= 0) qErr('Invalid parameters');

        $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL");
        $sess->execute([$sessionId, $userId]);
        $session = $sess->fetch(PDO::FETCH_ASSOC);
        if (!$session) qErr('Session not found or already complete', 404);

        $dup = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
        $dup->execute([$sessionId, $questionId]);
        if ($dup->fetch()) qErr('Already answered this question');

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

        // Update mastery
        $masteryUpdate = updateMastery($db, $userId, $questionId, $isCorrect, $timeTaken);

        qOk([
            'is_correct'        => $isCorrect,
            'correct_option_id' => $correctOptionId,
            'points_earned'     => $points,
            'mastery'           => $masteryUpdate,
        ]);

    // ── POST: finish session ───────────────────────────────────────────────────
    case 'finish':
        $input     = postInput();
        verifyCsrf($input);
        $sessionId = (int)($input['session_id'] ?? 0);
        if ($sessionId <= 0) qErr('Invalid session');

        $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
        $sess->execute([$sessionId, $userId]);
        $session = $sess->fetch(PDO::FETCH_ASSOC);
        if (!$session) qErr('Session not found', 404);

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

        $rankStmt = $db->prepare(
            "SELECT user_id, SUM(total_points) AS monthly_pts
             FROM quiz_sessions
             WHERE month_year=? AND completed_at IS NOT NULL
             GROUP BY user_id ORDER BY monthly_pts DESC"
        );
        $rankStmt->execute([$monthYear]);
        $ranks = $rankStmt->fetchAll(PDO::FETCH_ASSOC);
        $myRank = $myMonthlyTotal = 0;
        foreach ($ranks as $i => $r) {
            if ((int)$r['user_id'] === $userId) {
                $myRank         = $i + 1;
                $myMonthlyTotal = (int)$r['monthly_pts'];
                break;
            }
        }

        qOk([
            'correct'       => $correctCount,
            'total'         => (int)$session['questions_count'],
            'points'        => $totalPoints,
            'monthly_rank'  => $myRank,
            'monthly_total' => $myMonthlyTotal,
        ]);

    // ── GET: monthly leaderboard ───────────────────────────────────────────────
    case 'leaderboard':
        $month = $_GET['month'] ?? $monthYear;
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = $monthYear;

        $rows = $db->prepare(
            "SELECT u.id, u.full_name,
                    SUM(s.total_points)    AS monthly_points,
                    COUNT(s.id)            AS games_played,
                    SUM(s.correct_count)   AS total_correct,
                    SUM(s.questions_count) AS total_questions,
                    MAX(s.total_points)    AS best_game
             FROM quiz_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.month_year=? AND s.completed_at IS NOT NULL
             GROUP BY u.id, u.full_name
             ORDER BY monthly_points DESC, games_played DESC
             LIMIT 20"
        );
        $rows->execute([$month]);
        $leaders = $rows->fetchAll(PDO::FETCH_ASSOC);

        $prizeStmt = $db->prepare("SELECT winner_user_id FROM quiz_monthly_prizes WHERE month_year=? LIMIT 1");
        $prizeStmt->execute([$month]);
        $prizeWinner = $prizeStmt->fetchColumn();

        foreach ($leaders as &$row) {
            $row['monthly_points']  = (int)$row['monthly_points'];
            $row['games_played']    = (int)$row['games_played'];
            $row['total_correct']   = (int)$row['total_correct'];
            $row['total_questions'] = (int)$row['total_questions'];
            $row['best_game']       = (int)$row['best_game'];
            $row['prize_awarded']   = ($prizeWinner && (int)$prizeWinner === (int)$row['id']);
        }
        unset($row);
        qOk(['leaderboard' => $leaders, 'month' => $month]);

    // ── GET: my stats ──────────────────────────────────────────────────────────
    case 'my_stats':
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

        // Total mastered across all categories
        $masteredStmt = $db->prepare(
            "SELECT COUNT(*) FROM quiz_user_mastery WHERE user_id=? AND mastery_level >= 4"
        );
        $masteredStmt->execute([$userId]);
        $totalMastered = (int)$masteredStmt->fetchColumn();

        $rankStmt = $db->prepare(
            "SELECT user_id FROM (
                SELECT user_id, SUM(total_points) AS pts
                FROM quiz_sessions WHERE month_year=? AND completed_at IS NOT NULL
                GROUP BY user_id ORDER BY pts DESC
             ) ranked"
        );
        $rankStmt->execute([$monthYear]);
        $rankedIds = $rankStmt->fetchAll(PDO::FETCH_COLUMN);
        $myRank    = array_search($userId, array_map('intval', $rankedIds));
        $myRank    = $myRank !== false ? $myRank + 1 : null;

        qOk([
            'games_played'    => (int)($stats['games_played'] ?? 0),
            'monthly_points'  => (int)($stats['monthly_points'] ?? 0),
            'best_game'       => (int)($stats['best_game'] ?? 0),
            'accuracy_pct'    => $stats['total_questions'] > 0
                                   ? round(($stats['total_correct'] / $stats['total_questions']) * 100)
                                   : 0,
            'monthly_rank'    => $myRank,
            'total_mastered'  => $totalMastered,
        ]);

    // ── Admin: list questions ──────────────────────────────────────────────────
    case 'questions':
        requireAdminQ($isAdmin);
        $catFilter   = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
        $whereClause = $catFilter !== null ? "WHERE q.category_id=?" : "WHERE 1=1";
        $params      = $catFilter !== null ? [$catFilter] : [];

        $stmt = $db->prepare(
            "SELECT q.id, q.question_text, q.learn_notes, q.image_path, q.difficulty, q.is_active,
                    c.name AS category_name, c.colour AS category_colour,
                    (SELECT COUNT(*) FROM quiz_options o WHERE o.question_id=q.id) AS option_count
             FROM quiz_questions q
             JOIN quiz_categories c ON c.id=q.category_id
             $whereClause
             ORDER BY c.sort_order, q.id DESC"
        );
        $stmt->execute($params);
        qOk(['questions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // ── Admin: single question with options ────────────────────────────────────
    case 'get_question':
        requireAdminQ($isAdmin);
        $qid = (int)($_GET['id'] ?? 0);
        if ($qid <= 0) qErr('Invalid id');

        $qstmt = $db->prepare("SELECT * FROM quiz_questions WHERE id=?");
        $qstmt->execute([$qid]);
        $question = $qstmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) qErr('Not found', 404);

        $ostmt = $db->prepare("SELECT id, option_text, is_correct, sort_order FROM quiz_options WHERE question_id=? ORDER BY sort_order");
        $ostmt->execute([$qid]);
        $question['options'] = $ostmt->fetchAll(PDO::FETCH_ASSOC);
        qOk(['question' => $question]);

    // ── Admin: save category ───────────────────────────────────────────────────
    case 'save_category':
        requireAdminQ($isAdmin);
        $input = postInput();
        verifyCsrf($input);
        $id          = isset($input['id']) ? (int)$input['id'] : null;
        $name        = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $icon        = trim($input['icon'] ?? 'help-circle');
        $colour      = trim($input['colour'] ?? '#2D8659');
        $sortOrder   = (int)($input['sort_order'] ?? 0);
        $isActive    = isset($input['is_active']) ? (int)$input['is_active'] : 1;
        if ($name === '') qErr('Name is required');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) qErr('Invalid colour');

        if ($id) {
            $db->prepare("UPDATE quiz_categories SET name=?,description=?,icon=?,colour=?,sort_order=?,is_active=? WHERE id=?")
               ->execute([$name,$description,$icon,$colour,$sortOrder,$isActive,$id]);
            qOk(['message' => 'Category updated', 'id' => $id]);
        } else {
            $db->prepare("INSERT INTO quiz_categories (name,description,icon,colour,sort_order,is_active) VALUES (?,?,?,?,?,?)")
               ->execute([$name,$description,$icon,$colour,$sortOrder,$isActive]);
            qOk(['message' => 'Category created', 'id' => (int)$db->lastInsertId()]);
        }

    // ── Admin: save question + options ─────────────────────────────────────────
    case 'save_question':
        requireAdminQ($isAdmin);
        $input = postInput();
        verifyCsrf($input);
        $id           = isset($input['id']) ? (int)$input['id'] : null;
        $categoryId   = (int)($input['category_id'] ?? 0);
        $questionText = trim($input['question_text'] ?? '');
        $learnNotes   = trim($input['learn_notes'] ?? '') ?: null;
        $imagePath    = trim($input['image_path'] ?? '') ?: null;
        $difficulty   = in_array($input['difficulty'] ?? '', ['easy','medium','hard']) ? $input['difficulty'] : 'medium';
        $options      = $input['options'] ?? [];
        $isActive     = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if ($categoryId <= 0) qErr('Category is required');
        if ($questionText === '') qErr('Question text is required');
        if (count($options) < 2) qErr('At least 2 options required');
        $correctCount = 0;
        foreach ($options as $opt) { if (!empty($opt['is_correct'])) $correctCount++; }
        if ($correctCount !== 1) qErr('Exactly one correct option required');

        if ($id) {
            $db->prepare(
                "UPDATE quiz_questions SET category_id=?,question_text=?,learn_notes=?,image_path=?,difficulty=?,is_active=?,updated_at=NOW() WHERE id=?"
            )->execute([$categoryId,$questionText,$learnNotes,$imagePath,$difficulty,$isActive,$id]);
            $db->prepare("DELETE FROM quiz_options WHERE question_id=?")->execute([$id]);
        } else {
            $db->prepare(
                "INSERT INTO quiz_questions (category_id,question_text,learn_notes,image_path,difficulty,is_active,created_by) VALUES (?,?,?,?,?,?,?)"
            )->execute([$categoryId,$questionText,$learnNotes,$imagePath,$difficulty,$isActive,$userId]);
            $id = (int)$db->lastInsertId();
        }

        $optStmt = $db->prepare("INSERT INTO quiz_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)");
        foreach (array_values($options) as $i => $opt) {
            $optStmt->execute([$id, trim($opt['option_text']??''), empty($opt['is_correct'])?0:1, $i]);
        }
        qOk(['message' => 'Question saved', 'id' => $id]);

    // ── Admin: upload question image ───────────────────────────────────────────
    case 'upload_image':
        requireAdminQ($isAdmin);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') qErr('POST required', 405);
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) qErr('Upload failed');
        $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, $allowed, true)) qErr('Invalid file type');
        if ($file['size'] > 8 * 1024 * 1024) qErr('File too large — max 8MB');
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $ext      = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'jpg';
        $filename = 'quiz_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir  = PUBLIC_ROOT . '/uploads/quiz';
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) qErr('Failed to save file');
        qOk(['image_path' => '/uploads/quiz/' . $filename]);

    // ── Admin: deactivate question ─────────────────────────────────────────────
    case 'delete_question':
        requireAdminQ($isAdmin);
        $input = postInput(); verifyCsrf($input);
        $qid = (int)($input['id'] ?? 0);
        if ($qid <= 0) qErr('Invalid id');
        $db->prepare("UPDATE quiz_questions SET is_active=0 WHERE id=?")->execute([$qid]);
        qOk(['message' => 'Question deactivated']);

    // ── Admin: award prize ─────────────────────────────────────────────────────
    case 'award_prize':
        requireAdminQ($isAdmin);
        $input    = postInput(); verifyCsrf($input);
        $winnerId = (int)($input['winner_user_id'] ?? 0);
        $prize    = trim($input['prize_description'] ?? '');
        $month    = trim($input['month_year'] ?? $monthYear);
        $notes    = trim($input['notes'] ?? '');
        if ($winnerId <= 0) qErr('Winner required');
        if ($prize === '') qErr('Prize description required');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) qErr('Invalid month format');
        $db->prepare(
            "INSERT INTO quiz_monthly_prizes (month_year,winner_user_id,prize_description,awarded_at,awarded_by,notes)
             VALUES (?,?,?,NOW(),?,?)"
        )->execute([$month,$winnerId,$prize,$userId,$notes]);
        qOk(['message' => 'Prize recorded']);

    // ── Admin: list prizes ─────────────────────────────────────────────────────
    case 'prizes':
        requireAdminQ($isAdmin);
        $stmt = $db->query(
            "SELECT p.*, u.full_name AS winner_name, ab.full_name AS awarded_by_name
             FROM quiz_monthly_prizes p
             JOIN users u  ON u.id = p.winner_user_id
             JOIN users ab ON ab.id = p.awarded_by
             ORDER BY p.month_year DESC, p.created_at DESC"
        );
        qOk(['prizes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    default:
        qErr('Unknown action', 404);
}
