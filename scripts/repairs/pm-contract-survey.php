// READ-ONLY survey (run with scripts/prod-run.sh, never --commit):
// every contract whose property is PM-managed: current vs correct client, invoices, open balance, payment ties.
$rows = $db->query("
  SELECT c.id AS contract_id, c.contract_number, c.status, c.client_id AS cur_client, cc.display_name AS cur_name, cc.client_type AS cur_type,
         p.id AS property_id, p.client_id AS prop_client, p.property_manager_id AS pm_company,
         (SELECT COUNT(*) FROM clients k WHERE k.legacy_company_id = p.property_manager_id) AS pm_client_rows,
         (SELECT MIN(k.id) FROM clients k WHERE k.legacy_company_id = p.property_manager_id) AS pm_client
  FROM contracts c
  JOIN properties p ON p.id = c.property_id
  LEFT JOIN clients cc ON cc.id = c.client_id
  WHERE p.property_manager_id IS NOT NULL AND p.property_manager_id > 0
  ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC);
$tbl = function ($t) use ($db) { try { $db->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (Throwable $e) { return false; } };
$hasAlloc = $tbl('invoice_payment_allocations'); $hasBds = $tbl('bank_deposit_sources'); $hasStripe = $tbl('stripe_payments'); $hasAuto = $tbl('autopay_attempts');
$res = [];
foreach ($rows as $r) {
    $s = $db->prepare("SELECT id, invoice_number, status, total, balance_due, amount_paid, client_id, company_id FROM invoices WHERE contract_id = ?");
    $s->execute([$r['contract_id']]);
    $inv = $s->fetchAll(PDO::FETCH_ASSOC);
    $open = 0.0; $wrong = 0; $paidTies = [];
    foreach ($inv as $i) {
        if (in_array($i['status'], ['sent','viewed','partial','overdue'], true)) $open += (float)$i['balance_due'];
        if ((int)$i['client_id'] !== (int)$r['pm_client'] || (int)$i['company_id'] !== (int)$r['pm_company']) $wrong++;
        $ties = [];
        $q = function ($sql) use ($db, $i) { try { $x = $db->prepare($sql); $x->execute([$i['id']]); return (int)$x->fetchColumn(); } catch (Throwable $e) { return -1; } };
        if ($hasAlloc && $q("SELECT COUNT(*) FROM invoice_payment_allocations WHERE invoice_id = ?") > 0) $ties[] = 'alloc';
        if ($hasStripe && $q("SELECT COUNT(*) FROM stripe_payments WHERE invoice_id = ?") > 0) $ties[] = 'stripe';
        if ($hasAuto && $q("SELECT COUNT(*) FROM autopay_attempts WHERE invoice_id = ?") > 0) $ties[] = 'autopay';
        if ((float)$i['amount_paid'] > 0 && !$ties) $ties[] = 'amount_paid-only';
        if ($ties) $paidTies[$i['invoice_number']] = implode('+', $ties);
    }
    $flag = [];
    if ((int)$r['pm_client_rows'] !== 1) $flag[] = 'pm_client_rows=' . $r['pm_client_rows'];
    if ($r['prop_client'] && (int)$r['prop_client'] !== (int)$r['pm_client']) $flag[] = 'prop_client(' . $r['prop_client'] . ')!=pm_client';
    if ((int)$r['cur_client'] === (int)$r['pm_client']) $flag[] = 'contract_ok';
    $res[] = [$r['contract_id'], $r['contract_number'], $r['status'], 'cur=' . ($r['cur_client'] ?: 'NULL') . ' ' . $r['cur_name'] . '/' . $r['cur_type'],
              'pm=' . $r['pm_client'] . '(co ' . $r['pm_company'] . ')', 'inv=' . count($inv), 'wrong_inv=' . $wrong, 'open=' . number_format($open, 2),
              'ties=' . json_encode($paidTies), 'flags=' . implode(',', $flag)];
}
$out['n'] = count($res);
$out['rows'] = array_map(fn($x) => implode(' | ', $x), $res);
