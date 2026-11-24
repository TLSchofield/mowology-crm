// Head health snapshot — READ-ONLY, counts and ratios only. Run with: scripts/prod-run.sh scripts/dept/heads/snapshot.php
// (a preview: prod-run rolls everything back; this script only SELECTs). Never emits names, amounts, subjects or message text:
// every value in $out is a number or a short fixed label (a status, a kind, a reason code, a field name, a cron job name).
// Each metric checks that its table and columns exist first; a missing one is reported as a gap, not an error.
$DAYS = 84;
$cols = [];
foreach ($db->query("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")->fetchAll() as $r) {
    $cols[$r['t']][$r['c']] = true;
}
$has = function (string $t, array $cs) use ($cols): bool { foreach ($cs as $c) { if (empty($cols[$t][$c])) return false; } return true; };
$pick = function (string $t, array $cands) use ($cols) { foreach ($cands as $c) { if (!empty($cols[$t][$c])) return $c; } return null; };
// A label is emitted only if it looks like a machine code (lowercase, no spaces) AND, where a column has a known set, is in it. Anything else becomes 'other'.
$ALLOW = [
    'reason_code' => ['not_our_service', 'not_our_voice', 'bad_timing', 'too_soon', 'wrong_service', 'wrong_id', 'wrong_statement', 'other'],
    'field' => ['subtotal', 'gst', 'pst', 'total', 'accounting_category', 'category', 'vendor', 'job', 'asset_tag', 'line_items', 'expense_date', 'payment_method', 'item'],
];
$label = function ($v, ?string $set = null) use ($ALLOW): string {
    $v = (string)$v;
    if (!preg_match('/^[a-z][a-z0-9_.:-]{0,39}$/', $v)) return 'other';
    return ($set !== null && isset($ALLOW[$set]) && !in_array($v, $ALLOW[$set], true)) ? 'other' : $v;
};

$heads = [];
$gap = function (string $h, string $why) use (&$heads) { $heads[$h]['gaps'][] = $why; };

/** weekly counts of rows in $t where $where, bucketed by the first existing date column among $dates; $by optionally splits by a label column. */
$weekly = function (string $h, string $metric, string $t, array $dates, string $where = '1=1', ?string $by = null, ?string $set = null) use (&$heads, $has, $pick, $db, $DAYS, $label, $gap) {
    $d = $pick($t, $dates);
    if ($d === null || ($by !== null && $pick($t, [$by]) === null)) { $gap($h, "$metric: no $t." . ($d === null ? implode('/', $dates) : 'column')); return; }
    $sel = "DATE_FORMAT(`$d`, '%x-W%v') w, COUNT(*) n" . ($by ? ", `$by` b" : '');
    $sql = "SELECT $sel FROM `$t` WHERE $where AND `$d` >= (NOW() - INTERVAL $DAYS DAY) GROUP BY w" . ($by ? ', b' : '') . " ORDER BY w";
    try { $rows = $db->query($sql)->fetchAll(); } catch (Throwable $e) { $gap($h, "$metric: query failed"); return; }
    $m = ['total' => 0, 'weekly' => []];
    foreach ($rows as $r) {
        $m['total'] += (int)$r['n'];
        if ($by) { $m['by'][$label($r['b'], $set)] = ($m['by'][$label($r['b'], $set)] ?? 0) + (int)$r['n']; $m['weekly'][$r['w']] = ($m['weekly'][$r['w']] ?? 0) + (int)$r['n']; }
        else { $m['weekly'][$r['w']] = (int)$r['n']; }
    }
    $heads[$h]['metrics'][$metric] = $m;
};

