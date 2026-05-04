<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/QuizHelpers.php
 *
 * Shared, stateless helper functions for the Knowledge Quiz system.
 * Used by both the JWT mobile API (app/Modules/Quiz/Api/) and the
 * web CSRF API (public/crm/api/quiz.php).
 *
 * No session, no auth, no globals — all dependencies passed as parameters.
 */

// ── Season context ────────────────────────────────────────────────────────────

/**
 * Return current season context based on month (Vancouver-specific).
 */
function getSeasonContext(int $month = 0): array
{
    if ($month === 0) $month = (int)date('n');

    $u1 = ($month % 12) + 1;
    $u2 = (($month + 1) % 12) + 1;

    $seasons = [
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
        'focus_tags'      => $matched[5],
        'tip'             => $matched[6],
    ];
}

// ── Question image helpers ────────────────────────────────────────────────────

function getQuestionImages(PDO $db, int $questionId, ?string $fallbackImagePath = null): array
{
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

function getImagesForQuestions(PDO $db, array $questionIds): array
{
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

// ── Seasonal question selection ───────────────────────────────────────────────

/**
 * Select questions using seasonal blending (40/30/20/10):
 *   40% current season, 30% upcoming season,
 *   20% spaced-rep review, 10% weak/unlearned fill.
 */
function selectSeasonalQuestions(PDO $db, int $userId, ?int $categoryId, int $sessionLength): array
{
    $ctx  = getSeasonContext();
    $curM = (string)$ctx['month'];
    $upM1 = (string)$ctx['upcoming_months'][0];
    $upM2 = (string)$ctx['upcoming_months'][1];

    $catWhere = $categoryId !== null ? 'AND q.category_id = ' . (int)$categoryId : '';

    $targets = [
        'current'  => max(1, (int)round($sessionLength * 0.40)),
        'upcoming' => max(1, (int)round($sessionLength * 0.30)),
        'review'   => max(1, (int)round($sessionLength * 0.20)),
    ];
    $targets['weak'] = max(0, $sessionLength - $targets['current'] - $targets['upcoming'] - $targets['review']);

    $selected = [];

    // Pool 1: current season, not fully mastered
    $stmt = $db->prepare(
        "SELECT q.id FROM quiz_questions q
         LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE q.is_active = 1 $catWhere
           AND FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0
           AND COALESCE(m.mastery_level, 0) < 5
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
         WHERE q.is_active = 1 $catWhere
           AND q.id NOT IN ($excl)
           AND (FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0
                OR FIND_IN_SET(?, IFNULL(q.relevant_months,'1,2,3,4,5,6,7,8,9,10,11,12')) > 0)
           AND COALESCE(m.mastery_level, 0) < 5
         ORDER BY COALESCE(q.seasonal_priority, 5) DESC, RAND()
         LIMIT {$targets['upcoming']}"
    );
    $stmt->execute([$userId, $upM1, $upM2]);
    $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Pool 3: spaced-rep review
    $excl = implode(',', array_map('intval', $selected ?: [0]));
    $stmt = $db->prepare(
        "SELECT q.id FROM quiz_questions q
         JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE q.is_active = 1 $catWhere
           AND q.id NOT IN ($excl)
           AND m.next_review_at <= NOW()
           AND m.mastery_level >= 1
         ORDER BY m.next_review_at ASC
         LIMIT {$targets['review']}"
    );
    $stmt->execute([$userId]);
    $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Pool 4: weak/fill
    $needed = $sessionLength - count($selected);
    if ($needed > 0) {
        $excl = implode(',', array_map('intval', $selected ?: [0]));
        $stmt = $db->prepare(
            "SELECT q.id FROM quiz_questions q
             LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
             WHERE q.is_active = 1 $catWhere
               AND q.id NOT IN ($excl)
             ORDER BY COALESCE(m.mastery_level, 0) ASC, RAND()
             LIMIT $needed"
        );
        $stmt->execute([$userId]);
        $selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    shuffle($selected);
    return array_slice($selected, 0, $sessionLength);
}

// ── Mastery ───────────────────────────────────────────────────────────────────

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
 * Compute new mastery after an answer.
 * Speed bonus: correct in ≤10s → advance 2 levels; else 1.
 * Wrong: drop 1 (floor = 1 if already seen, 0 if unseen).
 */
function computeNewMastery(int $current, bool $correct, int $timeTaken, int $streak): array
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
    $meta       = masteryMeta();
    $hours      = $meta[$newLevel]['review_hours'] ?? null;
    $nextReview = $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;
    return ['level' => $newLevel, 'streak' => $newStreak, 'next_review' => $nextReview];
}

/**
 * Upsert quiz_user_mastery for one answer. Returns mastery delta info.
 */
function updateMastery(PDO $db, int $userId, int $questionId, bool $correct, int $timeTaken): array
{
    $stmt = $db->prepare("SELECT * FROM quiz_user_mastery WHERE user_id = ? AND question_id = ?");
    $stmt->execute([$userId, $questionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $currentLevel  = $existing ? (int)$existing['mastery_level']  : 0;
    $currentStreak = $existing ? (int)$existing['correct_streak'] : 0;
    $totalAttempts = $existing ? (int)$existing['total_attempts'] : 0;
    $totalCorrect  = $existing ? (int)$existing['total_correct']  : 0;

    $computed   = computeNewMastery($currentLevel, $correct, $timeTaken, $currentStreak);
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
        'old_level'  => $currentLevel,
        'new_level'  => $newLevel,
        'delta'      => $newLevel - $currentLevel,
        'level_name' => $meta[$newLevel]['name'],
        'streak'     => $newStreak,
    ];
}

// ── Rank tier ─────────────────────────────────────────────────────────────────

function rankTierInfo(int $totalMastered): array
{
    if ($totalMastered >= 100) return ['tier' => 5, 'name' => 'Master Groundskeeper', 'icon' => '🏆', 'next_at' => null,  'next_name' => null];
    if ($totalMastered >= 50)  return ['tier' => 4, 'name' => 'Landscape Expert',     'icon' => '🏅', 'next_at' => 100, 'next_name' => 'Master Groundskeeper'];
    if ($totalMastered >= 25)  return ['tier' => 3, 'name' => 'Plant Specialist',      'icon' => '🌳', 'next_at' => 50,  'next_name' => 'Landscape Expert'];
    if ($totalMastered >= 10)  return ['tier' => 2, 'name' => 'Turf Technician',       'icon' => '🌿', 'next_at' => 25,  'next_name' => 'Plant Specialist'];
    return                            ['tier' => 1, 'name' => 'Lawn Apprentice',        'icon' => '🌱', 'next_at' => 10,  'next_name' => 'Turf Technician'];
}

// ── Daily streak ──────────────────────────────────────────────────────────────

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

// ── Badge checking ────────────────────────────────────────────────────────────

function checkAndAwardBadges(PDO $db, int $userId): array
{
    $allBadges = $db->query("SELECT * FROM quiz_badges ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$allBadges) return [];

    $earnedStmt = $db->prepare("SELECT badge_key FROM quiz_user_badges WHERE user_id = ?");
    $earnedStmt->execute([$userId]);
    $earnedKeys = $earnedStmt->fetchAll(PDO::FETCH_COLUMN);

    $newlyEarned = [];
    $insertStmt  = $db->prepare("INSERT IGNORE INTO quiz_user_badges (user_id, badge_key) VALUES (?,?)");

    foreach ($allBadges as $badge) {
        $key     = $badge['badge_key'];
        $reqType = $badge['requirement_type'] ?? '';
        $reqVal  = (int)($badge['requirement_value'] ?? 0);
        $catName = $badge['requirement_category_name'] ?? null;

        if (in_array($key, $earnedKeys, true)) continue;

        $earned = false;
        switch ($reqType) {
            case 'category_correct':
                if ($catName) {
                    $cidStmt = $db->prepare("SELECT id FROM quiz_categories WHERE name LIKE ? AND is_active=1 LIMIT 1");
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
                $cnt = $db->prepare("SELECT COUNT(*) FROM quiz_user_mastery WHERE user_id=? AND mastery_level >= 4");
                $cnt->execute([$userId]);
                $earned = (int)$cnt->fetchColumn() >= $reqVal;
                break;
            case 'streak_days':
                $s = $db->prepare("SELECT current_streak FROM quiz_daily_streaks WHERE user_id=?");
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
            $newlyEarned[] = ['key' => $key, 'name' => $badge['name'], 'icon' => $badge['icon']];
        }
    }

    return $newlyEarned;
}
