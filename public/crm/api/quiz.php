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
 * Return current season context based on month (Vancouver-specific).
 */
function getSeasonContext(int $month = 0): array
{
    if ($month === 0) $month = (int)date('n');

    // Upcoming months (next 2)
    $u1 = ($month % 12) + 1;
    $u2 = (($month + 1) % 12) + 1;

    $seasons = [
        // [start, end, label, icon, tagline, focus_tags, tip]
        [2,  4,  'Late Winter / Early Spring', '🌱', 'Prep season — moss, drainage, pre-emergent, early fertilizer',
         ['moss-management','early-spring-lawn','pre-emergent','crabgrass-prevention','chafer-awareness','spring-fertilizer','drainage'],
         'Check every lawn for moss and drainage. Pre-emergent crabgrass control must go down before soil hits 10°C. Early fertilizer gets roots moving.'],
        [4,  6,  'Spring Growth Push', '🌿', 'Prune after flowering, ramp up mowing, apply grub prevention',
         ['spring-flowering-shrub','prune-after-flowering','hedge-timing','grub-prevention','lawn-growth','spring-lawn'],
         'Rhododendrons and azaleas are finishing bloom — prune lightly now, not in late summer. Apply Acelepryn for chafer before eggs hatch (June).'],
        [6,  8,  'Early Summer Peak', '☀️', 'Heat stress, irrigation, hedges, summer annuals',
         ['summer-heat','heat-stress','irrigation-awareness','hedge-timing','summer-annuals','summer-lawn'],
         'Raise mowing height in heat. Watch for heat stress wilting and chafer grub spongy patches. Hedges need trimming every 6–8 weeks.'],
        [8,  11, 'Fall Cleanup & Overseeding', '🍂', 'Best window: overseed, aerate, chafer check, fall fertilizer',
         ['fall-overseeding','fall-aeration','fall-fertilizer','chafer-grub-damage','spongy-turf','fall-bulbs','moss-prep'],
         'Late August to October — best overseeding window. Aerate before seeding. Check spongy turf for chafer grubs. Apply fall fertilizer Sept–Oct.'],
        [11, 2,  'Winter & Planning', '❄️', 'Dormant season — protect, plan, and train',
         ['winter-lawn','frost-protection','winter-planning','client-education','root-rot','wet-season'],
         'Avoid traffic on frozen or waterlogged turf. Watch for root rot on rhododendrons in heavy clay. Great time for team training and spring program planning.'],
    ];

    $matched = null;
    foreach ($seasons as $s) {
        [$start, $end] = $s;
        // Wrap-around season (e.g. winter: 11–2)
        if ($start > $end) {
            if ($month >= $start || $month <= $end) { $matched = $s; break; }
        } else {
            if ($month >= $start && $month <= $end) { $matched = $s; break; }
        }
    }
    if (!$matched) $matched = $seasons[0]; // default

    return [
        'month'          => $month,
        'upcoming_months'=> [$u1, $u2],
        'season_label'   => $matched[2],
        'season_icon'    => $matched[3],
        'season_tagline' => $matched[4],
        'focus_tags'     => $matched[5],
        'tip'            => $matched[6],
    ];
}

/**
 * Fetch images for a single question from quiz_question_images.
 * Falls back to question's image_path if table has no rows yet.
 */
function getQuestionImages(PDO $db, int $questionId, ?string $fallbackImagePath = null): array {
    $stmt = $db->prepare(
        "SELECT image_path, sort_order, COALESCE(caption,'') AS caption
         FROM quiz_question_images WHERE question_id=? ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$questionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows && $fallbackImagePath) {
        $rows = [['image_path' => $fallbackImagePath, 'sort_order' => 0, 'caption' => '']];
    }
    return $rows;
}

/**
 * Batch-fetch images for multiple questions (one query, N+1 free).
 * Returns array keyed by question_id.
 */
function getImagesForQuestions(PDO $db, array $questionIds): array {
    if (!$questionIds) return [];
    $ids  = implode(',', array_map('intval', $questionIds));
    $rows = $db->query(
        "SELECT question_id, image_path, sort_order, COALESCE(caption,'') AS caption
         FROM quiz_question_images WHERE question_id IN ($ids)
         ORDER BY question_id, sort_order ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['question_id']][] = $r;
    }
    return $map;
}

/**
 * Select questions using seasonal blending (40/30/20/10).
 * Pool 1 (40%): current season questions not fully mastered
 * Pool 2 (30%): upcoming season questions not fully mastered
 * Pool 3 (20%): spaced-rep review (due questions with mastery >= 1)
 * Pool 4 (10%): weak/unlearned fill
 * Falls back gracefully when pools are undersized.
 */
