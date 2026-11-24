// READ-ONLY parity: frozen OLD recipient cascade vs InvoiceRouting::resolveInvoiceRecipients(), every property.
// Run through scripts/recipient-parity.sh (it prepends the frozen copy). Writes nothing; prod-run rolls back anyway.
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceRouting.php';
if (!class_exists('InvoiceRouting') || !method_exists('InvoiceRouting', 'resolveInvoiceRecipients')) {
    throw new RuntimeException('InvoiceRouting::resolveInvoiceRecipients is not on the server yet — deploy InvoiceRouting.php first');
}

$props = $db->query("SELECT id, client_id, owner_company_id, property_manager_id, site_contact_id FROM properties ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$planCo = [];
foreach ($db->query("SELECT property_id, company_id FROM job_plans WHERE company_id IS NOT NULL AND property_id IS NOT NULL GROUP BY property_id, company_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $planCo[(int)$r['property_id']][] = (int)$r['company_id'];
}
$clientCo = [];
foreach ($db->query("SELECT id, legacy_company_id FROM clients WHERE legacy_company_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $clientCo[(int)$r['id']] = (int)$r['legacy_company_id'];
}

$run = function (callable $f) {
    try { return ['ok' => $f()]; } catch (Throwable $e) { return ['err' => get_class($e) . ': ' . $e->getMessage()]; }
};
// What a screen can see of a result: the contact ids, emails, roles and the preselected set (order matters).
$shape = function (array $res) {
    if (isset($res['err'])) return ['err' => $res['err']];
    return array_map(fn($r) => [
        'contact_id' => (int)($r['contact_id'] ?? 0),
        'email'      => strtolower((string)($r['email_address'] ?? '')),
        'role'       => $r['contact_role'] ?? null,
        'preselect'  => array_key_exists('preselect', $r) ? (bool)$r['preselect'] : null,
        'name'       => $r['contact_name'] ?? ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''),
    ], $res['ok']);
};

$cases = 0; $diffs = []; $empty = 0; $noClient = 0; $noClientCases = 0; $withPm = 0;
foreach ($props as $p) {
    $pid = (int)$p['id']; $cid = (int)$p['client_id'];
    $companies = array_values(array_unique(array_filter(array_merge(
        [0], $planCo[$pid] ?? [], [(int)$p['owner_company_id'], (int)$p['property_manager_id']], $cid && isset($clientCo[$cid]) ? [$clientCo[$cid]] : []
    ), fn($v) => $v !== 0)));
    array_unshift($companies, 0);
    $contactIds = array_values(array_unique([0, (int)$p['site_contact_id']]));
    $clients = $cid ? [0, $cid] : [0];
    if (!$cid) $noClient++;
    if ((int)$p['property_manager_id']) $withPm++;
    foreach ($clients as $cl) foreach ($companies as $co) foreach ($contactIds as $ct) {
        $cases++;
        if (!$cid) $noClientCases++;
        $old = $shape($run(fn() => legacyRecipientCascade($db, $pid, $cl, $co, $ct)));
        $new = $shape($run(fn() => InvoiceRouting::resolveInvoiceRecipients($pid, $cl, $co, $ct, $db)));
        if (!isset($old['err']) && !$old) $empty++;
        if ($old !== $new && count($diffs) < 50) {
            $diffs[] = ['property_id' => $pid, 'client_id' => $cl, 'company_id' => $co, 'contact_id' => $ct, 'old' => $old, 'new' => $new];
        }
    }
}
$out['properties']          = count($props);
$out['properties_no_client_id'] = $noClient;
$out['properties_pm_managed']   = $withPm;
$out['cases']               = $cases;
$out['cases_on_no_client_properties'] = $noClientCases;
$out['cases_resolving_to_nobody']     = $empty;
$out['diff_count']          = count($diffs);
$out['diffs']               = $diffs;
$out['verdict']             = $diffs ? 'DIFFS — STOP' : 'PARITY — zero diffs';
