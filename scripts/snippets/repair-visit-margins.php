// Repair visit_margin_snapshots rows whose labor was charged from an absurd plan estimate (2026-10-26).
// Run through scripts/prod-run.sh: a PREVIEW (rolled back) by default, `--commit` keeps the changes.
//   scripts/prod-run.sh scripts/snippets/repair-visit-margins.php            preview
//   scripts/prod-run.sh scripts/snippets/repair-visit-margins.php --commit   apply
// Rows built on real measured time are re-checked against it: if the stored labor does not match the minutes, it is
// corrected (phase 2 of the repair, 2026-10-26). The new labor comes from VisitCompletionService::resolveLaborMinutes, the same rule the
// live code now uses (plus the suspect-timer rule below): an implausible estimate (over 8 hours) leaves labor and margin UNKNOWN (null), not guessed.
require_once APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
// Before the guard is deployed the live class has no resolveLaborMinutes(): use the same rule inline (8 h estimate cap).
$resolve = method_exists('VisitCompletionService', 'resolveLaborMinutes')
    ? ['VisitCompletionService', 'resolveLaborMinutes']
    : function (int $actual, int $timer, int $estimate): array {
        if ($actual > 0 && $actual <= 2400) { return ['minutes' => $actual, 'source' => 'actual']; }
        if ($timer > 0 && $timer <= 2400) { return ['minutes' => $timer, 'source' => 'timer']; }
        if ($estimate > 0 && $estimate <= 480) { return ['minutes' => $estimate, 'source' => 'estimate']; }
        return ['minutes' => null, 'source' => 'none'];
    };

