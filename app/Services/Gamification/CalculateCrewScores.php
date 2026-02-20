<?php
/**
 * /app/Services/Gamification/CalculateCrewScores.php
 *
 * Calculates crew team weekly scores from expense and receipt data.
 *
 * Score weights:
 *   OCR Accuracy     25%  — avg match_confidence of receipts with OCR
 *   Submission Speed 20%  — % of expenses submitted within 4h of expense_date
 *   Photos Per Job   20%  — % of expenses that have a receipt photo
 *   Anomaly-Free     20%  — % of expenses with anomaly_score <= 10
 *   Budget Adherence 15%  — % of job-linked expenses where job is on-budget
 *
 * Usage (cron or one-shot):
 *   require_once APP_ROOT . '/Services/Gamification/CalculateCrewScores.php';
 *   $results = calculateAllTeamScores($weekStart);   // e.g. '2026-02-17' (Monday)
 *   $results = calculateTeamScore($teamId, $weekStart);
 *
 * Can also be called via web: /crm/api/gamification.php?action=recalculate&week=2026-02-17
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

// Score weight constants
const WEIGHT_OCR_ACCURACY  = 0.25;
const WEIGHT_SPEED         = 0.20;
const WEIGHT_PHOTOS        = 0.20;
const WEIGHT_ANOMALY_FREE  = 0.20;
const WEIGHT_BUDGET        = 0.15;

// Speed target: submissions within this many hours score full points
const SPEED_TARGET_HOURS   = 4;
// Anomaly threshold: score <= this is considered "clean"
const ANOMALY_CLEAN_THRESHOLD = 10;


/**
 * Calculate and persist scores for ALL active teams for a given week.
 *
 * @param string   $weekStart  ISO date of Monday e.g. '2026-02-17'
 * @param PDO|null $db
 * @return array   Array of result arrays, one per team
 */
function calculateAllTeamScores(string $weekStart, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    $stmt = $db->prepare("SELECT id FROM crew_teams WHERE is_active = 1");
    $stmt->execute();
    $teamIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $results = [];
    foreach ($teamIds as $teamId) {
        $results[] = calculateTeamScore((int)$teamId, $weekStart, $db);
    }
    return $results;
}


/**
 * Calculate and persist score for a single team for a given week.
 *
 * @param int      $teamId
 * @param string   $weekStart  Monday of the week (YYYY-MM-DD)
 * @param PDO|null $db
 * @return array   Score breakdown
 */