function selectSeasonalQuestions(PDO $db, int $userId, ?int $categoryId, int $sessionLength): array
{
    $ctx   = getSeasonContext();
    $curM  = (string)$ctx['month'];
    $upM1  = (string)$ctx['upcoming_months'][0];
    $upM2  = (string)$ctx['upcoming_months'][1];

    $catWhere = $categoryId !== null ? "AND q.category_id = " . (int)$categoryId : "";

    $targets = [
        'current'  => max(1, (int)round($sessionLength * 0.40)),
        'upcoming' => max(1, (int)round($sessionLength * 0.30)),
        'review'   => max(1, (int)round($sessionLength * 0.20)),
    ];
    $targets['weak'] = $sessionLength - $targets['current'] - $targets['upcoming'] - $targets['review'];
    if ($targets['weak'] < 0) $targets['weak'] = 0;

    $selected = [];

    $hasText = "AND (q.question_text IS NOT NULL AND q.question_text != '')";

    // ── Pool 1: Current season ────────────────────────────────────────────────
    $stmt = $db->prepare(
        "SELECT q.id FROM quiz_questions q
         LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE q.is_active = 1 $catWhere $hasText
           AND FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0
           AND COALESCE(m.mastery_level, 0) < 5
         ORDER BY COALESCE(q.seasonal_priority, 5) DESC, COALESCE(m.mastery_level, 0) ASC, RAND()
         LIMIT {$targets['current']}"
    );
    $stmt->execute([$userId, $curM]);
    $selected = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ── Pool 2: Upcoming season ───────────────────────────────────────────────
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

    // ── Pool 3: Spaced rep review ─────────────────────────────────────────────
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

    // ── Pool 4: Weak / fill ───────────────────────────────────────────────────
    $needed = $sessionLength - count($selected);
    if ($needed > 0) {
        $excl = implode(',', array_map('intval', $selected ?: [0]));
        $stmt = $db->prepare(
            "SELECT q.id FROM quiz_questions q
             LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE q.is_active = 1 $catWhere $hasText
               AND q.id NOT IN ($excl)
             ORDER BY COALESCE(m.mastery_level, 0) ASC, RAND()
             LIMIT $needed"
        );
        $stmt->execute([$userId]);
        $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // Shuffle so seasonal questions don't always appear in the same order
    shuffle($selected);
    return array_slice($selected, 0, $sessionLength);
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

/**
 * Returns rank tier info based on total mastered questions (mastery_level >= 4).
 */
function rankTierInfo(int $totalMastered): array
{
    if ($totalMastered >= 100) return ['tier' => 5, 'name' => 'Master Groundskeeper', 'icon' => '🏆', 'next_at' => null,  'next_name' => null];
    if ($totalMastered >= 50)  return ['tier' => 4, 'name' => 'Landscape Expert',     'icon' => '🏅', 'next_at' => 100, 'next_name' => 'Master Groundskeeper'];
    if ($totalMastered >= 25)  return ['tier' => 3, 'name' => 'Plant Specialist',      'icon' => '🌳', 'next_at' => 50,  'next_name' => 'Landscape Expert'];
    if ($totalMastered >= 10)  return ['tier' => 2, 'name' => 'Turf Technician',       'icon' => '🌿', 'next_at' => 25,  'next_name' => 'Plant Specialist'];
    return                            ['tier' => 1, 'name' => 'Lawn Apprentice',        'icon' => '🌱', 'next_at' => 10,  'next_name' => 'Turf Technician'];
}

/**
 * Update the daily study streak for a user (call after completing a session).
 * Returns current_streak, longest_streak, new_best.
 */
function updateDailyStreak(PDO $db, int $userId): array
{
    $today = date('Y-m-d');
    $stmt  = $db->prepare("SELECT * FROM quiz_daily_streaks WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->prepare(
            "INSERT INTO quiz_daily_streaks (user_id, current_streak, longest_streak, last_active_date)
             VALUES (?,1,1,?)"
        )->execute([$userId, $today]);
        return ['current_streak' => 1, 'longest_streak' => 1, 'new_best' => true];
    }

    $lastDate = $row['last_active_date'];
    $current  = (int)$row['current_streak'];
    $longest  = (int)$row['longest_streak'];

    if ($lastDate === $today) {
        // Already counted today
        return ['current_streak' => $current, 'longest_streak' => $longest, 'new_best' => false];
    }

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $current   = ($lastDate === $yesterday) ? $current + 1 : 1;
    $longest   = max($longest, $current);
    $newBest   = $current > (int)$row['longest_streak'];

    $db->prepare(
        "UPDATE quiz_daily_streaks
         SET current_streak=?, longest_streak=?, last_active_date=?, updated_at=NOW()
         WHERE user_id=?"
    )->execute([$current, $longest, $today, $userId]);

    return ['current_streak' => $current, 'longest_streak' => $longest, 'new_best' => $newBest];
}

/**
 * Check all badge conditions for a user and award any newly earned badges.
 * Returns array of newly earned badges (empty if none).
 */
function checkAndAwardBadges(PDO $db, int $userId): array
{
    // Load badge definitions
    $allBadges = $db->query("SELECT * FROM quiz_badges ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$allBadges) return [];

    // Already earned
    $earnedStmt = $db->prepare("SELECT badge_key FROM quiz_user_badges WHERE user_id = ?");
    $earnedStmt->execute([$userId]);
    $earnedKeys = $earnedStmt->fetchAll(PDO::FETCH_COLUMN);

    $newlyEarned = [];
    $insertStmt  = $db->prepare("INSERT IGNORE INTO quiz_user_badges (user_id, badge_key) VALUES (?,?)");

    foreach ($allBadges as $badge) {
        $key     = $badge['badge_key'];
        $reqType = $badge['requirement_type'];
        $reqVal  = (int)$badge['requirement_value'];
        $catName = $badge['requirement_category_name'];

        if (in_array($key, $earnedKeys, true)) continue;

        $earned = false;
        switch ($reqType) {
            case 'category_correct':
                if ($catName) {
                    $cidStmt = $db->prepare(
                        "SELECT id FROM quiz_categories WHERE name LIKE ? AND is_active=1 LIMIT 1"
                    );
                    $cidStmt->execute(['%' . $catName . '%']);
                    $cid = $cidStmt->fetchColumn();
                    if ($cid) {
                        $cnt = $db->prepare(
                            "SELECT COUNT(*) FROM quiz_answers a
                             JOIN quiz_sessions s ON s.id = a.session_id
                             JOIN quiz_questions q ON q.id = a.question_id
                             WHERE s.user_id = ? AND q.category_id = ? AND a.is_correct = 1"
                        );
                        $cnt->execute([$userId, $cid]);
                        $earned = (int)$cnt->fetchColumn() >= $reqVal;
                    }
                }
                break;

            case 'total_mastered':
                $cnt = $db->prepare(
                    "SELECT COUNT(*) FROM quiz_user_mastery WHERE user_id=? AND mastery_level >= 4"
                );
                $cnt->execute([$userId]);
                $earned = (int)$cnt->fetchColumn() >= $reqVal;
                break;

            case 'streak_days':
                $s = $db->prepare(
                    "SELECT current_streak FROM quiz_daily_streaks WHERE user_id=?"
                );
                $s->execute([$userId]);
                $earned = (int)$s->fetchColumn() >= $reqVal;
                break;

            case 'speed_correct':
                $cnt = $db->prepare(
                    "SELECT COUNT(*) FROM quiz_answers a
                     JOIN quiz_sessions s ON s.id = a.session_id
                     WHERE s.user_id = ? AND a.is_correct = 1 AND a.time_taken_seconds <= 5"
                );
                $cnt->execute([$userId]);
                $earned = (int)$cnt->fetchColumn() >= $reqVal;
                break;

            case 'perfect_session':
                $cnt = $db->prepare(
                    "SELECT COUNT(*) FROM quiz_sessions
                     WHERE user_id = ? AND completed_at IS NOT NULL
                       AND questions_count > 0 AND correct_count = questions_count"
                );
                $cnt->execute([$userId]);
                $earned = (int)$cnt->fetchColumn() >= 1;
                break;
        }

        if ($earned) {
            $insertStmt->execute([$userId, $key]);
            $newlyEarned[] = [
                'key'  => $key,
                'name' => $badge['name'],
                'icon' => $badge['icon'],
            ];
        }
    }

    return $newlyEarned;
}

// ── Routing ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: season_context ───────────────────────────────────────────────────
    case 'season_context':
        $ctx = getSeasonContext();
        // Get active campaign for this month
        $camp = $db->prepare(
            "SELECT name, description, tip_text, tip_image_path, focus_tags
             FROM quiz_seasonal_campaigns
             WHERE is_active = 1
               AND (
                 (start_month <= end_month AND ? BETWEEN start_month AND end_month)
                 OR (start_month > end_month AND (? >= start_month OR ? <= end_month))
               )
             ORDER BY sort_order ASC LIMIT 1"
        );
        $m = $ctx['month'];
        $camp->execute([$m, $m, $m]);
        $campaign = $camp->fetch(PDO::FETCH_ASSOC) ?: null;

        // Count questions relevant to current season
        $seasonQ = $db->prepare(
            "SELECT COUNT(*) FROM quiz_questions
             WHERE is_active = 1
               AND FIND_IN_SET(?, IFNULL(relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0"
        );
        $seasonQ->execute([(string)$m]);
        $seasonQCount = (int)$seasonQ->fetchColumn();

        qOk(array_merge($ctx, [
            'campaign'           => $campaign,
            'seasonal_q_count'   => $seasonQCount,
        ]));

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
        $cards  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $qids   = array_column($cards, 'id');
        $imgMap = getImagesForQuestions($db, $qids);
        foreach ($cards as &$card) {
            $card['mastery_level'] = (int)$card['mastery_level'];
            $imgs = $imgMap[$card['id']] ?? [];
            if (!$imgs && ($card['image_path'] ?? '')) {
                $imgs = [['image_path' => $card['image_path'], 'sort_order' => 0, 'caption' => '']];
            }
            $card['images'] = $imgs;
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
        $categoryId    = isset($input['category_id']) && $input['category_id'] !== '' && $input['category_id'] !== null
                           ? (int)$input['category_id'] : null;
        $mode          = $input['mode'] ?? 'seasonal'; // 'seasonal' | 'random' | 'test'
        $sessionLength = (int)($input['session_length'] ?? 10);
        if (!in_array($sessionLength, [3, 5, 10], true)) $sessionLength = 10;

        if ($mode === 'test') {
            // Legacy: priority order — due for review → lowest mastery → random
            if ($categoryId !== null) {
                $stmt = $db->prepare(
                    "SELECT q.id,
                            COALESCE(m.mastery_level, 0) AS mlvl,
                            CASE WHEN m.next_review_at IS NOT NULL AND m.next_review_at <= NOW() THEN 0 ELSE 1 END AS not_due
                     FROM quiz_questions q
                     LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                     WHERE q.category_id = ? AND q.is_active = 1
                       AND (q.question_text IS NOT NULL AND q.question_text != '')
                     ORDER BY not_due ASC, mlvl ASC, RAND()
                     LIMIT {$sessionLength}"
                );
                $stmt->execute([$userId, $categoryId]);
                $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmt = $db->prepare(
                    "SELECT q.id,
                            COALESCE(m.mastery_level, 0) AS mlvl,
                            CASE WHEN m.next_review_at IS NOT NULL AND m.next_review_at <= NOW() THEN 0 ELSE 1 END AS not_due
                     FROM quiz_questions q
                     LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                     WHERE q.is_active = 1
                       AND (q.question_text IS NOT NULL AND q.question_text != '')
                     ORDER BY not_due ASC, mlvl ASC, RAND()
                     LIMIT {$sessionLength}"
                );
                $stmt->execute([$userId]);
                $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } elseif ($mode === 'random') {
            // Pure random mode
            if ($categoryId !== null) {
                $stmt = $db->prepare("SELECT id FROM quiz_questions WHERE category_id=? AND is_active=1 AND (question_text IS NOT NULL AND question_text != '') ORDER BY RAND() LIMIT {$sessionLength}");
                $stmt->execute([$categoryId]);
            } else {
                $stmt = $db->query("SELECT id FROM quiz_questions WHERE is_active=1 AND (question_text IS NOT NULL AND question_text != '') ORDER BY RAND() LIMIT {$sessionLength}");
            }
            $qids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // 🌱 Seasonal mode (default) — 40% current season / 30% upcoming / 20% review / 10% weak
            $qids = selectSeasonalQuestions($db, $userId, $categoryId, $sessionLength);
        }

        if (count($qids) < 1) {
            qErr('No questions available for this category yet. Ask an admin to add some!');
        }

        $questionCount = count($qids);
        $questionIds   = implode(',', $qids);
        $seasonCtx     = getSeasonContext();

        $ins = $db->prepare(
            "INSERT INTO quiz_sessions (user_id, category_id, question_ids, questions_count, month_year)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$userId, $categoryId, $questionIds, $questionCount, $monthYear]);
        $sessionId = (int)$db->lastInsertId();

        qOk([
            'session_id'     => $sessionId,
            'total'          => $questionCount,
            'season_label'   => $seasonCtx['season_label'],
            'season_icon'    => $seasonCtx['season_icon'],
            'season_tagline' => $seasonCtx['season_tagline'],
        ]);

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

        $meta   = masteryMeta();
        $images = getQuestionImages($db, (int)$question['id'], $question['image_path'] ?: null);
        qOk([
            'question_num'     => $qNum,
            'total'            => (int)$session['questions_count'],
            'already_answered' => $alreadyAnswered,
            'question' => [
                'id'               => (int)$question['id'],
                'text'             => $question['question_text'],
                'image_path'       => $images[0]['image_path'] ?? $question['image_path'], // compat
                'images'           => $images,
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

        // Update daily streak and check badges
        $streakResult = updateDailyStreak($db, $userId);
        $newBadges    = checkAndAwardBadges($db, $userId);

        qOk([
            'correct'        => $correctCount,
            'total'          => (int)$session['questions_count'],
            'points'         => $totalPoints,
            'monthly_rank'   => $myRank,
            'monthly_total'  => $myMonthlyTotal,
            'streak'         => $streakResult,
            'new_badges'     => $newBadges,
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

        // Streak
        $streakRow     = $db->prepare("SELECT current_streak, longest_streak FROM quiz_daily_streaks WHERE user_id=?");
        $streakRow->execute([$userId]);
        $streakData    = $streakRow->fetch(PDO::FETCH_ASSOC);
        $currentStreak = (int)($streakData['current_streak'] ?? 0);
        $longestStreak = (int)($streakData['longest_streak'] ?? 0);

        // Badges
        $badgesEarned = $db->prepare("SELECT COUNT(*) FROM quiz_user_badges WHERE user_id=?");
        $badgesEarned->execute([$userId]);
        $badgeCount = (int)$badgesEarned->fetchColumn();

        $totalBadges = (int)$db->query("SELECT COUNT(*) FROM quiz_badges")->fetchColumn();

        // Rank tier
        $tier = rankTierInfo($totalMastered);

        // Category accuracy breakdown
        $catAccStmt = $db->prepare(
            "SELECT c.name, c.colour,
                    COUNT(a.id)    AS total_answers,
                    SUM(a.is_correct) AS correct_answers
             FROM quiz_answers a
             JOIN quiz_sessions s ON s.id = a.session_id
             JOIN quiz_questions q ON q.id = a.question_id
             JOIN quiz_categories c ON c.id = q.category_id
             WHERE s.user_id = ?
             GROUP BY c.id, c.name, c.colour
             ORDER BY c.sort_order"
        );
        $catAccStmt->execute([$userId]);
        $categoryAccuracy = [];
        foreach ($catAccStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total   = (int)$row['total_answers'];
            $correct = (int)$row['correct_answers'];
            $categoryAccuracy[] = [
                'name'    => $row['name'],
                'colour'  => $row['colour'],
                'total'   => $total,
                'correct' => $correct,
                'pct'     => $total > 0 ? round(($correct / $total) * 100) : 0,
            ];
        }

        // Season context + readiness score
        $seasonCtx = getSeasonContext();
        $seasonM   = $seasonCtx['month'];

        // Count seasonal questions user has mastered (level >= 3) vs total in season
        $seasonTotal = (int)$db->prepare(
            "SELECT COUNT(*) FROM quiz_questions WHERE is_active=1
             AND FIND_IN_SET(?, IFNULL(relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0"
        )->execute([(string)$seasonM]) ? $db->query(
            "SELECT COUNT(*) FROM quiz_questions WHERE is_active=1
             AND FIND_IN_SET('{$seasonM}', IFNULL(relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0"
        )->fetchColumn() : 0;

        $seasonMastered = $db->prepare(
            "SELECT COUNT(*) FROM quiz_user_mastery m
             JOIN quiz_questions q ON q.id = m.question_id
             WHERE m.user_id = ? AND m.mastery_level >= 3
               AND FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0
               AND q.is_active = 1"
        );
        $seasonMastered->execute([$userId, (string)$seasonM]);
        $seasonMasteredCount = (int)$seasonMastered->fetchColumn();

        $seasonReadinessPct = $seasonTotal > 0
            ? min(100, round(($seasonMasteredCount / $seasonTotal) * 100))
            : 0;

        qOk([
            'games_played'          => (int)($stats['games_played'] ?? 0),
            'monthly_points'        => (int)($stats['monthly_points'] ?? 0),
            'best_game'             => (int)($stats['best_game'] ?? 0),
            'accuracy_pct'          => $stats['total_questions'] > 0
                                         ? round(($stats['total_correct'] / $stats['total_questions']) * 100)
                                         : 0,
            'monthly_rank'          => $myRank,
            'total_mastered'        => $totalMastered,
            'current_streak'        => $currentStreak,
            'longest_streak'        => $longestStreak,
            'badge_count'           => $badgeCount,
            'total_badges'          => $totalBadges,
            'rank_tier'             => $tier,
            'category_accuracy'     => $categoryAccuracy,
            'season_label'          => $seasonCtx['season_label'],
            'season_icon'           => $seasonCtx['season_icon'],
            'season_tagline'        => $seasonCtx['season_tagline'],
            'season_tip'            => $seasonCtx['tip'],
            'season_focus_tags'     => $seasonCtx['focus_tags'],
            'season_readiness_pct'  => $seasonReadinessPct,
            'season_mastered'       => $seasonMasteredCount,
            'season_total_q'        => (int)$seasonTotal,
        ]);

    // ── Admin: list questions ──────────────────────────────────────────────────
    case 'questions':
        requireAdminQ($isAdmin);
        $catFilter   = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
        $whereClause = $catFilter !== null ? "WHERE q.category_id=?" : "WHERE 1=1";
        $params      = $catFilter !== null ? [$catFilter] : [];

        $stmt = $db->prepare(
            "SELECT q.id, q.question_text, q.learn_notes, q.image_path, q.difficulty,
                    q.question_type, q.learning_level, q.is_active,
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
        $question['images']  = getQuestionImages($db, $qid, $question['image_path'] ?: null);
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
        $validTypes   = ['multiple_choice','photo_id','scenario','sequence','reverse_recall','customer_explanation','quality_judgement'];
        $questionType = in_array($input['question_type'] ?? '', $validTypes) ? $input['question_type'] : 'multiple_choice';
        $learningLevel= max(1, min(6, (int)($input['learning_level'] ?? 1)));
        $options          = $input['options'] ?? [];
        $isActive         = isset($input['is_active']) ? (int)$input['is_active'] : 1;
        $relevantMonths   = trim($input['relevant_months'] ?? '') ?: '1,2,3,4,5,6,7,8,9,10,11,12';
        $seasonalTags     = trim($input['seasonal_tags'] ?? '') ?: null;
        $seasonalPriority = max(1, min(10, (int)($input['seasonal_priority'] ?? 5)));
        $imagesInput      = is_array($input['images'] ?? null) ? $input['images'] : [];

        if ($categoryId <= 0) qErr('Category is required');
        if ($questionText === '') qErr('Question text is required');
        if (count($options) < 2) qErr('At least 2 options required');
        $correctCount = 0;
        foreach ($options as $opt) { if (!empty($opt['is_correct'])) $correctCount++; }
        if ($correctCount !== 1) qErr('Exactly one correct option required');

        if ($id) {
            $db->prepare(
                "UPDATE quiz_questions
                 SET category_id=?,question_text=?,learn_notes=?,image_path=?,difficulty=?,
                     question_type=?,learning_level=?,is_active=?,
                     relevant_months=?,seasonal_tags=?,seasonal_priority=?,updated_at=NOW()
                 WHERE id=?"
            )->execute([$categoryId,$questionText,$learnNotes,$imagePath,$difficulty,
                        $questionType,$learningLevel,$isActive,
                        $relevantMonths,$seasonalTags,$seasonalPriority,$id]);
            $db->prepare("DELETE FROM quiz_options WHERE question_id=?")->execute([$id]);
        } else {
            $db->prepare(
                "INSERT INTO quiz_questions
                 (category_id,question_text,learn_notes,image_path,difficulty,question_type,learning_level,is_active,
                  relevant_months,seasonal_tags,seasonal_priority,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$categoryId,$questionText,$learnNotes,$imagePath,$difficulty,
                        $questionType,$learningLevel,$isActive,
                        $relevantMonths,$seasonalTags,$seasonalPriority,$userId]);
            $id = (int)$db->lastInsertId();
        }

        $optStmt = $db->prepare("INSERT INTO quiz_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)");
        foreach (array_values($options) as $i => $opt) {
            $optStmt->execute([$id, trim($opt['option_text']??''), empty($opt['is_correct'])?0:1, $i]);
        }

        // ── Persist multi-image set ──────────────────────────────────────────
        $db->prepare("DELETE FROM quiz_question_images WHERE question_id=?")->execute([$id]);
        $primaryImagePath = null;
        if ($imagesInput) {
            $imgStmt = $db->prepare(
                "INSERT INTO quiz_question_images (question_id, image_path, sort_order, caption)
                 VALUES (?,?,?,?)"
            );
            foreach (array_values($imagesInput) as $i => $img) {
                $imgPath = trim($img['image_path'] ?? '');
                if ($imgPath === '') continue;
                $caption = trim($img['caption'] ?? '');
                $imgStmt->execute([$id, $imgPath, $i, $caption ?: null]);
                if ($primaryImagePath === null) $primaryImagePath = $imgPath;
            }
        } else {
            // If images array sent as empty, fall back: keep existing image_path if set
            $existing = $db->prepare("SELECT image_path FROM quiz_questions WHERE id=?");
            $existing->execute([$id]);
            $primaryImagePath = $existing->fetchColumn() ?: null;
        }
        // Sync image_path on quiz_questions to first image (for backward compat)
        $db->prepare("UPDATE quiz_questions SET image_path=? WHERE id=?")->execute([$primaryImagePath, $id]);

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

    // ── GET: all badges with earned status for current user ────────────────────
    case 'user_badges':
        $allBadges = $db->query("SELECT * FROM quiz_badges ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $earnedStmt = $db->prepare(
            "SELECT badge_key, earned_at FROM quiz_user_badges WHERE user_id = ?"
        );
        $earnedStmt->execute([$userId]);
        $earnedMap = [];
        foreach ($earnedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $earnedMap[$row['badge_key']] = $row['earned_at'];
        }

        $result = [];
        foreach ($allBadges as $b) {
            $earned   = isset($earnedMap[$b['badge_key']]);
            $result[] = [
                'key'         => $b['badge_key'],
                'name'        => $b['name'],
                'description' => $b['description'],
                'icon'        => $b['icon'],
                'earned'      => $earned,
                'earned_at'   => $earned ? $earnedMap[$b['badge_key']] : null,
            ];
        }
        qOk(['badges' => $result, 'earned_count' => count($earnedMap), 'total' => count($allBadges)]);

    // ── Admin: list seasonal campaigns ─────────────────────────────────────────
    case 'list_campaigns':
        requireAdminQ($isAdmin);
        $rows = $db->query(
            "SELECT * FROM quiz_seasonal_campaigns ORDER BY sort_order ASC, start_month ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        qOk(['campaigns' => $rows]);

    // ── Admin: save (create/update) seasonal campaign ───────────────────────────
    case 'save_campaign':
        requireAdminQ($isAdmin);
        $input       = postInput(); verifyCsrf($input);
        $cid         = isset($input['id']) ? (int)$input['id'] : null;
        $name        = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $seasonLabel = trim($input['season_label'] ?? '');
        $startMonth  = max(1, min(12, (int)($input['start_month'] ?? 1)));
        $endMonth    = max(1, min(12, (int)($input['end_month'] ?? 12)));
        $priBoost    = max(1, min(10, (int)($input['priority_boost'] ?? 3)));
        $focusTags   = trim($input['focus_tags'] ?? '') ?: null;
        $tipText     = trim($input['tip_text'] ?? '') ?: null;
        $sortOrder   = (int)($input['sort_order'] ?? 5);
        $isActive    = isset($input['is_active']) ? (int)$input['is_active'] : 1;
        if ($name === '') qErr('Campaign name required');
        if ($seasonLabel === '') qErr('Season label required');
        if ($cid) {
            $db->prepare(
                "UPDATE quiz_seasonal_campaigns
                 SET name=?,description=?,season_label=?,start_month=?,end_month=?,
                     priority_boost=?,focus_tags=?,tip_text=?,sort_order=?,is_active=?
                 WHERE id=?"
            )->execute([$name,$description,$seasonLabel,$startMonth,$endMonth,
                        $priBoost,$focusTags,$tipText,$sortOrder,$isActive,$cid]);
            qOk(['message' => 'Campaign updated', 'id' => $cid]);
        } else {
            $db->prepare(
                "INSERT INTO quiz_seasonal_campaigns
                 (name,description,season_label,start_month,end_month,priority_boost,focus_tags,tip_text,sort_order,is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([$name,$description,$seasonLabel,$startMonth,$endMonth,
                        $priBoost,$focusTags,$tipText,$sortOrder,$isActive]);
            qOk(['message' => 'Campaign created', 'id' => (int)$db->lastInsertId()]);
        }

    // ── Admin: delete (deactivate) seasonal campaign ────────────────────────────
    case 'delete_campaign':
        requireAdminQ($isAdmin);
        $input = postInput(); verifyCsrf($input);
        $cid   = (int)($input['id'] ?? 0);
        if ($cid <= 0) qErr('Invalid id');
        $db->prepare("DELETE FROM quiz_seasonal_campaigns WHERE id=?")->execute([$cid]);
        qOk(['message' => 'Campaign deleted']);

    // ── Pre-shift gate: check if user needs quiz today ────────────────────────
    case 'preshift_check':
        $enabledRow = $db->query(
            "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_enabled'"
        )->fetch(PDO::FETCH_ASSOC);
        $enabled = ($enabledRow && $enabledRow['setting_value'] == '1');

        $lenRow = $db->query(
            "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_session_length'"
        )->fetch(PDO::FETCH_ASSOC);
        $sessionLen = (int)($lenRow['setting_value'] ?? 3);

        $done = false;
        if ($enabled) {
            $doneStmt = $db->prepare(
                "SELECT id FROM quiz_preshift_log WHERE user_id=? AND log_date=CURDATE()"
            );
            $doneStmt->execute([$userId]);
            $done = (bool)$doneStmt->fetch();
        }
        qOk(['enabled' => $enabled, 'done' => $done, 'session_length' => $sessionLen]);

    // ── Pre-shift gate: mark today's quiz complete ────────────────────────────
    case 'preshift_complete':
        $input     = postInput(); verifyCsrf($input);
        $sessionId = (int)($input['session_id'] ?? 0);
        $correct   = max(0, (int)($input['questions_correct'] ?? 0));
        $asked     = max(1, (int)($input['questions_asked'] ?? 3));
        $pct       = (int)round($correct / $asked * 100);

        // INSERT IGNORE — idempotent, safe to call twice on same day
        $db->prepare(
            "INSERT IGNORE INTO quiz_preshift_log
             (user_id, log_date, session_id, questions_asked, questions_correct, score_pct, completed_at)
             VALUES (?, CURDATE(), ?, ?, ?, ?, NOW())"
        )->execute([$userId, $sessionId ?: null, $asked, $correct, $pct]);

        $alreadyDone = ($db->lastInsertId() == 0);
        qOk(['already_done' => $alreadyDone]);

    // ── Admin: list plant/weed library entries ────────────────────────────────
    case 'library_list':
        requireAdminQ($isAdmin);
        $type   = $_GET['type']   ?? '';
        $search = trim($_GET['q'] ?? '');
        $sql    = "SELECT * FROM quiz_plant_library WHERE is_active = 1";
        $params = [];
        if ($type && in_array($type, ['plant','weed','grass','shrub','tree','fungus'], true)) {
            $sql    .= " AND type = ?";
            $params[] = $type;
        }
        if ($search !== '') {
            $like     = '%' . $search . '%';
            $sql     .= " AND (common_name LIKE ? OR latin_name LIKE ? OR tags LIKE ? OR description LIKE ?)";
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }
        $sql .= " ORDER BY type ASC, common_name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        qOk(['entries' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // ── Admin: save plant/weed library entry ──────────────────────────────────
    case 'save_library_entry':
        requireAdminQ($isAdmin);
        $input = postInput(); verifyCsrf($input);
        $id    = (int)($input['id'] ?? 0);
        $name  = trim($input['common_name'] ?? '');
        if ($name === '') qErr('Common name is required');
        $validTypes = ['plant','weed','grass','shrub','tree','fungus'];
        $type  = in_array($input['type'] ?? '', $validTypes, true) ? $input['type'] : 'plant';
        $data  = [
            trim($input['common_name'] ?? ''),
            trim($input['latin_name'] ?? '') ?: null,
            $type,
            trim($input['description'] ?? '') ?: null,
            trim($input['identification_notes'] ?? '') ?: null,
            trim($input['image_path'] ?? '') ?: null,
            trim($input['tags'] ?? '') ?: null,
        ];
        if ($id > 0) {
            $db->prepare(
                "UPDATE quiz_plant_library
                 SET common_name=?, latin_name=?, type=?, description=?,
                     identification_notes=?, image_path=?, tags=?, updated_at=NOW()
                 WHERE id=?"
            )->execute(array_merge($data, [$id]));
            qOk(['message' => 'Entry updated', 'id' => $id]);
        } else {
            $db->prepare(
                "INSERT INTO quiz_plant_library
                    (common_name, latin_name, type, description, identification_notes, image_path, tags)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute($data);
            qOk(['message' => 'Entry created', 'id' => (int)$db->lastInsertId()]);
        }

    // ── Admin: delete plant/weed library entry ────────────────────────────────
    case 'delete_library_entry':
        requireAdminQ($isAdmin);
        $input = postInput(); verifyCsrf($input);
        $id    = (int)($input['id'] ?? 0);
        if ($id <= 0) qErr('Invalid id');
        $db->prepare("DELETE FROM quiz_plant_library WHERE id=?")->execute([$id]);
        qOk(['message' => 'Entry deleted']);

    // ── Admin: upload library image ───────────────────────────────────────────
    case 'upload_library_image':
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
        $filename = 'lib_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir  = PUBLIC_ROOT . '/uploads/quiz';
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) qErr('Failed to save file');
        qOk(['image_path' => '/uploads/quiz/' . $filename]);

    default:
        qErr('Unknown action', 404);
}
