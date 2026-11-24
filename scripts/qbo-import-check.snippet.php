// qbo-import-check.snippet.php — read-only: does what the QuickBooks onboarding import saved in a business match the
// QuickBooks snapshot, field by field? (Tim 2026-11-04: "ensure the data pulled from quickbooks matches our databases")
// Run: scripts/prod-run.sh scripts/qbo-import-check.snippet.php   (never needs --commit)
// Set the business database below. Clients: name, email, phone, mobile, company, billing, property (+ who owns an
// address already taken). Services: name, price, QuickBooks item link. Suppliers: name. Accounts: mappings count.
$D = '`mowology_rehearsal2`';   // ← the business database to check
$norm = function ($v) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$v))); };
$digits = function ($v) { return preg_replace('/\D+/', '', (string)$v); };
$snap = function ($kind) use ($db, $D) { $s = $db->prepare("SELECT data FROM $D.qbo_onboarding_snapshot WHERE kind = ?"); $s->execute([$kind]); return array_map(fn($r) => json_decode($r['data'], true), $s->fetchAll()); };
$has = function (array $row, $want, $cmp) { foreach ($row as $v) if ($v !== null && $cmp($v) === $want) return true; return false; };
$out = ['clients' => ['checked' => 0, 'not_found' => [], 'misses' => []], 'products' => ['checked' => 0, 'misses' => []], 'suppliers' => ['checked' => 0, 'missing' => []]];
$miss = function (&$bucket, $field, $who, $want) { $bucket[$field] = ($bucket[$field] ?? ['count' => 0, 'eg' => []]); $bucket[$field]['count']++; if (count($bucket[$field]['eg']) < 2) $bucket[$field]['eg'][] = "$who: expected '$want'"; };