function calculateTeamScore(int $teamId, string $weekStart, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    // Week date range (Mon–Sun)
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

    // Get all team member IDs
    $stmt = $db->prepare("SELECT user_id FROM crew_team_members WHERE team_id = ?");
    $stmt->execute([$teamId]);
    $memberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($memberIds)) {
        return _emptyScore($teamId, $weekStart);
    }

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

    // ── Fetch all expenses for this team this week ────────────────────────────
    $params = array_merge($memberIds, [$weekStart, $weekEnd]);
    $stmt = $db->prepare("
        SELECT e.id,
               e.created_by,
               e.expense_date,
               e.created_at,
               e.receipt_media_id,
               e.match_confidence,
               e.anomaly_score,
               e.job_id,
               e.total,
               e.accounting_category
        FROM expenses e
        WHERE e.created_by IN ($placeholders)
          AND e.expense_date BETWEEN ? AND ?
          AND e.status != 'rejected'
        ORDER BY e.created_at
    ");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalExpenses = count($expenses);

    if ($totalExpenses === 0) {
        return _persistScore($teamId, $weekStart, _emptyScore($teamId, $weekStart), $db);
    }

    // ── Component 1: OCR Accuracy (25%) ──────────────────────────────────────
    // Only count expenses that went through OCR (match_confidence > 0)
    $ocrExpenses = array_filter($expenses, fn($e) => (int)$e['match_confidence'] > 0);
    $scoreOcr = 0.0;
    $avgOcrConf = null;
    if (count($ocrExpenses) > 0) {
        $avgOcrConf = array_sum(array_column($ocrExpenses, 'match_confidence')) / count($ocrExpenses);
        $scoreOcr = min(100.0, $avgOcrConf); // confidence is already 0-100
    } else {
        // No OCR receipts this week — give neutral score (50) rather than penalising
        $scoreOcr = 50.0;
    }

    // ── Component 2: Submission Speed (20%) ──────────────────────────────────
    // Score = % of expenses submitted within SPEED_TARGET_HOURS of expense_date
    // We compare created_at time vs expense_date + 00:00:00
    // (expense_date is a date, so "same day early" scores full points within window)
    $fastCount = 0;
    foreach ($expenses as $e) {
        $expenseDay  = strtotime($e['expense_date'] . ' 00:00:00');
        $submittedAt = strtotime($e['created_at']);
        $hoursLag    = ($submittedAt - $expenseDay) / 3600;
        if ($hoursLag >= 0 && $hoursLag <= SPEED_TARGET_HOURS) {
            $fastCount++;
        }
    }
    $scoreSpeed    = ($totalExpenses > 0) ? round(($fastCount / $totalExpenses) * 100, 2) : 0.0;
    $avgSubmitHrs  = null;
    if ($totalExpenses > 0) {
        $totalHrs = 0.0;
        foreach ($expenses as $e) {
            $lag = (strtotime($e['created_at']) - strtotime($e['expense_date'] . ' 00:00:00')) / 3600;
            $totalHrs += max(0, $lag);
        }
        $avgSubmitHrs = round($totalHrs / $totalExpenses, 2);
    }

    // ── Component 3: Photos Per Job (20%) ─────────────────────────────────────
    $withPhoto  = count(array_filter($expenses, fn($e) => !empty($e['receipt_media_id'])));
    $scorePhoto = ($totalExpenses > 0) ? round(($withPhoto / $totalExpenses) * 100, 2) : 0.0;

    // ── Component 4: Anomaly-Free (20%) ──────────────────────────────────────
    $cleanCount     = count(array_filter($expenses, fn($e) => ((int)($e['anomaly_score'] ?? 0)) <= ANOMALY_CLEAN_THRESHOLD));
    $anomalousCount = $totalExpenses - $cleanCount;
    $scoreAnomaly   = ($totalExpenses > 0) ? round(($cleanCount / $totalExpenses) * 100, 2) : 100.0;

    // ── Component 5: Budget Adherence (15%) ──────────────────────────────────
    // Only evaluate expenses linked to jobs. For each unique job_id, check if
    // total materials spend <= quoted materials. On-budget = score contribution.
    $jobIds = array_values(array_unique(array_filter(array_column($expenses, 'job_id'))));
    $scoreBudget  = 50.0; // neutral default when no jobs linked
    $onBudgetJobs = 0;
    if (!empty($jobIds)) {
        require_once APP_ROOT . '/Services/Receipts/MarginTracker.php';
        $totalJobs = count($jobIds);
        foreach ($jobIds as $jobId) {
            $margin = getJobMargin((int)$jobId, $db);
            if ($margin && $margin['status'] === 'on_track') {
                $onBudgetJobs++;
            }
        }
        $scoreBudget = ($totalJobs > 0) ? round(($onBudgetJobs / $totalJobs) * 100, 2) : 50.0;
    }

    // ── Composite weighted total ──────────────────────────────────────────────
    $totalScore = round(
        ($scoreOcr      * WEIGHT_OCR_ACCURACY) +
        ($scoreSpeed    * WEIGHT_SPEED)         +
        ($scorePhoto    * WEIGHT_PHOTOS)        +
        ($scoreAnomaly  * WEIGHT_ANOMALY_FREE)  +
        ($scoreBudget   * WEIGHT_BUDGET),
        2
    );

    $scoreData = [
        'team_id'              => $teamId,
        'week_start'           => $weekStart,
        'score_ocr_accuracy'   => $scoreOcr,
        'score_speed'          => $scoreSpeed,
        'score_photos'         => $scorePhoto,
        'score_anomaly_free'   => $scoreAnomaly,
        'score_budget'         => $scoreBudget,
        'total_score'          => $totalScore,
        'expenses_submitted'   => $totalExpenses,
        'expenses_with_photo'  => $withPhoto,
        'expenses_anomalous'   => $anomalousCount,
        'expenses_on_budget'   => $onBudgetJobs,
        'avg_ocr_confidence'   => $avgOcrConf !== null ? round($avgOcrConf, 2) : null,
        'avg_submission_hours' => $avgSubmitHrs,
    ];

    return _persistScore($teamId, $weekStart, $scoreData, $db);
}


/**
 * Persist score to DB (upsert) and check + award badges.
 */
