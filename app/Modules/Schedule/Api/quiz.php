<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/quiz.php
 *
 * Mobile Pre-Shift Quiz API — JWT-authenticated wrapper around the CRM quiz logic.
 *
 * Authorization: Bearer <jwt>
 *
 * POST {action: 'start',  session_length: 10, mode?: 'seasonal'}
 * GET  ?session_id=N&q=N  — fetch one question
 * POST {action: 'answer', session_id: N, question_id: N, selected_option_id?: N, time_taken_seconds: N}
 * POST {action: 'finish', session_id: N}
 *
 * Returns same JSON shape as /crm/api/quiz.php for the four mobile-relevant actions.
 * The finish response adds a computed 'passed' and 'pass_mark' field for the iOS gate.
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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

    $jwtUser   = requireJwt();
    $userId    = (int)$jwtUser['id'];
    $isAdmin   = in_array($jwtUser['role'], ['admin', 'manager'], true);
    $monthYear = date('Y-m');
    $db        = getDB();

    // ── Helpers ───────────────────────────────────────────────────────────────

    function mOk(array $data): void
    {
        echo json_encode(array_merge(['success' => true], $data));
        exit;
    }

    function mErr(string $msg, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    function mPostInput(): array
    {
        return (array)(json_decode(file_get_contents('php://input'), true) ?? []);
    }

    // ── Seasonal selection helpers (duplicated from crm/api/quiz.php) ─────────

    function mGetSeasonContext(int $month = 0): array
    {
        if ($month === 0) $month = (int)date('n');
        $u1 = ($month % 12) + 1;
        $u2 = (($month + 1) % 12) + 1;
        $seasons = [
            [2,  4,  'Late Winter / Early Spring', '🌱', 'Prep season',       ['moss-management','early-spring-lawn','pre-emergent'], ''],
            [4,  6,  'Spring Growth Push',         '🌿', 'Prune after bloom', ['spring-flowering-shrub','grub-prevention'], ''],
            [6,  8,  'Early Summer Peak',           '☀️', 'Heat & irrigation', ['summer-heat','irrigation-awareness'], ''],
            [8,  11, 'Fall Cleanup & Overseeding', '🍂', 'Best overseed window', ['fall-overseeding','fall-aeration'], ''],
            [11, 2,  'Winter & Planning',           '❄️', 'Dormant season',    ['winter-lawn','frost-protection'], ''],
        ];
        $matched = null;
        foreach ($seasons as $s) {
            [$start, $end] = $s;
            if ($start > $end) {
                if ($month >= $start || $month <= $end) { $matched = $s; break; }
            } else {
                if ($month >= $start && $month <= $end) { $matched = $s; break; }
            }
        }
        if (!$matched) $matched = $seasons[0];
        return [
            'month'           => $month,
            'upcoming_months' => [$u1, $u2],
            'season_label'    => $matched[2],
            'season_icon'     => $matched[3],
            'season_tagline'  => $matched[4],
        ];
    }

    function mSelectSeasonalQuestions(PDO $db, int $userId, ?int $categoryId, int $sessionLength): array
    {
        $ctx  = mGetSeasonContext();
        $curM = (string)$ctx['month'];
        $upM1 = (string)$ctx['upcoming_months'][0];
        $upM2 = (string)$ctx['upcoming_months'][1];

        $catWhere = $categoryId !== null ? "AND q.category_id = " . (int)$categoryId : "";
        $hasText  = "AND (q.question_text IS NOT NULL AND q.question_text != '')";

        $targets = [
            'current'  => max(1, (int)round($sessionLength * 0.40)),
            'upcoming' => max(1, (int)round($sessionLength * 0.30)),
            'review'   => max(1, (int)round($sessionLength * 0.20)),
        ];
        $targets['weak'] = max(0, $sessionLength - $targets['current'] - $targets['upcoming'] - $targets['review']);

        $selected = [];

        // Pool 1: current season
        $stmt = $db->prepare(
            "SELECT q.id FROM quiz_questions q
             LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE q.is_active = 1 $catWhere $hasText
               AND FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0
               AND COALESCE(m.mastery_level, 0) < 5
               AND (m.last_seen_at IS NULL OR m.last_seen_at < DATE_SUB(NOW(), INTERVAL 20 HOUR))
             ORDER BY COALESCE(q.seasonal_priority, 5) DESC, COALESCE(m.mastery_level, 0) ASC, RAND()
             LIMIT {$targets['current']}"
        );
        $stmt->execute([$userId, $curM]);
        $selected = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Pool 2: upcoming season
        $excl = implode(',', array_map('intval', $selected ?: [0]));
        $stmt = $db->prepare(
            "SELECT q.id FROM quiz_questions q
             LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE q.is_active = 1 $catWhere $hasText
               AND q.id NOT IN ($excl)
               AND (FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0
                    OR FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0)
               AND COALESCE(m.mastery_level, 0) < 5
             ORDER BY COALESCE(q.seasonal_priority, 5) DESC, RAND()
             LIMIT {$targets['upcoming']}"
        );
        $stmt->execute([$userId, $upM1, $upM2]);
        $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Pool 3: spaced rep review
        $excl = implode(',', array_map('intval', $selected ?: [0]));
        $stmt = $db->prepare(
            "SELECT q.id FROM quiz_questions q
             JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE q.is_active = 1 $catWhere $hasText
               AND q.id NOT IN ($excl)
               AND m.next_review_at <= NOW()
               AND m.mastery_level >= 1
             ORDER BY m.next_review_at ASC
             LIMIT {$targets['review']}"
        );
        $stmt->execute([$userId]);
        $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Pool 4: weak fill
        $needed = $sessionLength - count($selected);
        if ($needed > 0) {
            $excl = implode(',', array_map('intval', $selected ?: [0]));
            $stmt = $db->prepare(
                "SELECT q.id FROM quiz_questions q
                 LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                 WHERE q.is_active = 1 $catWhere $hasText
                   AND q.id NOT IN ($excl)
                   AND (m.last_seen_at IS NULL OR m.last_seen_at < DATE_SUB(NOW(), INTERVAL 20 HOUR))
                 ORDER BY COALESCE(m.mastery_level, 0) ASC, RAND()
                 LIMIT $needed"
            );
            $stmt->execute([$userId]);
            $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        shuffle($selected);
        return array_slice($selected, 0, $sessionLength);
    }

    function mMasteryMeta(): array
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

    function mComputeNewMastery(int $current, bool $correct, int $timeTaken, int $streak): array
    {
        if ($correct) {
            $advance   = ($timeTaken <= 10) ? 2 : 1;
            $newLevel  = min(5, $current + $advance);
            $newStreak = $streak + 1;
        } else {
            $floor     = $current >= 1 ? 1 : 0;
            $newLevel  = max($floor, $current - 1);
            $newStreak = 0;
        }
        $meta       = mMasteryMeta();
        $hours      = $meta[$newLevel]['review_hours'] ?? null;
        $nextReview = $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;
        return ['level' => $newLevel, 'streak' => $newStreak, 'next_review' => $nextReview];
    }

    function mUpdateMastery(PDO $db, int $userId, int $questionId, bool $correct, int $timeTaken): array
    {
        $stmt = $db->prepare("SELECT * FROM quiz_user_mastery WHERE user_id = ? AND question_id = ?");
        $stmt->execute([$userId, $questionId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentLevel  = $existing ? (int)$existing['mastery_level']  : 0;
        $currentStreak = $existing ? (int)$existing['correct_streak'] : 0;
        $totalAttempts = $existing ? (int)$existing['total_attempts'] : 0;
        $totalCorrect  = $existing ? (int)$existing['total_correct']  : 0;

        $computed   = mComputeNewMastery($currentLevel, $correct, $timeTaken, $currentStreak);
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

        $meta = mMasteryMeta();
        return [
            'old_level'  => $currentLevel,
            'new_level'  => $newLevel,
            'delta'      => $newLevel - $currentLevel,
            'level_name' => $meta[$newLevel]['name'],
            'streak'     => $newStreak,
        ];
    }

    // ── Route ─────────────────────────────────────────────────────────────────

    $method = $_SERVER['REQUEST_METHOD'];

    // GET with session_id+q = fetch a question
    if ($method === 'GET' && isset($_GET['session_id'], $_GET['q'])) {
        $sessionId = (int)$_GET['session_id'];
        $qNum      = (int)$_GET['q'];

        if ($sessionId <= 0) mErr('Invalid session');

        $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
        $sess->execute([$sessionId, $userId]);
        $session = $sess->fetch(PDO::FETCH_ASSOC);
        if (!$session) mErr('Session not found', 404);
        if ($session['completed_at'] !== null) mErr('Session already complete');

        $qids = array_map('intval', explode(',', $session['question_ids']));
        $idx  = $qNum - 1;
        if ($idx < 0 || $idx >= count($qids)) mErr('Question number out of range');
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
        if (!$question) mErr('Question not found', 404);

        $ostmt = $db->prepare("SELECT id, option_text FROM quiz_options WHERE question_id=? ORDER BY sort_order");
        $ostmt->execute([$questionId]);
        $options = $ostmt->fetchAll(PDO::FETCH_ASSOC);
        shuffle($options);

        $meta = mMasteryMeta();
        mOk([
            'question_num' => $qNum,
            'total'        => (int)$session['questions_count'],
            'question'     => [
                'id'              => (int)$question['id'],
                'text'            => $question['question_text'],
                'image_path'      => $question['image_path'],
                'difficulty'      => $question['difficulty'],
                'category_name'   => $question['category_name'],
                'category_colour' => $question['category_colour'],
                'mastery_level'   => (int)$question['mastery_level'],
                'mastery_name'    => $meta[(int)$question['mastery_level']]['name'],
            ],
            'options' => $options,
        ]);
    }

    // POST actions
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input  = mPostInput();
    $action = $input['action'] ?? '';

    switch ($action) {

        // ── start ──────────────────────────────────────────────────────────────
        case 'start':
            $categoryId    = isset($input['category_id']) && $input['category_id'] !== '' && $input['category_id'] !== null
                               ? (int)$input['category_id'] : null;
            $mode          = $input['mode'] ?? 'seasonal';
            $sessionLength = (int)($input['session_length'] ?? 10);
            if (!in_array($sessionLength, [3, 5, 10], true)) $sessionLength = 10;

            if ($mode === 'random') {
                if ($categoryId !== null) {
                    $stmt = $db->prepare(
                        "SELECT id FROM quiz_questions WHERE category_id=? AND is_active=1
                           AND (question_text IS NOT NULL AND question_text != '')
                         ORDER BY RAND() LIMIT {$sessionLength}"
                    );
                    $stmt->execute([$categoryId]);
                } else {
                    $stmt = $db->query(
                        "SELECT id FROM quiz_questions WHERE is_active=1
                           AND (question_text IS NOT NULL AND question_text != '')
                         ORDER BY RAND() LIMIT {$sessionLength}"
                    );
                }
                $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                // seasonal (default for mobile)
                $qids = mSelectSeasonalQuestions($db, $userId, $categoryId, $sessionLength);
            }

            if (count($qids) < 1) {
                mErr('No questions available yet. Ask an admin to add some.');
            }

            $questionCount = count($qids);
            $questionIds   = implode(',', $qids);
            $seasonCtx     = mGetSeasonContext();

            $ins = $db->prepare(
                "INSERT INTO quiz_sessions (user_id, category_id, question_ids, questions_count, month_year)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $ins->execute([$userId, $categoryId, $questionIds, $questionCount, $monthYear]);
            $sessionId = (int)$db->lastInsertId();

            mOk([
                'session_id'     => $sessionId,
                'total'          => $questionCount,
                'season_label'   => $seasonCtx['season_label'],
                'season_icon'    => $seasonCtx['season_icon'],
                'season_tagline' => $seasonCtx['season_tagline'],
            ]);

        // ── answer ─────────────────────────────────────────────────────────────
        case 'answer':
            $sessionId        = (int)($input['session_id'] ?? 0);
            $questionId       = (int)($input['question_id'] ?? 0);
            $selectedOptionId = isset($input['selected_option_id']) ? (int)$input['selected_option_id'] : null;
            $timeTaken        = max(0, min(30, (int)($input['time_taken_seconds'] ?? 30)));

            if ($sessionId <= 0 || $questionId <= 0) mErr('Invalid parameters');

            $sess = $db->prepare(
                "SELECT * FROM quiz_sessions WHERE id=? AND user_id=? AND completed_at IS NULL"
            );
            $sess->execute([$sessionId, $userId]);
            $session = $sess->fetch(PDO::FETCH_ASSOC);
            if (!$session) mErr('Session not found or already complete', 404);

            $dup = $db->prepare("SELECT id FROM quiz_answers WHERE session_id=? AND question_id=?");
            $dup->execute([$sessionId, $questionId]);
            if ($dup->fetch()) mErr('Already answered this question');

            $corrStmt = $db->prepare(
                "SELECT id FROM quiz_options WHERE question_id=? AND is_correct=1 LIMIT 1"
            );
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

            $masteryUpdate = mUpdateMastery($db, $userId, $questionId, $isCorrect, $timeTaken);

            mOk([
                'is_correct'        => $isCorrect,
                'correct_option_id' => $correctOptionId,
                'points_earned'     => $points,
                'mastery'           => $masteryUpdate,
            ]);

        // ── finish ─────────────────────────────────────────────────────────────
        case 'finish':
            $sessionId = (int)($input['session_id'] ?? 0);
            if ($sessionId <= 0) mErr('Invalid session');

            $sess = $db->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
            $sess->execute([$sessionId, $userId]);
            $session = $sess->fetch(PDO::FETCH_ASSOC);
            if (!$session) mErr('Session not found', 404);

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
            $totalAnswers = (int)($agg['total']   ?? 0);
            $totalPoints  = (int)($agg['points']  ?? 0);
            $totalQs      = (int)$session['questions_count'];

            $db->prepare(
                "UPDATE quiz_sessions SET completed_at=NOW(), correct_count=?, total_points=? WHERE id=?"
            )->execute([$correctCount, $totalPoints, $sessionId]);

            // Pass = 70% correct (round up)
            $passMark = (int)ceil($totalQs * 0.70);
            $passed   = $correctCount >= $passMark;

            // Record pre-shift completion
            $pct = $totalQs > 0 ? (int)round($correctCount / $totalQs * 100) : 0;
            $db->prepare(
                "INSERT IGNORE INTO quiz_preshift_log
                 (user_id, log_date, session_id, questions_asked, questions_correct, score_pct, completed_at)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, NOW())"
            )->execute([$userId, $sessionId, $totalQs, $correctCount, $pct]);

            mOk([
                'correct'   => $correctCount,
                'total'     => $totalQs,
                'points'    => $totalPoints,
                'passed'    => $passed,
                'pass_mark' => $passMark,
            ]);

        default:
            mErr('Unknown action: ' . htmlspecialchars($action), 400);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