$rows = $db->query("
    SELECT s.id, s.visit_id, s.service_type, s.quoted_amount, s.labor_cost, s.material_cost, s.drive_cost, s.margin_pct,
           jv.actual_duration_minutes act, jv.labor_rate_snapshot rate, jv.labor_cost_snapshot jv_labor, jp.estimated_duration_minutes est,
           (SELECT COALESCE(SUM(t.duration_minutes), 0) FROM job_time_entries t WHERE t.visit_id = s.visit_id AND t.status IN ('completed','edited')) timer
      FROM visit_margin_snapshots s
      JOIN job_visits jv ON jv.id = s.visit_id
      LEFT JOIN job_plans jp ON jp.id = jv.plan_id
")->fetchAll(PDO::FETCH_ASSOC);

$upd = $db->prepare("UPDATE visit_margin_snapshots SET labor_cost = ?, gross_margin = ?, margin_pct = ?, computed_at = NOW() WHERE id = ?");
$updJv = $db->prepare("UPDATE job_visits SET labor_cost_snapshot = ? WHERE id = ? AND labor_cost_snapshot IS NOT NULL AND ROUND(labor_cost_snapshot, 2) = ROUND(?, 2)");

$out = ['rows_total' => count($rows), 'measured_already_right' => 0, 'measured_fixed' => 0, 'estimate_fixed' => 0, 'changed' => 0, 'became_unknown' => 0, 'became_smaller' => 0, 'unchanged' => 0,
        'labor_before' => 0.0, 'labor_after' => 0.0, 'quoted_in_changed' => 0.0, 'by_service' => [], 'biggest' => [], 'job_visits_snapshots_fixed' => 0, 'suspect_timers' => 0, 'suspect_visits' => []];
$changes = [];
foreach ($rows as $r) {
    // Measured time is used when the visit has it. The stored labor of a measured visit is replaced only when it does not
    // match that time (it was charged from the plan estimate before the minutes were saved), by more than 50 cents.
    $res = call_user_func($resolve, (int)$r['act'], (int)$r['timer'], (int)$r['est']);
    $measured = ((int)$r['act'] > 0 || (int)$r['timer'] > 0);
    $rate = (float)$r['rate'];
    // Suspect timer (Tim, 2026-10-26): 10+ measured hours on a job quoted under $200 is a timer left running. Unknown, not a loss.
    $suspect = in_array($res['source'], ['actual', 'timer'], true) && $res['minutes'] !== null
        && (int)$res['minutes'] >= 600 && $r['quoted_amount'] !== null && (float)$r['quoted_amount'] < 200.0;
    if ($suspect) { $res = ['minutes' => null, 'source' => 'suspect']; $out['suspect_timers']++; $out['suspect_visits'][] = (int)$r['visit_id']; }
    $newLabor = ($res['minutes'] !== null && $rate > 0) ? round($rate * $res['minutes'] / 60, 2) : null;
    $oldLabor = $r['labor_cost'] !== null ? (float)$r['labor_cost'] : null;
    $same = ($newLabor === null && $oldLabor === null) || ($newLabor !== null && $oldLabor !== null && abs($newLabor - $oldLabor) < 0.5);
    if ($same) { $out[$measured ? 'measured_already_right' : 'unchanged']++; continue; }
    $out[$measured ? 'measured_fixed' : 'estimate_fixed']++;
    $q = $r['quoted_amount'] !== null ? (float)$r['quoted_amount'] : null;
    $newGross = ($q !== null && $newLabor !== null) ? round($q - $newLabor - (float)$r['material_cost'] - (float)$r['drive_cost'], 2) : null;
    $newPct = ($newGross !== null && $q > 0) ? round($newGross / $q * 100, 2) : null;
    $changes[] = ['r' => $r, 'labor' => $newLabor, 'gross' => $newGross, 'pct' => $newPct];
    $out['changed']++;
    if ($newLabor === null) { $out['became_unknown']++; } else { $out['became_smaller']++; }
    $out['labor_before'] += (float)$oldLabor;
    $out['labor_after'] += (float)$newLabor;
    $out['quoted_in_changed'] += (float)$q;
    $svc = (string)$r['service_type'];
    $b = $out['by_service'][$svc] ?? ['rows' => 0, 'pct_before_sum' => 0.0, 'pct_before_n' => 0];
    $b['rows']++;
    if ($r['margin_pct'] !== null) { $b['pct_before_sum'] += (float)$r['margin_pct']; $b['pct_before_n']++; }
    $out['by_service'][$svc] = $b;
}
foreach ($out['by_service'] as $k => $b) {
    $out['by_service'][$k] = ['rows' => $b['rows'], 'avg_margin_pct_before' => $b['pct_before_n'] ? round($b['pct_before_sum'] / $b['pct_before_n'], 1) : null];
}
usort($changes, function ($a, $b) { return (float)$b['r']['labor_cost'] <=> (float)$a['r']['labor_cost']; });
foreach (array_slice($changes, 0, 6) as $c) {
    $out['biggest'][] = ['visit' => (int)$c['r']['visit_id'], 'quoted' => $c['r']['quoted_amount'], 'labor_before' => $c['r']['labor_cost'], 'labor_after' => $c['labor'], 'margin_after' => $c['pct']];
}
$lowest = array_filter($changes, function ($c) { return $c['pct'] !== null; });
usort($lowest, function ($a, $b) { return $a['pct'] <=> $b['pct']; });
foreach (array_slice($lowest, 0, 6) as $c) {
    $out['lowest_after'][] = ['visit' => (int)$c['r']['visit_id'], 'service' => $c['r']['service_type'], 'quoted' => $c['r']['quoted_amount'], 'labor_after' => $c['labor'], 'margin_after' => $c['pct']];
}
foreach ($changes as $c) {
    $upd->execute([$c['labor'], $c['gross'], $c['pct'], (int)$c['r']['id']]);
    // The visit's own saved labor is only replaced when it still holds the SAME bad figure the margin row held.
    if ($c['r']['labor_cost'] !== null) {
        $updJv->execute([$c['labor'], (int)$c['r']['visit_id'], (float)$c['r']['labor_cost']]);
        $out['job_visits_snapshots_fixed'] += $updJv->rowCount();
    }
}
$out['labor_before'] = round($out['labor_before'], 2);
$out['labor_after'] = round($out['labor_after'], 2);
$out['quoted_in_changed'] = round($out['quoted_in_changed'], 2);
// What the by-service picture looks like afterwards (read inside the same transaction, so it is rolled back in preview).
$out['after_by_service'] = $db->query("SELECT service_type, COUNT(*) n, SUM(margin_pct IS NOT NULL) with_margin, ROUND(AVG(margin_pct),1) avg_margin_pct FROM visit_margin_snapshots GROUP BY service_type HAVING n >= 5 ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