function _persistScore(int $teamId, string $weekStart, array $scoreData, PDO $db): array
{
    $db->prepare("
        INSERT INTO crew_weekly_scores
            (team_id, week_start, score_ocr_accuracy, score_speed, score_photos,
             score_anomaly_free, score_budget, total_score,
             expenses_submitted, expenses_with_photo, expenses_anomalous,
             expenses_on_budget, avg_ocr_confidence, avg_submission_hours)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            score_ocr_accuracy   = VALUES(score_ocr_accuracy),
            score_speed          = VALUES(score_speed),
            score_photos         = VALUES(score_photos),
            score_anomaly_free   = VALUES(score_anomaly_free),
            score_budget         = VALUES(score_budget),
            total_score          = VALUES(total_score),
            expenses_submitted   = VALUES(expenses_submitted),
            expenses_with_photo  = VALUES(expenses_with_photo),
            expenses_anomalous   = VALUES(expenses_anomalous),
            expenses_on_budget   = VALUES(expenses_on_budget),
            avg_ocr_confidence   = VALUES(avg_ocr_confidence),
            avg_submission_hours = VALUES(avg_submission_hours),
            calculated_at        = CURRENT_TIMESTAMP
    ")->execute([
        $teamId, $weekStart,
        $scoreData['score_ocr_accuracy'],
        $scoreData['score_speed'],
        $scoreData['score_photos'],
        $scoreData['score_anomaly_free'],
        $scoreData['score_budget'],
        $scoreData['total_score'],
        $scoreData['expenses_submitted'],
        $scoreData['expenses_with_photo'],
        $scoreData['expenses_anomalous'],
        $scoreData['expenses_on_budget'],
        $scoreData['avg_ocr_confidence'],
        $scoreData['avg_submission_hours'],
    ]);

    // Check and award badges for this team/week
    _checkBadgeAwards($teamId, $weekStart, $scoreData, $db);

    return $scoreData;
}


/**
 * Check badge conditions and award where earned (no duplicate awards).
 */
function _checkBadgeAwards(int $teamId, string $weekStart, array $scoreData, PDO $db): void
{
    $awards = [];

    // perfect_week: total_score = 100
    if ($scoreData['total_score'] >= 100.0) {
        $awards[] = ['perfect_week', $weekStart, 'Score hit 100/100'];
    }

    // speed_demon: avg submission < 1h
    if ($scoreData['avg_submission_hours'] !== null && $scoreData['avg_submission_hours'] < 1.0 && $scoreData['expenses_submitted'] >= 3) {
        $awards[] = ['speed_demon', $weekStart, 'Avg submission ' . $scoreData['avg_submission_hours'] . 'h'];
    }

    // clean_sheet: zero anomalous
    if ($scoreData['expenses_anomalous'] === 0 && $scoreData['expenses_submitted'] >= 3) {
        $awards[] = ['clean_sheet', $weekStart, 'Zero anomalies'];
    }

    // photo_pro: 100% photo rate (at least 3 expenses)
    if ($scoreData['expenses_submitted'] >= 3 && $scoreData['expenses_with_photo'] === $scoreData['expenses_submitted']) {
        $awards[] = ['photo_pro', $weekStart, '100% receipt photos'];
    }

    // budget_hero: score_budget = 100 (at least 1 job-linked expense)
    if ($scoreData['score_budget'] >= 100.0 && $scoreData['expenses_on_budget'] > 0) {
        $awards[] = ['budget_hero', $weekStart, 'All jobs on budget'];
    }

    // consecutive checks (first_team, consistent) — look back at history
    _checkConsecutiveBadges($teamId, $weekStart, $db, $awards);

    foreach ($awards as [$slug, $week, $note]) {
        // Look up badge id
        $bStmt = $db->prepare("SELECT id FROM crew_badges WHERE slug = ? AND is_active = 1");
        $bStmt->execute([$slug]);
        $badgeId = $bStmt->fetchColumn();
        if (!$badgeId) continue;

        // No duplicate per team+badge+week
        $existStmt = $db->prepare("SELECT id FROM crew_badge_awards WHERE badge_id = ? AND team_id = ? AND week_start = ?");
        $existStmt->execute([$badgeId, $teamId, $week]);
        if ($existStmt->fetchColumn()) continue;

        $db->prepare("INSERT INTO crew_badge_awards (badge_id, team_id, week_start, note) VALUES (?, ?, ?, ?)")
           ->execute([$badgeId, $teamId, $week, $note]);
    }
}


/**
 * Check multi-week streak badges.
 */
