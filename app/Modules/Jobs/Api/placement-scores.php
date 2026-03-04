<?php
/**
 * Placement Scores API
 * Recomputes placement-fit scores for a set of unscheduled visits for the current week.
 * Called after placing a tray visit so remaining tray-card badges stay current without
 * a full page reload.
 *
 * POST JSON:
 *   week_start  string  YYYY-MM-DD — Monday of the schedule week being viewed
 *   visit_ids   int[]   — IDs of the remaining (still-unscheduled) job_visits to score
 *
 * Response:
 *   {success:true, scores:{visitId:{dateStr:{score,cap,cycle,weather,density,label,cycle_note}}},
 *                  best_days:{visitId:{date,score,label}}}
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';

    requireLogin();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['week_start']) || empty($input['visit_ids'])) {
        throw new Exception('Missing required fields: week_start, visit_ids');
    }

    $weekStart = trim($input['week_start']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
        throw new Exception('Invalid week_start format — expected YYYY-MM-DD');
    }

    $visitIds = array_values(array_filter(array_map('intval', (array)$input['visit_ids'])));
    if (empty($visitIds)) {
        echo json_encode(['success' => true, 'scores' => [], 'best_days' => []]);
        exit;
    }

    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $db = getDB();

    // ── Build day capacity map from current calendar stops ─────────────────────
    $calendarData = getCalendarStops($weekStart, $weekEnd);

    $dayCapUsed  = []; // dateStr => total estimated minutes already scheduled
    $dayNStops   = []; // dateStr => number of stops (for density bonus)
    $curDate = new DateTime($weekStart);
    for ($i = 0; $i < 7; $i++) {
        $ds      = $curDate->format('Y-m-d');
        $nStops  = count($calendarData[$ds] ?? []);
        $used    = 0;
        foreach (($calendarData[$ds] ?? []) as $stop) {
            foreach (($stop['visits'] ?? []) as $v) {
                $used += (int)($v['estimated_duration'] ?? 0);
            }
        }
        // Rough drive-time estimate consistent with schedule.php scoring
        $used += $nStops > 0 ? (max(0, $nStops - 1) * 8 + 10) : 0;
        $dayCapUsed[$ds]  = $used;
        $dayNStops[$ds]   = $nStops;
        $curDate->modify('+1 day');
    }

    // ── Fetch the requested unscheduled visits ────────────────────────────────
    $placeholders = implode(',', array_fill(0, count($visitIds), '?'));
    $stmt = $db->prepare("
        SELECT
            jv.id                             AS visit_id,
            jv.plan_id,
            jp.service_type,
            jp.estimated_duration_minutes     AS estimated_duration,
            jp.recurrence_pattern,
            jp.recurrence_interval,
            jp.recurrence_interval_unit,
            jp.recurrence_day_of_week,
            jp.start_date                     AS plan_start_date,
            (SELECT MAX(jv2.completed_at)
               FROM job_visits jv2
              WHERE jv2.plan_id = jp.id
                AND jv2.status = 'completed') AS last_completed_at
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.id IN ($placeholders)
          AND jp.status = 'active'
          AND jv.stop_id IS NULL
    ");
    $stmt->execute($visitIds);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Score each visit ──────────────────────────────────────────────────────
    $placementScores = [];
    $visitBestDays   = [];

    foreach ($visits as $uv) {
        $visitId  = (int)$uv['visit_id'];
        $duration = max(1, (int)($uv['estimated_duration'] ?? 45));
        $pattern  = $uv['recurrence_pattern'] ?? 'weekly';
        $interval = max(1, (int)($uv['recurrence_interval'] ?? 1));

        // Parse preferred day-of-week (may be comma-separated for multi-day plans)
        $prefDows = null;
        if ($uv['recurrence_day_of_week'] !== null && $uv['recurrence_day_of_week'] !== '') {
            $prefDows = array_values(array_filter(
                array_map('intval', explode(',', (string)$uv['recurrence_day_of_week'])),
                function ($d) { return $d >= 0 && $d <= 6; }
            ));
            if (empty($prefDows)) $prefDows = null;
        }

        $lastDone   = !empty($uv['last_completed_at']) ? strtotime($uv['last_completed_at']) : null;
        $planStart  = !empty($uv['plan_start_date'])   ? strtotime($uv['plan_start_date'])  : null;
        $isBiweekly = ($pattern === 'biweekly')
                   || ($pattern === 'weekly' && $interval >= 2)
                   || ($pattern === 'custom'
                       && strtolower($uv['recurrence_interval_unit'] ?? 'weeks') === 'weeks'
                       && $interval >= 2);

        $scores  = [];
        $curDate2 = new DateTime($weekStart);
        for ($i = 0; $i < 7; $i++) {
            $ds      = $curDate2->format('Y-m-d');
            $dayTs   = strtotime($ds);
            $dayDow  = (int)date('w', $dayTs); // 0=Sun … 6=Sat
            $usedMin = $dayCapUsed[$ds] ?? 0;
            $nStops  = $dayNStops[$ds]  ?? 0;

            // ── Capacity Fit (0–40) ────────────────────────────────────────────
            $afterMin = $usedMin + $duration;
            if ($afterMin <= 480) {
                $headroom = max(0, 480 - $usedMin);
                $capScore = (int)round(min(40, ($headroom / 480) * 40));
            } elseif ($afterMin <= 540) {
                $capScore = 10;
            } else {
                $capScore = 0;
            }

            // ── Cycle Match (0–40) ────────────────────────────────────────────
            $cycleScore = 20;
            $cycleNote  = '';
            if ($isBiweekly) {
                $intervalDays = $interval * 7;
                $refTs        = $lastDone ?? $planStart ?? $dayTs;
                $daysDiff     = abs((int)(($dayTs - $refTs) / 86400));
                $cycleMod     = $daysDiff % $intervalDays;
                if ($cycleMod <= 2 || $cycleMod >= ($intervalDays - 2)) {
                    $cycleScore = 40; $cycleNote = 'On cycle ✓';
                } elseif ($cycleMod <= 5 || $cycleMod >= ($intervalDays - 5)) {
                    $cycleScore = 25; $cycleNote = 'Near cycle';
                } else {
                    $cycleScore = 0;  $cycleNote = 'Off cycle';
                }
            } elseif ($pattern === 'weekly' || ($pattern === 'custom' && $interval === 1)) {
                if ($prefDows !== null) {
                    if (in_array($dayDow, $prefDows, true)) {
                        $cycleScore = 40; $cycleNote = 'Preferred day ✓';
                    } else {
                        $cycleScore = 15; $cycleNote = 'Not preferred day';
                    }
                } else {
                    $cycleScore = 35; $cycleNote = 'Any day OK';
                }
            } else {
                $cycleScore = 30; $cycleNote = '';
            }

            // ── Weather (0–20) ────────────────────────────────────────────────
            // Weather conditions haven't changed since page load; assume low-risk
            // (= 20 pts) so relative ordering of days is preserved after a drop.
            $weatherScore = 20;

            // ── Density Bonus (0–10) ──────────────────────────────────────────
            $densityScore = $nStops > 0 ? min(10, $nStops * 2) : 0;

            // ── Total + Label ─────────────────────────────────────────────────
            $total = max(0, min(100, $capScore + $cycleScore + $weatherScore + $densityScore));

            if ($total >= 80)                          $label = 'Best';
            elseif ($total >= 60)                      $label = 'Good';
            elseif ($total >= 40)                      $label = 'OK';
            elseif ($capScore === 0)                   $label = 'Full';
            elseif ($cycleScore === 0 && $isBiweekly)  $label = 'Off cycle';
            else                                       $label = 'Poor';

            $scores[$ds] = [
                'score'      => $total,
                'cap'        => $capScore,
                'cycle'      => $cycleScore,
                'weather'    => $weatherScore,
                'density'    => $densityScore,
                'label'      => $label,
                'cycle_note' => $cycleNote,
            ];

            $curDate2->modify('+1 day');
        }

        $placementScores[$visitId] = $scores;

        // Best day for this visit
        $bDay = null; $bScore = -1; $bLabel = 'Poor';
        foreach ($scores as $ds => $sd) {
            if ($sd['score'] > $bScore) {
                $bScore = $sd['score'];
                $bDay   = $ds;
                $bLabel = $sd['label'];
            }
        }
        $visitBestDays[$visitId] = ['date' => $bDay, 'score' => $bScore, 'label' => $bLabel];
    }

    echo json_encode([
        'success'   => true,
        'scores'    => $placementScores,
        'best_days' => $visitBestDays,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error']);
}