/** how much a person changed a drafted text: compares two columns in PHP and emits only counts (unchanged / light / heavy). */
$edits = function (string $h, string $metric, string $t, string $a, string $b, string $where = '1=1') use (&$heads, $has, $pick, $db, $DAYS, $gap) {
    $d = $pick($t, ['decided_at', 'sent_at', 'updated_at', 'created_at']);
    if ($d === null || !$has($t, [$a, $b])) { $gap($h, "$metric: no $t.$a/$b"); return; }
    try { $rows = $db->query("SELECT `$a` a, `$b` b FROM `$t` WHERE $where AND `$b` IS NOT NULL AND `$b` <> '' AND `$d` >= (NOW() - INTERVAL $DAYS DAY) ORDER BY `$d` DESC LIMIT 150")->fetchAll(); }
    catch (Throwable $e) { $gap($h, "$metric: query failed"); return; }
    $c = ['unchanged' => 0, 'light' => 0, 'heavy' => 0];
    foreach ($rows as $r) {
        $x = (string)$r['a']; $y = (string)$r['b'];
        if ($x === $y) { $c['unchanged']++; continue; }
        similar_text(mb_substr($x, 0, 300), mb_substr($y, 0, 300), $pct);
        $c[$pct >= 85 ? 'light' : 'heavy']++;
    }
    $heads[$h]['metrics'][$metric] = ['total' => array_sum($c), 'by' => $c];
};

/** which fields the owner overrode in a JSON outcome ({"fields":{"gst":{"overridden":true}}}-style): counts per FIELD NAME only. */
$fieldOverrides = function (string $h, string $metric, string $t, string $col) use (&$heads, $has, $pick, $db, $DAYS, $label, $gap) {
    $d = $pick($t, ['created_at', 'decided_at', 'updated_at']);
    if ($d === null || !$has($t, [$col])) { $gap($h, "$metric: no $t.$col"); return; }
    try { $rows = $db->query("SELECT `$col` j FROM `$t` WHERE `$col` IS NOT NULL AND `$d` >= (NOW() - INTERVAL $DAYS DAY) ORDER BY `$d` DESC LIMIT 600")->fetchAll(); } catch (Throwable $e) { $gap($h, "$metric: query failed"); return; }
    $by = []; $any = 0; $n = 0;
    foreach ($rows as $r) {
        $j = json_decode((string)$r['j'], true); if (!is_array($j)) continue; $n++; $hit = false;
        $walk = function ($node, $name) use (&$walk, &$by, &$hit, $label) {
            if (!is_array($node)) return;
            if (!empty($node['overridden'])) { $k = $label($name, 'field'); $by[$k] = ($by[$k] ?? 0) + 1; $hit = true; }
            foreach ($node as $k => $v) { if (is_array($v)) $walk($v, is_string($k) ? $k : $name); }
        };
        $walk($j, 'item'); if ($hit) $any++;
    }
    $heads[$h]['metrics'][$metric] = ['total' => $n, 'with_override' => $any, 'by' => $by];
};

// ---- Kaname (Charlie)
$weekly('kaname', 'shown',     'charlie_items', ['first_seen', 'created_at'], '1=1', 'kind');
$weekly('kaname', 'opened',    'charlie_items', ['opened_at'], 'opened_at IS NOT NULL', 'kind');
$weekly('kaname', 'dealt_with','charlie_items', ['resolved_at'], 'resolved_at IS NOT NULL', 'kind');
$weekly('kaname', 'dismissed', 'charlie_items', ['dismissed_at'], 'dismissed_at IS NOT NULL', 'kind');
$weekly('kaname', 'overridden_rulings', 'charlie_rulings', ['overridden_at'], 'overridden_at IS NOT NULL');
$gap('kaname', 'suggested-versus-final is not recorded');

// ---- Sam
$weekly('sam', 'followups', 'sam_followups', ['created_at', 'updated_at'], '1=1', 'status');
$edits('sam', 'followup_edits', 'sam_followups', 'suggested_body', 'final_body', "status = 'sent'");
$weekly('sam', 'requests', 'sam_requests', ['created_at', 'updated_at'], '1=1', 'status');
$weekly('sam', 'acceptances', 'sam_acceptances', ['created_at', 'updated_at'], '1=1', 'status');
$weekly('sam', 'quotes_by_status', 'quotes', ['created_at'], '1=1', 'status');
$weekly('sam', 'quotes_accepted', 'quotes', ['accepted_at'], 'accepted_at IS NOT NULL');