function _checkConsecutiveBadges(int $teamId, string $weekStart, PDO $db, array &$awards): void
{
    // consistent: 80+ for 8 straight weeks
    $stmt = $db->prepare("
        SELECT week_start, total_score
        FROM crew_weekly_scores
        WHERE team_id = ?
          AND week_start <= ?
        ORDER BY week_start DESC
        LIMIT 8
    ");
    $stmt->execute([$teamId, $weekStart]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($recent) === 8) {
        $allHigh = array_reduce($recent, fn($carry, $row) => $carry && $row['total_score'] >= 80, true);
        if ($allHigh) {
            $awards[] = ['consistent', $weekStart, '8 consecutive weeks at 80+'];
        }
    }

    // first_team: rank #1 for 4 consecutive weeks — check by comparing to other teams
    // (lightweight: only check if this team scored above 80 this week)
    // Full implementation in the weekly cron; skip here to avoid complex subqueries
}


/**
 * Return a zero-score array for a team with no activity.
 */
function _emptyScore(int $teamId, string $weekStart): array
{
    return [
        'team_id'              => $teamId,
        'week_start'           => $weekStart,
        'score_ocr_accuracy'   => 0.0,
        'score_speed'          => 0.0,
        'score_photos'         => 0.0,
        'score_anomaly_free'   => 0.0,
        'score_budget'         => 0.0,
        'total_score'          => 0.0,
        'expenses_submitted'   => 0,
        'expenses_with_photo'  => 0,
        'expenses_anomalous'   => 0,
        'expenses_on_budget'   => 0,
        'avg_ocr_confidence'   => null,
        'avg_submission_hours' => null,
    ];
}


/**
 * Get current ISO week's Monday date.
 */
function getCurrentWeekStart(): string
{
    $dayOfWeek = (int)date('N'); // 1=Mon, 7=Sun
    return date('Y-m-d', strtotime('-' . ($dayOfWeek - 1) . ' days'));
}


/**
 * Get leaderboard for a given week — all teams ranked by total_score.
 *
 * @param string   $weekStart
 * @param PDO|null $db
 * @return array
 */
function getLeaderboard(string $weekStart, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    $stmt = $db->prepare("
        SELECT
            ct.id          AS team_id,
            ct.name        AS team_name,
            ct.colour      AS team_colour,
            ct.emoji       AS team_emoji,
            cws.total_score,
            cws.score_ocr_accuracy,
            cws.score_speed,
            cws.score_photos,
            cws.score_anomaly_free,
            cws.score_budget,
            cws.expenses_submitted,
            cws.expenses_with_photo,
            cws.expenses_anomalous,
            cws.avg_submission_hours,
            cws.calculated_at
        FROM crew_teams ct
        LEFT JOIN crew_weekly_scores cws ON cws.team_id = ct.id AND cws.week_start = ?
        WHERE ct.is_active = 1
        ORDER BY COALESCE(cws.total_score, 0) DESC, ct.name
    ");
    $stmt->execute([$weekStart]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add rank and badge count per team
    $rank = 1;
    foreach ($rows as &$row) {
        $row['rank'] = $rank++;

        // Count total badges earned all-time
        $bStmt = $db->prepare("SELECT COUNT(*) FROM crew_badge_awards WHERE team_id = ?");
        $bStmt->execute([$row['team_id']]);
        $row['badge_count'] = (int)$bStmt->fetchColumn();

        // Most recent badges (last 3)
        $rStmt = $db->prepare("
            SELECT cb.name, cb.icon, cb.colour, cb.tier, cba.awarded_at
            FROM crew_badge_awards cba
            JOIN crew_badges cb ON cb.id = cba.badge_id
            WHERE cba.team_id = ?
            ORDER BY cba.awarded_at DESC
            LIMIT 3
        ");
        $rStmt->execute([$row['team_id']]);
        $row['recent_badges'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Member names
        $mStmt = $db->prepare("
            SELECT u.full_name
            FROM crew_team_members ctm
            JOIN users u ON u.id = ctm.user_id
            WHERE ctm.team_id = ?
            ORDER BY u.full_name
        ");
        $mStmt->execute([$row['team_id']]);
        $row['members'] = $mStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($row);

    return $rows;
}


/**
 * Get last N weeks of score history for a team (for sparkline).
 *
 * @param int      $teamId
 * @param int      $weeks   How many weeks back to fetch
 * @param PDO|null $db
 * @return array   Ordered oldest-first
 */
function getTeamScoreHistory(int $teamId, int $weeks = 8, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    $stmt = $db->prepare("
        SELECT week_start, total_score, score_ocr_accuracy, score_speed,
               score_photos, score_anomaly_free, score_budget, expenses_submitted
        FROM crew_weekly_scores
        WHERE team_id = ?
        ORDER BY week_start DESC
        LIMIT ?
    ");
    $stmt->execute([$teamId, $weeks]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_reverse($rows); // oldest first for charting
}
