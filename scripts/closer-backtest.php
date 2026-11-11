<?php
/**
 * Backtest Sam the Closer's minutes model and price against real (or fixture) visits.
 * Local only — reads CSV exports, never touches a database.
 *
 *   php scripts/closer-backtest.php --fixture
 *   php scripts/closer-backtest.php --visits=visits.csv --entries=entries.csv [--hourly=52] [--margin=35] [--floor=25] [--split=2026-01-01]
 *
 * visits.csv  : visit_id,date,service_type,lawn_sqft,edge_ft,hedge_ft,obstacles,quoted_amount,materials
 * entries.csv : visit_id,user_id,start_time,end_time,duration_minutes,time_type,cluster_session_id,time_source
 * (The read-only SQL that produces both is in docs/crm/sales-head.md → "Closer backtest".)
 *
 * Train on visits before --split (default: the start of the latest season in the data), test
 * on the rest. Per service: n, minutes MAE % and bias %, the Closer's price vs what was charged,
 * and the margin actually earned at the charged price from the cleaned timer minutes.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Modules/Sales/Services/CloserPricing.php';
require_once $root . '/app/Modules/Sales/Services/SiteMinutesModel.php';

$opt = getopt('', ['fixture', 'visits:', 'entries:', 'hourly:', 'margin:', 'floor:', 'split:']);
$card = [
    'hourly_cost'   => (float)($opt['hourly'] ?? 52),
    'target_margin' => (float)($opt['margin'] ?? 35) / 100,
    'margin_floor'  => (float)($opt['floor'] ?? 25) / 100,
];

function readCsv(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) { fwrite(STDERR, "Cannot read {$path}\n"); exit(1); }
    $head = array_map('trim', fgetcsv($fh, 0, ',', '"', '\\'));
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($r) === count($head)) $rows[] = array_combine($head, $r);
    }
    return $rows;
}

/** Made-up history: 2 seasons, a hidden true model, noise, plus the traps the cleaner must survive. */
function fixture(): array
{
    mt_srand(42);
    $truth = ['mow' => [7, 5.5, 1.5], 'edge' => [2, 6, 0], 'hedge' => [12, 38, 0], 'cleanup' => [20, 22, 0]];
    $label = ['mow' => 'Lawn Maintenance', 'edge' => 'Edging', 'hedge' => 'Hedge Trimming', 'cleanup' => 'Fall Clean-up'];
    $count = ['mow' => 70, 'edge' => 24, 'hedge' => 12, 'cleanup' => 18];
    $visits = $entries = [];
    $id = 1;
    foreach ($count as $svc => $n) {
        for ($i = 0; $i < $n; $i++, $id++) {
            $season = $i < $n / 2 ? 2025 : 2026;
            $date = sprintf('%d-%02d-%02d', $season, mt_rand(4, 10), mt_rand(1, 28));
            $lawn = mt_rand(1800, 9000);
            $edge = mt_rand(60, 260);
            $hedge = mt_rand(20, 160);
            $obs = mt_rand(0, 5);
            [$a, $b, $c] = $truth[$svc];
            $units = $svc === 'edge' ? $edge / 100 : ($svc === 'hedge' ? $hedge / 100 : $lawn / 1000);
            $mins = max(6, $a + $b * $units + $c * $obs + (mt_rand(-100, 100) / 100) * 0.15 * ($a + $b * $units));
            $price = round(($svc === 'mow' ? 45 + 0.004 * $lawn : ($svc === 'edge' ? 12 : ($svc === 'hedge' ? 80 + 0.4 * $hedge : 150 + 0.02 * $lawn))), 2);
            $visits[] = ['visit_id' => $id, 'date' => $date, 'service_type' => $label[$svc], 'lawn_sqft' => $lawn, 'edge_ft' => $edge,
                         'hedge_ft' => $hedge, 'obstacles' => $obs, 'quoted_amount' => $price, 'materials' => $svc === 'cleanup' ? 6 : 1];
            $start = strtotime($date . ' 09:00:00');
            $crew = ($svc === 'cleanup' || $i % 9 === 0) ? 2 : 1;
            for ($u = 1; $u <= $crew; $u++) {
                $entries[] = ['visit_id' => $id, 'user_id' => $u, 'start_time' => date('Y-m-d H:i:s', $start),
                              'end_time' => date('Y-m-d H:i:s', (int)($start + $mins / $crew * 60)), 'duration_minutes' => '', 'time_type' => 'job',
                              'cluster_session_id' => '', 'time_source' => ''];
            }
            // Trap: a drive entry on most visits (must not count as site time).
            $entries[] = ['visit_id' => $id, 'user_id' => 1, 'start_time' => date('Y-m-d H:i:s', $start - 900),
                          'end_time' => date('Y-m-d H:i:s', $start), 'duration_minutes' => '', 'time_type' => 'drive',
                          'cluster_session_id' => '', 'time_source' => ''];
            // Trap: every 11th visit was a cluster split (not a measurement).
            if ($i % 11 === 5) {
                $entries[] = ['visit_id' => $id, 'user_id' => 1, 'start_time' => $date . ' 00:00:00', 'end_time' => '',
                              'duration_minutes' => 20, 'time_type' => 'job', 'cluster_session_id' => 3, 'time_source' => 'cluster_apportioned'];
            }
        }
    }
    return [$visits, $entries];
}

