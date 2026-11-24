// PM-managed contract repair (2026-11-24). PREVIEW with scripts/prod-run.sh; Tim reviews before any --commit.
// Moves contracts.client_id and the contract's invoices (client_id + company_id) to the managing firm.
// Skips (and says why): firm has no single clients row; property's own client disagrees with the firm's;
// the contract's CURRENT client holds prepaid credit (client_credits) — credit follows the client, so that needs a human.
// Payments are never touched (invoice_payment_allocations, stripe_payments, autopay_attempts are keyed on invoice_id).
$hasCredits = true; try { $db->query("SELECT 1 FROM client_credits LIMIT 0"); } catch (Throwable $e) { $hasCredits = false; }
$rows = $db->query("
  SELECT c.id AS contract_id, c.contract_number, c.client_id AS cur_client, p.client_id AS prop_client, p.property_manager_id AS pm_company,
         (SELECT COUNT(*) FROM clients k WHERE k.legacy_company_id = p.property_manager_id) AS pm_rows,
         (SELECT MIN(k.id) FROM clients k WHERE k.legacy_company_id = p.property_manager_id) AS pm_client
  FROM contracts c JOIN properties p ON p.id = c.property_id
  WHERE p.property_manager_id > 0 ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC);
$report = [];
foreach ($rows as $r) {
    $cid = (int)$r['contract_id']; $pm = (int)$r['pm_client']; $co = (int)$r['pm_company'];
    $st = $db->prepare("SELECT id, invoice_number, status, balance_due, client_id, company_id FROM invoices WHERE contract_id = ? AND (client_id IS NULL OR client_id <> ? OR company_id IS NULL OR company_id <> ?)");
    $st->execute([$cid, $pm, $co]);
    $inv = $st->fetchAll(PDO::FETCH_ASSOC);
    $contractNeeds = ((int)$r['cur_client'] !== $pm);
    if (!$inv && !$contractNeeds) continue;
    $why = [];
    if ((int)$r['pm_rows'] !== 1) $why[] = 'firm has ' . $r['pm_rows'] . ' clients rows';
    if ($r['prop_client'] && (int)$r['prop_client'] !== $pm) $why[] = 'property client ' . $r['prop_client'] . ' != firm client ' . $pm;
    if ($hasCredits && (int)$r['cur_client'] > 0 && (int)$r['cur_client'] !== $pm) {
        $cq = $db->prepare("SELECT COUNT(*) FROM client_credits WHERE client_id = ?"); $cq->execute([(int)$r['cur_client']]);
        if ((int)$cq->fetchColumn() > 0) $why[] = 'old client ' . $r['cur_client'] . ' holds prepaid credit';
    }
    $line = ['contract' => $r['contract_number'], 'client' => ($r['cur_client'] ?: 'NULL') . ' -> ' . $pm, 'invoices' => array_map(fn($i) => $i['invoice_number'] . ' (' . $i['client_id'] . '/' . $i['company_id'] . ' ' . $i['status'] . ' bal ' . $i['balance_due'] . ')', $inv), 'open_moving' => 0.0];
    foreach ($inv as $i) if (in_array($i['status'], ['sent','viewed','partial','overdue'], true)) $line['open_moving'] += (float)$i['balance_due'];
    if ($why) { $line['SKIPPED'] = implode('; ', $why); $report[] = $line; continue; }
    if ($contractNeeds) $db->prepare("UPDATE contracts SET client_id = ? WHERE id = ?")->execute([$pm, $cid]);
    $u = $db->prepare("UPDATE invoices SET client_id = ?, company_id = ? WHERE id = ?");
    foreach ($inv as $i) $u->execute([$pm, $co, (int)$i['id']]);
    $line['APPLIED'] = $commit ? 'committed' : 'preview (rolled back)';
    $report[] = $line;
}
$out['report'] = $report;