// ---- Penny
$weekly('penny', 'receipt_suggestions', 'expense_suggestions', ['created_at', 'updated_at'], '1=1', 'status');
$gap('penny', 'which field was corrected is not read yet (an edited receipt is counted by status)');
$weekly('penny', 'bank_line_reviews', 'bank_line_reviews', ['created_at', 'decided_at', 'updated_at'], '1=1', 'outcome');
if (!$has('bank_line_reviews', ['suggested_account_id', 'final_account_id'])) { $gap('penny', 'bank_account_changed: no suggested/final account columns'); } else {
    $weekly('penny', 'bank_account_changed', 'bank_line_reviews', ['created_at', 'decided_at', 'updated_at'], 'final_account_id IS NOT NULL AND suggested_account_id IS NOT NULL AND final_account_id <> suggested_account_id');
}
$weekly('penny', 'lessons_taught', 'head_lessons', ['created_at'], "head = 'penny'");
$weekly('penny', 'missing_receipts', 'penny_missing_receipts', ['created_at', 'updated_at'], '1=1', 'status');
$weekly('penny', 'lookback_proposals', 'lookback_proposals', ['created_at', 'updated_at'], '1=1', 'status');
$weekly('penny', 'revisits', 'penny_revisits', ['created_at', 'updated_at'], '1=1', 'status');

// ---- Otto
$weekly('otto', 'suggestions', 'otto_suggestions', ['created_at', 'updated_at'], '1=1', 'status');
$weekly('otto', 'suggestions_by_kind', 'otto_suggestions', ['created_at', 'updated_at'], '1=1', 'kind');
$weekly('otto', 'accepted_by_kind', 'otto_suggestions', ['created_at', 'updated_at'], "status = 'accepted'", 'kind');
$weekly('otto', 'expired_by_kind', 'otto_suggestions', ['created_at', 'updated_at'], "status = 'expired'", 'kind');
$weekly('otto', 'missed_visit_decisions', 'otto_missed_review_items', ['decided_at'], 'decided_at IS NOT NULL', 'decision');
$weekly('otto', 'auto_visits', 'otto_auto_visits', ['created_at', 'updated_at'], '1=1', 'status');
$gap('otto', 'job outcome is not linked to a suggestion');

// ---- Mia
$weekly('mia', 'suggestions', 'mia_suggestions', ['created_at', 'updated_at'], '1=1', 'status');
$weekly('mia', 'suggestion_outcomes', 'mia_suggestions', ['outcome_at'], 'outcome_at IS NOT NULL', 'outcome');
$weekly('mia', 'edited_before_sending', 'mia_suggestions', ['updated_at', 'created_at'], 'edited = 1');
$edits('mia', 'draft_edits', 'mia_suggestions', 'draft_body', 'sent_body', "status = 'sent'");
$weekly('mia', 'decisions', 'mia_decisions', ['created_at', 'decided_at'], '1=1', 'decision');
$weekly('mia', 'decision_reasons', 'mia_decisions', ['created_at', 'decided_at'], "decision IN ('rejected','later')", 'reason_code', 'reason_code');

// ---- Yui
$weekly('yui', 'drafts', 'yui_actions', ['created_at', 'updated_at'], '1=1', 'status');
$edits('yui', 'draft_edits', 'yui_actions', 'suggested_body', 'final_body', "status = 'sent'");
$weekly('yui', 'inbound_messages', 'sales_messages', ['sent_at', 'created_at'], "direction = 'inbound'");
$gap('yui', 'ignored drafts and email opens are not recorded');

// ---- Ada (Technology)
$weekly('ada', 'cron_runs', 'cron_runs', ['ran_at'], '1=1', 'status');
$weekly('ada', 'cron_failures_by_job', 'cron_runs', ['ran_at'], "status IN ('error','warning')", 'cron_key');
$weekly('ada', 'accepted_risks', 'ada_accepted_risks', ['accepted_at'], '1=1', 'released_how');
$weekly('ada', 'fixed_marks', 'ada_fixed_marks', ['created_at', 'updated_at'], '1=1', 'outcome');
$weekly('ada', 'pulse_checks', 'pulse_checks', ['created_at', 'checked_at', 'updated_at'], '1=1', 'status');
$gap('ada', 'corrections are not recorded');

$out = ['generated' => date('c'), 'window_days' => $DAYS, 'heads' => $heads, 'tables_seen' => count($cols)];