foreach ($snap('customer') as $c) {
  if (($c['Active'] ?? true) === false || !empty($c['ParentRef']['value'])) continue;
  $out['clients']['checked']++;
  $who = (string)($c['DisplayName'] ?? $c['Id']);
  $l = $db->prepare("SELECT entity_id FROM $D.qbo_links WHERE entity_type = 'client' AND qbo_id = ? AND entity_id LIKE 'contact:%' LIMIT 1"); $l->execute([(string)$c['Id']]);
  $cid = (int)substr((string)$l->fetchColumn(), 8);
  if (!$cid) { $out['clients']['not_found'][] = $who; continue; }
  $contact = $db->query("SELECT * FROM $D.contacts WHERE id = $cid")->fetch() ?: [];
  $props = $db->query("SELECT * FROM $D.properties WHERE site_contact_id = $cid")->fetchAll();
  $company = [];
  try { $company = $db->query("SELECT co.* FROM $D.companies co JOIN $D.contacts ct ON ct.company_id = co.id WHERE ct.id = $cid")->fetch() ?: []; } catch (Throwable $e) {}
  if (!$company) try { $company = $db->query("SELECT * FROM $D.companies WHERE primary_contact_id = $cid LIMIT 1")->fetch() ?: []; } catch (Throwable $e) {}
  [$g, $f] = QboOnboardingService::personName($c);
  $email = strtolower(trim((string)($c['PrimaryEmailAddr']['Address'] ?? '')));
  $ship = QboOnboardingService::address($c['ShipAddr'] ?? null) ?? QboOnboardingService::address($c['BillAddr'] ?? null);
  $bill = QboOnboardingService::address($c['BillAddr'] ?? null) ?? $ship;
  $whole = array_merge($contact, $company ?: []);
  foreach (['first name' => $g, 'last name' => $f, 'email' => $email] as $field => $want)
    if ($want !== '' && !$has($contact, $norm($want), $norm)) $miss($out['clients']['misses'], $field, $who, $want);
  foreach (['phone' => $c['PrimaryPhone']['FreeFormNumber'] ?? '', 'mobile' => $c['Mobile']['FreeFormNumber'] ?? ''] as $field => $want)
    if ($digits($want) !== '' && !$has($contact, $digits($want), $digits)) $miss($out['clients']['misses'], $field, $who, $want);
  if ($co = QboOnboardingService::companyOf($c)) if (!$company || !$has($company, $norm($co), $norm)) $miss($out['clients']['misses'], 'company', $who, $co);
  if ($bill) foreach (['billing address' => $bill['address'], 'billing city' => $bill['city'], 'billing province' => $bill['province'], 'billing postal' => $bill['postal_code']] as $field => $want)
    if (trim((string)$want) !== '' && !$has($whole, $norm($want), $norm)) $miss($out['clients']['misses'], $field, $who, $want);
  if ($ship) {
    $p = null; foreach ($props as $pr) if ($norm($pr['address'] ?? '') === $norm($ship['address'])) $p = $pr;
    if (!$p) { $sa = $db->prepare("SELECT site_contact_id FROM $D.properties WHERE LOWER(address) = ? LIMIT 1"); $sa->execute([$norm($ship['address'])]); $owner = $sa->fetchColumn(); $miss($out['clients']['misses'], $owner ? 'property (address already owned by another client)' : 'property (none)', $who, $ship['address']); }
    else foreach (['property city' => $ship['city'], 'property province' => $ship['province'], 'property postal' => $ship['postal_code']] as $field => $want)
      if (trim((string)$want) !== '' && !$has($p, $norm($want), $norm)) $miss($out['clients']['misses'], $field, $who, $want);
  }
}
foreach ($snap('item') as $i) {
  if (($i['Active'] ?? true) === false || in_array($i['Type'] ?? '', ['Category'], true)) continue;
  $name = trim((string)array_slice(explode(':', (string)$i['Name']), -1)[0]);
  $s = $db->prepare("SELECT * FROM $D.products WHERE LOWER(name) = ?"); $s->execute([strtolower($name)]); $p = $s->fetch();
  $out['products']['checked']++;
  if (!$p) { $miss($out['products']['misses'], 'missing', $name, $name); continue; }
  if (isset($i['UnitPrice']) && abs((float)$p['base_price'] - (float)$i['UnitPrice']) > 0.005) $miss($out['products']['misses'], 'price', $name, $i['UnitPrice']);
  $m = $db->prepare("SELECT qbo_id FROM $D.qbo_mappings WHERE kind = 'item' AND ittai_key = ?"); $m->execute([(string)$p['id']]);
  if ((string)$m->fetchColumn() !== (string)$i['Id']) $miss($out['products']['misses'], 'item link', $name, $i['Id']);
}
$vcols = $db->query("SHOW COLUMNS FROM $D.vendors")->fetchAll(PDO::FETCH_COLUMN);
$out['suppliers']['columns'] = array_values(array_filter($vcols, fn($c) => preg_match('/name|email|phone|addr/i', $c)));
$vnames = array_map($norm, array_merge(...array_map(fn($r) => array_values($r), $db->query("SELECT " . implode(',', array_filter($vcols, fn($c) => preg_match('/name/i', $c))) . " FROM $D.vendors")->fetchAll())));
foreach ($snap('vendor') as $v) {
  if (($v['Active'] ?? true) === false) continue;
  $out['suppliers']['checked']++;
  if (!in_array($norm($v['DisplayName'] ?? ''), $vnames, true)) $out['suppliers']['missing'][] = (string)($v['DisplayName'] ?? '');
}
$out['suppliers']['missing'] = array_slice($out['suppliers']['missing'], 0, 5);
$out['accounts_mapped'] = (int)$db->query("SELECT COUNT(*) FROM $D.qbo_mappings WHERE kind = 'account'")->fetchColumn();
$out['parts'] = $db->query("SELECT part, LEFT(summary, 90) s FROM $D.qbo_onboarding_parts")->fetchAll();
echo json_encode($out, JSON_PRETTY_PRINT);
