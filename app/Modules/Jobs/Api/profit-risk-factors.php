<?php
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
    require_once CRM_INCLUDES  . '/functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';

    requireLogin();
    session_write_close();

    // Accept GET or POST JSON
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $planId = (int)($input['plan_id'] ?? 0);
    } else {
        $planId = (int)($_GET['plan_id'] ?? 0);
    }

    if ($planId <= 0) {
        throw new InvalidArgumentException('plan_id is required');
    }

    $db = getDB();

    // ── Extra plan data ──────────────────────────────────────────────────────
    $stmt = $db->prepare(
        "SELECT price_per_visit, estimated_duration_minutes, plan_number, title
         FROM job_plans WHERE id = ?"
    );
    $stmt->execute([$planId]);
    $planRow = $stmt->fetch();
    if (!$planRow) {
        throw new RuntimeException('Plan not found');
    }

    // Total non-cancelled visits
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status NOT IN ('cancelled')"
    );
    $stmt->execute([$planId]);
    $totalVisits = (int)$stmt->fetchColumn();

    // ── Full profitability breakdown ─────────────────────────────────────────
    $p = getPlanProfitability($planId);

    if (empty($p) || !($p['has_data'] ?? false)) {
        echo json_encode([
            'success'  => true,
            'has_data' => false,
            'message'  => 'No completed visits yet — check back after the first visit is done.',
        ]);
        exit;
    }

    // ── Scoring helpers ──────────────────────────────────────────────────────
    $clamp = static function (float $v): float {
        return max(0.0, min(1.0, $v));
    };

    $severity = static function (float $s): string {
        if ($s >= 0.75) return 'critical';
        if ($s >= 0.50) return 'high';
        if ($s >= 0.25) return 'medium';
        return 'low';
    };

    $fmtPct = static function (float $v, int $dp = 1): string {
        return number_format($v, $dp) . '%';
    };

    $fmtMins = static function (int $m): string {
        if ($m >= 60) return floor($m / 60) . 'h ' . ($m % 60) . 'm';
        return $m . 'm';
    };

    // ── Raw values ───────────────────────────────────────────────────────────
    $rev            = max(0.01, (float)$p['revenue']);
    $labor          = (float)$p['labor_cost'];
    $expenses       = (float)$p['expense_cost'];
    $overhead       = (float)$p['overhead_cost'];
    $totalCost      = (float)$p['total_cost'];
    $profit         = (float)$p['profit'];
    $marginPct      = (float)$p['margin_pct'];
    $completedVisits = max(0, (int)$p['completed_visits']);
    $laborMins      = (float)$p['labor_minutes'];
    $isEstimated    = (bool)$p['labor_estimated'];
    $pricePerVisit  = (float)($planRow['price_per_visit'] ?? 0);
    $estDuration    = max(1, (int)($planRow['estimated_duration_minutes'] ?? 60));

    // ── Risk scores (0 = safe, 1 = extreme risk) ─────────────────────────────

    // 1. Profit Margin — target ≥ 40 %
    $marginScore = $clamp(1 - ($marginPct / 40));

    // 2. Labour ratio — target ≤ 45 % of revenue
    $laborRatioPct = ($labor / $rev) * 100;
    $laborScore    = $clamp(($labor / $rev) / 0.45);

    // 3. Time accuracy — actual vs estimated per visit
    if ($isEstimated || $completedVisits === 0) {
        $timeScore   = 0.15;
        $timeActual  = '—';
    } else {
        $avgActual   = $laborMins / $completedVisits;
        $overRatio   = max(0, $avgActual - $estDuration) / $estDuration;
        $timeScore   = $clamp($overRatio);
        $timeActual  = $fmtMins((int)round($avgActual)) . '/v';
    }

    // 4. Expense (materials) ratio — target ≤ 15 % of revenue
    $expRatioPct = ($expenses / $rev) * 100;
    $expScore    = $clamp(($expenses / $rev) / 0.15);

    // 5. Overhead ratio — target ≤ 25 % of revenue
    $ohRatioPct = ($overhead / $rev) * 100;
    $ohScore    = $clamp(($overhead / $rev) / 0.25);

    // 6. Revenue realization — actual vs price_per_visit × completed
    $expectedRev = $pricePerVisit * max(1, $completedVisits);
    $revScore    = $expectedRev > 0 ? $clamp(1 - ($p['revenue'] / $expectedRev)) : 0.0;
    $revActual   = '$' . number_format($p['revenue'], 0);
    $revExpected = '$' . number_format($expectedRev, 0);

    // 7. Visit completion — completed / total scheduled
    $completionScore = $clamp(1 - ($completedVisits / max(1, $totalVisits)));

    // 8. Cost overrun — total cost as % of revenue, target ≤ 65 % (35 % margin)
    $costRatio = $totalCost / $rev;
    $costScore = $clamp(($costRatio - 0.65) / 0.35);

    // ── Factor definitions (ordered 0–7, clockwise from top) ─────────────────
    $factors = [
        [
            'key'            => 'margin',
            'label'          => 'Margin',
            'raw_value'      => $fmtPct($marginPct),
            'expected_value' => '40%',
            'unit'           => '%',
            'score_0_1'      => round($marginScore, 3),
            'severity'       => $severity($marginScore),
            'explanation'    => $marginPct >= 40
                                    ? 'Exceeds 40% target'
                                    : number_format($marginPct, 1) . '% below 40% target',
        ],
        [
            'key'            => 'labor',
            'label'          => 'Labour',
            'raw_value'      => $fmtPct($laborRatioPct),
            'expected_value' => '≤45%',
            'unit'           => '%',
            'score_0_1'      => round($laborScore, 3),
            'severity'       => $severity($laborScore),
            'explanation'    => 'Labour is ' . $fmtPct($laborRatioPct) . ' of revenue',
        ],
        [
            'key'            => 'time',
            'label'          => 'Time',
            'raw_value'      => $isEstimated ? 'est.' : $timeActual,
            'expected_value' => $fmtMins($estDuration),
            'unit'           => 'min',
            'score_0_1'      => round($timeScore, 3),
            'severity'       => $severity($timeScore),
            'explanation'    => $isEstimated
                                    ? 'Using estimated duration — log actual time for accuracy'
                                    : 'Actual ' . $timeActual . ' vs ' . $fmtMins($estDuration) . ' estimate',
        ],
        [
            'key'            => 'expenses',
            'label'          => 'Expenses',
            'raw_value'      => $fmtPct($expRatioPct),
            'expected_value' => '≤15%',
            'unit'           => '%',
            'score_0_1'      => round($expScore, 3),
            'severity'       => $severity($expScore),
            'explanation'    => 'Materials & expenses are ' . $fmtPct($expRatioPct) . ' of revenue',
        ],
        [
            'key'            => 'overhead',
            'label'          => 'Overhead',
            'raw_value'      => $fmtPct($ohRatioPct),
            'expected_value' => '≤25%',
            'unit'           => '%',
            'score_0_1'      => round($ohScore, 3),
            'severity'       => $severity($ohScore),
            'explanation'    => 'Overhead allocation is ' . $fmtPct($ohRatioPct) . ' of revenue',
        ],
        [
            'key'            => 'revenue',
            'label'          => 'Revenue',
            'raw_value'      => $revActual,
            'expected_value' => $revExpected,
            'unit'           => '$',
            'score_0_1'      => round($revScore, 3),
            'severity'       => $severity($revScore),
            'explanation'    => 'Actual ' . $revActual . ' vs ' . $revExpected . ' quoted',
        ],
        [
            'key'            => 'visits',
            'label'          => 'Visits',
            'raw_value'      => $completedVisits . '/' . $totalVisits,
            'expected_value' => $totalVisits . '/' . $totalVisits,
            'unit'           => 'count',
            'score_0_1'      => round($completionScore, 3),
            'severity'       => $severity($completionScore),
            'explanation'    => $completedVisits . ' of ' . $totalVisits . ' visits completed',
        ],
        [
            'key'            => 'cost',
            'label'          => 'Cost',
            'raw_value'      => $fmtPct($costRatio * 100),
            'expected_value' => '≤65%',
            'unit'           => '%',
            'score_0_1'      => round($costScore, 3),
            'severity'       => $severity($costScore),
            'explanation'    => 'Total cost is ' . $fmtPct($costRatio * 100) . ' of revenue',
        ],
    ];

    // ── Recommendations (for medium/high/critical factors) ──────────────────
    $recs = [];

    if ($marginScore >= 0.25 && $totalCost > 0 && $completedVisits > 0) {
        $targetRev   = $totalCost / 0.60;
        $priceNeeded = $targetRev / $completedVisits;
        $diff        = $priceNeeded - $pricePerVisit;
        $diffPct     = $pricePerVisit > 0 ? ($diff / $pricePerVisit) * 100 : 0;
        $recs[] = [
            'key'  => 'Margin',
            'text' => sprintf(
                'Raise price/visit from %s → %s (%+.2f, %+.1f%%) to reach 40%% margin.',
                '$' . number_format($pricePerVisit, 2),
                '$' . number_format($priceNeeded, 2),
                $diff, $diffPct
            ),
        ];
    }

    if ($laborScore >= 0.25 && $completedVisits > 0) {
        $labourPerVisit   = $labor / $completedVisits;
        $targetPriceLabor = $labourPerVisit / 0.45;

        if ($targetPriceLabor > $pricePerVisit) {
            // Current price is too low — labour is genuinely expensive relative to revenue
            $recs[] = [
                'key'  => 'Labour',
                'text' => sprintf(
                    'Labour (%s/visit) is eating into margin. To keep it under 45%%, raise price from %s → %s/visit, or reduce average crew time.',
                    '$' . number_format($labourPerVisit, 2),
                    '$' . number_format($pricePerVisit, 2),
                    '$' . number_format($targetPriceLabor, 2)
                ),
            ];
        } else {
            // Price is fine — the ratio is slightly elevated due to few completed visits or crew time
            $recs[] = [
                'key'  => 'Labour',
                'text' => sprintf(
                    'Labour is %s%% of revenue (%s/visit at %s/visit price). Pricing is adequate — reducing crew time on site is the fastest way to improve this ratio.',
                    number_format($laborRatioPct, 1),
                    '$' . number_format($labourPerVisit, 2),
                    '$' . number_format($pricePerVisit, 2)
                ),
            ];
        }
    }

    if ($timeScore >= 0.25 && !$isEstimated && $completedVisits > 0) {
        $avgActualMins = (int)round($laborMins / $completedVisits);
        $overMins      = $avgActualMins - $estDuration;
        $overPct       = $estDuration > 0 ? round(($overMins / $estDuration) * 100) : 0;
        $recs[] = [
            'key'  => 'Time',
            'text' => sprintf(
                'Jobs are averaging %s over estimate (%d%% longer). Either streamline the job or update the plan estimate to %s.',
                $fmtMins($overMins), $overPct, $fmtMins($avgActualMins)
            ),
        ];
    }

    if ($ohScore >= 0.25) {
        $recs[] = [
            'key'  => 'Overhead',
            'text' => "Overhead is allocated as a % of labour. Adding more stops to the same route day spreads overhead across more revenue and reduces each plan's share.",
        ];
    }

    if ($completionScore >= 0.25 && $totalVisits > 0) {
        $completionPct    = round(($completedVisits / $totalVisits) * 100);
        $projectedRevenue = $pricePerVisit * $totalVisits;
        $recs[] = [
            'key'  => 'Visits',
            'text' => sprintf(
                'Plan is %d%% complete. Full completion projects %s revenue. Check for skipped or unscheduled visits.',
                $completionPct, '$' . number_format($projectedRevenue, 0)
            ),
        ];
    }

    echo json_encode([
        'success'  => true,
        'has_data' => true,
        'data'     => [
            'plan_id'         => $planId,
            'plan_number'     => $planRow['plan_number'],
            'plan_title'      => $planRow['title'],
            'net_profit'      => round($profit, 2),
            'net_margin'      => round($marginPct, 1),
            'factors'         => $factors,
            'recommendations' => $recs,
            'updated_at'      => date('c'),
        ],
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