if (isset($opt['fixture'])) {
    [$visits, $entries] = fixture();
    echo "FIXTURE (made-up visits, not Mowology data)\n";
} else {
    if (empty($opt['visits']) || empty($opt['entries'])) { fwrite(STDERR, "Need --visits and --entries, or --fixture\n"); exit(1); }
    $visits = readCsv($opt['visits']);
    $entries = readCsv($opt['entries']);
}

$byVisit = [];
foreach ($entries as $e) $byVisit[(int)$e['visit_id']][] = $e;

$rows = [];
$dropped = ['no service' => 0, 'not measured' => 0, 'no usable timer' => 0];
foreach ($visits as $v) {
    $svc = CloserPricing::serviceKey((string)$v['service_type']);
    if (!$svc) { $dropped['no service']++; continue; }
    $lot = ['lawn_sqft' => (float)$v['lawn_sqft'], 'edge_ft' => (float)$v['edge_ft'], 'hedge_ft' => (float)$v['hedge_ft'], 'obstacles' => (int)$v['obstacles']];
    $units = SiteMinutesModel::units($svc, $lot);
    if ($units === null) { $dropped['not measured']++; continue; }
    $m = SiteMinutesModel::visitMinutes($byVisit[(int)$v['visit_id']] ?? []);
    if (!SiteMinutesModel::usable($units, $m)) { $dropped['no usable timer']++; continue; }
    $rows[$svc][] = ['date' => $v['date'], 'units' => $units, 'obstacles' => $lot['obstacles'], 'minutes' => $m['person_minutes'],
                     'lot' => $lot, 'quoted' => (float)$v['quoted_amount'], 'materials' => (float)$v['materials']];
}

$dates = [];
foreach ($rows as $list) foreach ($list as $r) $dates[] = $r['date'];
sort($dates);
$split = $opt['split'] ?? ($dates ? substr(end($dates), 0, 4) . '-01-01' : date('Y-01-01'));

printf("Visits: %d · dropped: %s · train < %s, test ≥ %s\n", count($visits), json_encode($dropped), $split, $split);
printf("Card: \$%.2f/person-hour · target %d%% · floor %d%% (drive minutes not in the CSV → 0)\n\n",
    $card['hourly_cost'], $card['target_margin'] * 100, $card['margin_floor'] * 100);
printf("%-9s %5s %5s %8s %8s %10s %10s %9s %7s\n", 'service', 'train', 'test', 'MAE %', 'bias %', 'charged', 'Closer', 'earned %', '<floor');
foreach ($rows as $svc => $list) {
    $train = array_values(array_filter($list, function ($r) use ($split) { return $r['date'] < $split; }));
    $test  = array_values(array_filter($list, function ($r) use ($split) { return $r['date'] >= $split; }));
    if (!$train || !$test) {
        printf("%-9s %5d %5d  (needs visits on both sides of the split)\n", $svc, count($train), count($test));
        continue;
    }
    $model = SiteMinutesModel::fit($train);
    $abs = $bias = $charged = $closer = $rev = $cost = 0.0;
    $under = 0;
    foreach ($test as $r) {
        $p = SiteMinutesModel::predict($model, $svc, $r['lot']) ?? 0.0;
        $abs  += abs($p - $r['minutes']) / $r['minutes'];
        $bias += ($p - $r['minutes']) / $r['minutes'];
        $res = CloserPricing::price(['site_minutes' => $p, 'materials' => $r['materials']], $card);
        $closer += $res['price'];
        $charged += $r['quoted'];
        $actualCost = $r['minutes'] / 60 * $card['hourly_cost'] + $r['materials'];
        $rev += $r['quoted'];
        $cost += $actualCost;
        if (CloserPricing::belowFloor($r['quoted'], $actualCost, $card['margin_floor'])) $under++;
    }
    $n = count($test);
    printf("%-9s %5d %5d %8.1f %+8.1f %10s %10s %9.1f %4d/%d\n", $svc, count($train), $n, $abs / $n * 100, $bias / $n * 100,
        '$' . number_format($charged / $n, 2), '$' . number_format($closer / $n, 2), ($rev - $cost) / $rev * 100, $under, $n);
}
echo "\nMAE/bias: predicted vs actual person-minutes on the test season. charged/Closer: average per visit.\n";
echo "earned %: margin at the charged price using the cleaned timer minutes. <floor: test visits that earned under the floor.\n";
