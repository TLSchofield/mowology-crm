<?php
/**
 * LOCAL TEST ONLY — seed the local test database with FAKE data.
 *
 *   php scripts/local/seed.php        (scripts/local-site.sh reset runs it for you)
 *
 * Refuses to run unless secrets.php is the generated LOCAL TEST ONLY file and DB_NAME is
 * mowology_local. Nothing here is real: names are invented, emails end in .test, phones are 555.
 *
 * 1. Reference rows (roles, permissions, chart of accounts, tax rates, service types …) are
 *    replayed from the INSERT statements in database/migrations/*.sql — the repo's own seeds,
 *    never rows from a production backup.
 * 2. Three logins (admin / manager / crew) with generated passwords, written to the gitignored
 *    scripts/local/.credentials.local.
 * 3. ~10 clients with companies/contacts/properties, quotes, job plans with visits this week,
 *    invoices (draft/sent/paid/overdue), receipts/expenses, one contract, supplier vendors.
 *
 * ins() fills any NOT NULL column without a default that the caller did not set (and points
 * NOT NULL foreign keys at an existing parent row), so schema drift does not break the seed.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Core/paths.php';
require_once APP_ROOT . '/Core/config.php';

if (!defined('LOCAL_TEST_SITE') || DB_NAME !== 'mowology_local') {
    fwrite(STDERR, "REFUSING: seed.php only runs against the LOCAL TEST ONLY database (mowology_local).\n");
    exit(1);
}

$db = getDB();
$db->exec('SET FOREIGN_KEY_CHECKS=0');

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
$colCache = [];
function cols(PDO $db, string $t): array {
    global $colCache;
    if (!isset($colCache[$t])) {
        $st = $db->prepare("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, COLUMN_TYPE, EXTRA
                            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                            ORDER BY ORDINAL_POSITION");
        $st->execute([$t]);
        $colCache[$t] = [];
        foreach ($st->fetchAll() as $c) $colCache[$t][$c['COLUMN_NAME']] = $c;
    }
    return $colCache[$t];
}
function fks(PDO $db, string $t): array {
    static $c = [];
    if (!isset($c[$t])) {
        $st = $db->prepare("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
        $st->execute([$t]);
        $c[$t] = [];
        foreach ($st->fetchAll() as $r) $c[$t][$r['COLUMN_NAME']] = [$r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME']];
    }
    return $c[$t];
}
function hasTable(PDO $db, string $t): bool { return cols($db, $t) !== []; }

/** Insert a row; unknown keys are dropped, required columns are filled. Returns the new id. */
function ins(PDO $db, string $t, array $data): int {
    $cols = cols($db, $t);
    if (!$cols) throw new RuntimeException("table $t missing");
    $data = array_intersect_key($data, $cols);
    $fk = fks($db, $t);
    foreach ($cols as $name => $c) {
        if (array_key_exists($name, $data)) continue;
        if ($c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null || strpos($c['EXTRA'], 'auto_increment') !== false
            || stripos($c['EXTRA'], 'DEFAULT_GENERATED') !== false || stripos($c['EXTRA'], 'GENERATED') !== false) continue;
        if (isset($fk[$name])) {
            [$rt, $rc] = $fk[$name];
            $v = $db->query("SELECT `$rc` FROM `$rt` ORDER BY `$rc` LIMIT 1")->fetchColumn();
            $data[$name] = $v !== false ? $v : 1;
            continue;
        }
        $data[$name] = fillValue($c);
    }
    $names = array_keys($data);
    $sql = "INSERT INTO `$t` (`" . implode('`,`', $names) . "`) VALUES (" . implode(',', array_fill(0, count($names), '?')) . ")";
    $db->prepare($sql)->execute(array_values($data));
    return (int)$db->lastInsertId();
}
function fillValue(array $c) {
    switch ($c['DATA_TYPE']) {
        case 'enum': case 'set':
            preg_match("/^(?:enum|set)\('((?:[^']|'')*)'/", $c['COLUMN_TYPE'], $m);
            return str_replace("''", "'", $m[1] ?? '');
        case 'int': case 'tinyint': case 'smallint': case 'mediumint': case 'bigint':
        case 'decimal': case 'float': case 'double': return 0;
        case 'date': return date('Y-m-d');
        case 'datetime': case 'timestamp': return date('Y-m-d H:i:s');
        case 'time': return '08:00:00';
        case 'year': return (int)date('Y');
        case 'json': return '{}';
        default: return 'local-test';
    }
}
function upd(PDO $db, string $t, int $id, array $data): void {
    $data = array_intersect_key($data, cols($db, $t));
    if (!$data) return;
    $set = implode(',', array_map(fn($k) => "`$k` = ?", array_keys($data)));
    $db->prepare("UPDATE `$t` SET $set WHERE id = ?")->execute([...array_values($data), $id]);
}
function say(string $s): void { echo $s, "\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// 1. Reference rows from the repo's migration seeds (never from a backup)
// ─────────────────────────────────────────────────────────────────────────────
$REFERENCE_TABLES = [
    'roles', 'permissions', 'role_permissions', 'chart_of_accounts', 'tax_rates', 'service_types', 'sites',
    'expense_category_accounts', 'service_revenue_accounts', 'business_settings', 'time_clock_settings',
    'lifecycle_stages', 'pipeline_stages', 'cost_types', 'unit_types', 'product_categories', 'data_retention_settings',
    'cms_block_types', 'cms_themes', 'contract_terms_templates', 'billing_templates', 'email_templates',
    'quiz_categories', 'cert_tiers', 'crew_badges', 'quiz_badges', 'social_time_slots', 'seo_seasons',
    'overhead_settings', 'token_dictionary', 'municipal_areas', 'municipal_rules', 'ops_settings', 'cost_factors',
    'transaction_rules', 'penny_receipt_exempt_rules', 'charlie_rules', 'mail_tidy_rules', 'inbound_route_rules',
    'service_packages', 'icon_sets', 'unit_types',
];
$REFERENCE_TABLES = array_flip($REFERENCE_TABLES);

/** Split a SQL file into statements, respecting quotes and comments. */
function splitSql(string $sql): array {
    $out = []; $cur = ''; $q = ''; $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($q !== '') {
            $cur .= $ch;
            if ($ch === '\\' && $q !== '`') { $cur .= $sql[++$i] ?? ''; continue; }
            if ($ch === $q) {
                if (($sql[$i + 1] ?? '') === $q) { $cur .= $sql[++$i]; continue; }
                $q = '';
            }
            continue;
        }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') { $j = strpos($sql, "\n", $i); $i = $j === false ? $len : $j; $cur .= "\n"; continue; }
        if ($ch === '#') { $j = strpos($sql, "\n", $i); $i = $j === false ? $len : $j; $cur .= "\n"; continue; }
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') { $j = strpos($sql, '*/', $i + 2); $i = $j === false ? $len : $j + 1; continue; }
        if ($ch === "'" || $ch === '"' || $ch === '`') $q = $ch;
        if ($ch === ';') { if (trim($cur) !== '') $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}

$files = glob($ROOT . '/database/migrations/*.sql');
usort($files, function ($a, $b) {
    $na = (int)preg_replace('/\D.*/', '', basename($a)); $nb = (int)preg_replace('/\D.*/', '', basename($b));
    return $na <=> $nb ?: strcmp($a, $b);
});
$refOk = 0; $refFail = 0; $refTables = [];
foreach ($files as $f) {
    if (preg_match('/^(problem|todo)-/', basename($f))) continue;
    foreach (splitSql((string)file_get_contents($f)) as $stmt) {
        if (!preg_match('/^(INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?/i', $stmt, $m)) continue;
        $t = strtolower($m[2]);
        if (!isset($REFERENCE_TABLES[$t])) continue;
        $stmt = preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $stmt);
        try { $db->exec($stmt); $refOk++; $refTables[$t] = true; } catch (Throwable $e) { $refFail++; }
    }
}
// Permissions seeded only by PHP runners (migrations 607, 1068) — same statements.
$db->exec("INSERT IGNORE INTO permissions (`key`, description) VALUES
  ('expenses.view','View expenses'),('expenses.edit','Create and edit expenses'),
  ('expenses.send','Forward expenses to accounting'),('expenses.approve','Approve or reject expenses'),
  ('jobs.create_field','Create a job or add a visit from the field')");
$db->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'admin'");
$db->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` IN ('expenses.view','expenses.edit','expenses.send','jobs.create_field') WHERE r.name = 'manager'");
$db->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` IN ('expenses.view','expenses.edit','jobs.create_field') WHERE r.name = 'staff'");
$db->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` = 'expenses.view' WHERE r.name = 'viewer'");
say("reference: $refOk statements replayed from migrations into " . count($refTables) . " tables ($refFail skipped)");

// Site / CMS identity — domain 'localhost' so SITE_URL never points at production.
$db->exec("INSERT IGNORE INTO sites (id, site_key, name, domain, status) VALUES (1, 'mowology', 'Mowology (LOCAL TEST)', 'localhost', 'active')");
if (hasTable($db, 'cms_sites')) {
    $db->exec("INSERT IGNORE INTO cms_sites (id, slug, domain, name, tagline, phone_display, phone_tel, email, locale, timezone, is_active, onboarding_complete)
               VALUES (1, 'mowology', 'localhost', 'Mowology (LOCAL TEST)', 'LOCAL TEST SITE', '555-0100', '5550100', 'office@example.test', 'en_CA', 'America/Vancouver', 1, 1)");
}
$db->exec("INSERT IGNORE INTO business_settings (id, company_name, company_phone, company_email, company_website, company_address, gst_registration)
           VALUES (1, 'Mowology (LOCAL TEST)', '555-0100', 'office@example.test', 'http://localhost:8000', '1 Test Street, Vancouver BC', '000000000RT0001')");
$db->exec("UPDATE business_settings SET company_name = 'Mowology (LOCAL TEST)', company_email = 'office@example.test', company_phone = '555-0100', autopay_live_mode = 0 WHERE id = 1");

// ─────────────────────────────────────────────────────────────────────────────
// 2. Logins
// ─────────────────────────────────────────────────────────────────────────────
$pw = fn() => rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=') . '-9a';
$people = [
    'admin'   => ['email' => 'admin@local.test',   'username' => 'localadmin',   'first' => 'Ada',  'last' => 'Admin',   'role' => 'admin',   'rbac' => 'admin',   'driver' => 0],
    'manager' => ['email' => 'manager@local.test', 'username' => 'localmanager', 'first' => 'Max',  'last' => 'Manager', 'role' => 'manager', 'rbac' => 'manager', 'driver' => 0],
    'crew'    => ['email' => 'crew@local.test',    'username' => 'localcrew',    'first' => 'Casey','last' => 'Crew',    'role' => 'user',    'rbac' => 'staff',   'driver' => 1],
    // The outside accountant (migration 1355, AccountantFence): the Ledger only.
    'accountant' => ['email' => 'accountant@local.test', 'username' => 'localaccountant', 'first' => 'Andy', 'last' => 'Accountant', 'role' => 'accountant', 'rbac' => 'accountant', 'driver' => 0],
];
$userIds = []; $creds = [];
foreach ($people as $key => $p) {
    $plain = $pw();
    $userIds[$key] = ins($db, 'users', [
        'email' => $p['email'], 'username' => $p['username'], 'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
        'full_name' => $p['first'] . ' ' . $p['last'], 'first_name' => $p['first'], 'last_name' => $p['last'],
        'phone' => '555-0101', 'role' => $p['role'], 'is_active' => 1, 'is_driver' => $p['driver'], 'hourly_rate' => 30,
        'calendar_color' => $key === 'crew' ? '#2D8659' : '#1565C0', 'location_tracking_enabled' => 0,
    ]);
    $roleId = $db->query("SELECT id FROM roles WHERE name = " . $db->quote($p['rbac']))->fetchColumn();
    if ($roleId) $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$userIds[$key], $roleId]);
    $creds[$key] = [$p['email'], $plain];
}
$adminId = $userIds['admin']; $crewId = $userIds['crew'];

$credFile = __DIR__ . '/.credentials.local';
$txt = "# LOCAL TEST ONLY — logins for http://localhost:8000 (regenerated by every `scripts/local-site.sh reset`).\n"
     . "# Gitignored. Fake accounts on a fake database; never reuse these anywhere.\n"
     . "# format: ROLE EMAIL PASSWORD\n";
foreach ($creds as $role => [$email, $plain]) $txt .= sprintf("%-8s %-22s %s\n", $role, $email, $plain);
file_put_contents($credFile, $txt);
chmod($credFile, 0600);
say("users: admin, manager, crew, accountant (passwords in scripts/local/.credentials.local)");

// ─────────────────────────────────────────────────────────────────────────────
// 3. Business data (all fake)
// ─────────────────────────────────────────────────────────────────────────────
$today  = new DateTimeImmutable('today');
$monday = $today->modify('monday this week');
$year   = $today->format('Y');

// Products
$catId  = (int)($db->query("SELECT id FROM product_categories ORDER BY id LIMIT 1")->fetchColumn() ?: ins($db, 'product_categories', ['name' => 'Maintenance']));
$unitId = (int)($db->query("SELECT id FROM unit_types ORDER BY id LIMIT 1")->fetchColumn() ?: ins($db, 'unit_types', ['name' => 'Visit', 'abbreviation' => 'visit']));
$products = [];
foreach ([['Lawn Mowing', 'lawn_care', 55], ['Hedge Trimming', 'hedge_trimming', 120], ['Spring Cleanup', 'cleanup', 240], ['Snow Clearing', 'snow_removal', 90]] as [$n, $st, $price]) {
    $products[$st] = ins($db, 'products', ['category_id' => $catId, 'unit_type_id' => $unitId, 'name' => $n, 'sku' => 'LOC-' . strtoupper(substr($st, 0, 4)),
        'base_price' => $price, 'base_cost' => round($price * 0.4, 2), 'service_type' => $st, 'active' => 1, 'description' => "$n (local test product)"]);
}

$clientsSpec = [
    ['Alder Test Residence',      'individual',       'Alice',  'Alder',   '101 Fake Ave'],
    ['Birch Test Holdings Ltd',   'business',         'Bob',    'Birch',   '202 Sample Rd'],
    ['Cedar Court Strata (Test)', 'strata',           'Carol',  'Cedar',   '303 Example St'],
    ['Dogwood Test Home',         'individual',       'Dan',    'Dogwood', '404 Placeholder Pl'],
    ['Elm Test Property Mgmt',    'property_manager', 'Erin',   'Elm',     '505 Demo Dr'],
    ['Fir Test Residence',        'individual',       'Frank',  'Fir',     '606 Mock Blvd'],
    ['Garry Oak Test Cafe',       'business',         'Grace',  'Oak',     '707 Trial Way'],
    ['Hemlock Test Residence',    'individual',       'Hank',   'Hemlock', '808 Dummy Cres'],
    ['Ironwood Test Strata',      'strata',           'Ivy',    'Ironwood','909 Pretend Ln'],
    ['Juniper Test Residence',    'individual',       'Jack',   'Juniper', '1010 Faux Ct'],
    ['Delete-Me Test Client',     'individual',       'Zed',    'Delete',  '9999 Nowhere Rd'],  // role-gate target
];
$C = [];
foreach ($clientsSpec as $i => [$name, $type, $first, $last, $addr]) {
    $n = $i + 1;
    $email = strtolower($first) . '.' . strtolower($last) . '@example.test';
    $phone = sprintf('604-555-%04d', 100 + $n);
    $companyId = ins($db, 'companies', ['company_name' => $name, 'company_type' => $type, 'billing_address' => $addr, 'billing_city' => 'Vancouver',
        'billing_province' => 'BC', 'billing_postal_code' => 'V5V 0A' . ($n % 10), 'billing_email' => $email, 'billing_phone' => $phone,
        'account_status' => 'active', 'lifecycle_stage' => 'client', 'notes' => 'LOCAL TEST — fake company']);
    $contactId = ins($db, 'contacts', ['first_name' => $first, 'last_name' => $last, 'company_id' => $companyId, 'email' => $email,
        'phone' => $phone, 'mobile' => $phone, 'preferred_contact_method' => 'email', 'is_active' => 1, 'prospect_status' => 'client',
        'contact_role' => 'owner', 'lifecycle_stage' => 'client', 'portal_token' => bin2hex(random_bytes(16)),
        'consent_quote_followup' => 1, 'consent_timestamp' => date('Y-m-d H:i:s'), 'consent_source' => 'local_seed']);
    upd($db, 'companies', $companyId, ['primary_contact_id' => $contactId, 'billing_contact_id' => $contactId, 'quote_contact_id' => $contactId]);
    $clientId = ins($db, 'clients', ['site_id' => 1, 'client_type' => $type, 'display_name' => $name, 'billing_address' => $addr,
        'billing_city' => 'Vancouver', 'billing_province' => 'BC', 'billing_postal_code' => 'V5V 0A' . ($n % 10), 'billing_email' => $email,
        'billing_phone' => $phone, 'lifecycle_stage' => 'client', 'status' => 'active', 'legacy_company_id' => $companyId, 'legacy_contact_id' => $contactId]);
    ins($db, 'client_contacts', ['client_id' => $clientId, 'contact_id' => $contactId, 'role' => 'owner', 'is_primary' => 1, 'receives_invoices' => 1, 'receives_notifications' => 1]);
    $propertyId = ins($db, 'properties', ['property_name' => $name, 'property_type' => $type === 'strata' ? 'strata' : ($type === 'individual' ? 'single_family' : 'commercial'),
        'address' => $addr, 'city' => 'Vancouver', 'province' => 'BC', 'postal_code' => 'V5V 0A' . ($n % 10),
        'latitude' => 49.25 + $n * 0.004, 'longitude' => -123.10 - $n * 0.004, 'client_id' => $clientId, 'company_id' => $companyId,
        'site_contact_id' => $contactId, 'lawn_size_sqft' => 1500 + 250 * $n, 'status' => 'active', 'notes' => 'LOCAL TEST property']);
    ins($db, 'property_contacts', ['property_id' => $propertyId, 'contact_id' => $contactId, 'contact_role' => 'owner', 'is_primary' => 1]);
    ins($db, 'company_properties', ['company_id' => $companyId, 'property_id' => $propertyId, 'relationship_type' => 'owner', 'is_primary' => 1]);
    $C[$n] = ['client' => $clientId, 'company' => $companyId, 'contact' => $contactId, 'property' => $propertyId, 'name' => $name, 'email' => $email, 'addr' => $addr];
}
say('clients: ' . count($C) . ' (companies, contacts, properties linked)');

// Quotes
$quoteStatuses = [1 => 'accepted', 2 => 'sent', 3 => 'draft', 4 => 'declined', 5 => 'viewed'];
$Q = [];
$qn = 0;
foreach ($quoteStatuses as $ci => $status) {
    $qn++;
    $lines = [['lawn_care', 'Weekly lawn mowing — season', 20, 55], ['hedge_trimming', 'Hedge trimming (twice a season)', 2, 120]];
    $sub = array_sum(array_map(fn($l) => $l[2] * $l[3], $lines));
    $tax = round($sub * 0.05, 2);
    $qid = ins($db, 'quotes', ['property_id' => $C[$ci]['property'], 'company_id' => $C[$ci]['company'], 'client_id' => $C[$ci]['client'],
        'contact_id' => $C[$ci]['contact'], 'quote_number' => sprintf('QUO-%s-%04d', $year, $qn), 'title' => 'Season maintenance (local test)',
        'service_type' => 'lawn_care', 'service_types' => 'landscaping', 'quote_date' => $today->modify('-' . (20 - $qn) . ' days')->format('Y-m-d'),
        'expiry_date' => $today->modify('+30 days')->format('Y-m-d'), 'valid_until' => $today->modify('+30 days')->format('Y-m-d'),
        'subtotal' => $sub, 'amount' => $sub, 'tax_rate' => 0.05, 'tax_amount' => $tax, 'total_amount' => $sub + $tax, 'status' => $status,
        'description' => 'Fake quote for local testing.', 'access_token' => bin2hex(random_bytes(24)), 'token_expires_at' => $today->modify('+60 days')->format('Y-m-d H:i:s'),
        'sent_at' => $status === 'draft' ? null : $today->modify('-10 days')->format('Y-m-d H:i:s'), 'created_by' => $adminId,
        'accepted_at' => $status === 'accepted' ? $today->modify('-7 days')->format('Y-m-d H:i:s') : null,
        'accepted_by_name' => $status === 'accepted' ? 'Alice Alder' : null, 'pipeline_stage' => $status, 'recipient_chosen' => 1]);
    foreach ($lines as $k => [$st, $desc, $qty, $price]) {
        $qli = ins($db, 'quote_line_items', ['quote_id' => $qid, 'product_id' => $products[$st], 'service_type' => $st, 'description' => $desc,
            'quantity' => $qty, 'unit_type' => 'visit', 'unit_price' => $price, 'line_total' => $qty * $price, 'sort_order' => $k]);
        ins($db, 'quote_items', ['quote_id' => $qid, 'product_id' => $products[$st], 'item_name' => $desc, 'description' => $desc, 'quantity' => $qty,
            'unit_price' => $price, 'line_subtotal' => $qty * $price, 'line_tax' => round($qty * $price * 0.05, 2), 'line_total' => round($qty * $price * 1.05, 2), 'display_order' => $k]);
    }
    $Q[$ci] = $qid;
}
say('quotes: ' . count($Q));

// Contract (client 1, from the accepted quote)
$contractId = ins($db, 'contracts', ['contract_number' => sprintf('CON-%s-0001', $year), 'property_id' => $C[1]['property'], 'contact_id' => $C[1]['contact'],
    'client_id' => $C[1]['client'], 'quote_id' => $Q[1], 'title' => 'Alder Test — season maintenance contract', 'status' => 'active',
    'signature_status' => 'signed', 'current_version' => 1, 'billing_cycle' => 'monthly', 'billing_amount' => 240.00, 'invoice_timing' => 'end_of_month',
    'start_date' => $today->modify('first day of january this year')->format('Y-m-d'), 'end_date' => $today->modify('last day of december this year')->format('Y-m-d'),
    'renewal_date' => $today->modify('last day of december this year')->format('Y-m-d'), 'auto_renew' => 1, 'notes' => 'LOCAL TEST contract', 'created_by' => $adminId]);
ins($db, 'contract_versions', ['contract_id' => $contractId, 'version_number' => 1, 'changed_by' => $adminId, 'change_reason' => 'Created (local seed)',
    'title' => 'Alder Test — season maintenance contract', 'billing_cycle' => 'monthly', 'billing_amount' => 240.00, 'invoice_timing' => 'end_of_month',
    'start_date' => $today->modify('first day of january this year')->format('Y-m-d'), 'signature_status' => 'signed',
    'terms_body' => 'Fake terms for local testing.', 'signed_at' => $today->modify('-30 days')->format('Y-m-d H:i:s'), 'signed_by_name' => 'Alice Alder']);
say("contract: CON-$year-0001");

// Job plans + visits this week
$planSpecs = [
    [1, 'lawn_care',      'Weekly mowing — Alder',     55,  true,  $contractId, $Q[1]],
    [2, 'maintenance',    'Grounds care — Birch',      180, true,  null, null],
    [3, 'lawn_care',      'Strata lawns — Cedar Court',260, true,  null, null],
    [4, 'hedge_trimming', 'Hedge trim — Dogwood',      120, false, null, null],
    [5, 'cleanup',        'Fall cleanup — Elm PM',     240, false, null, null],
    [6, 'lawn_care',      'Biweekly mowing — Fir',     55,  true,  null, null],
];
$V = []; $P = []; $pn = 0; $visitN = 0;
foreach ($planSpecs as [$ci, $st, $title, $price, $recurring, $ctr, $quote]) {
    $pn++;
    $planNo = sprintf('PLN-%s-%04d', $year, $pn);
    $planId = ins($db, 'job_plans', ['plan_number' => $planNo, 'quote_id' => $quote, 'contract_id' => $ctr, 'property_id' => $C[$ci]['property'],
        'company_id' => $C[$ci]['company'], 'client_id' => $C[$ci]['client'], 'title' => $title . ' (local test)', 'description' => 'Fake plan',
        'service_type' => $st, 'pricing_model' => 'per_visit', 'invoice_timing' => 'after_visit', 'price_per_visit' => $price, 'estimated_amount' => $price,
        'is_recurring' => $recurring ? 1 : 0, 'recurrence_pattern' => $recurring ? 'weekly' : null, 'recurrence_interval' => 1,
        'plan_start_date' => $monday->modify('-14 days')->format('Y-m-d'), 'plan_end_date' => $recurring ? $monday->modify('+120 days')->format('Y-m-d') : null,
        'default_crew_id' => $crewId, 'default_crew_size' => 1, 'estimated_duration_minutes' => 60, 'default_time_start' => '08:00:00',
        'status' => 'active', 'created_by' => $adminId, 'visits_generated_through' => $monday->modify('+6 days')->format('Y-m-d')]);
    ins($db, 'plan_line_items', ['plan_id' => $planId, 'product_id' => $products[$st] ?? null, 'service_type' => $st, 'description' => $title,
        'quantity' => 1, 'unit_type' => 'visit', 'unit_price' => $price, 'line_total' => $price]);
    ins($db, 'plan_crew_assignments', ['plan_id' => $planId, 'user_id' => $crewId, 'role' => 'lead']);
    $P[$ci] = $planId;

    $days = $recurring ? [0, 2, 4] : [($pn % 5)];
    foreach ($days as $k => $d) {
        $visitN++;
        $date = $monday->modify("+$d days");
        $past = $date < $today;
        $status = $past ? 'completed' : ($date == $today && $k === 0 ? 'in_progress' : 'scheduled');
        $stopId = ins($db, 'calendar_stops', ['property_id' => $C[$ci]['property'], 'crew_id' => $crewId, 'stop_date' => $date->format('Y-m-d'),
            'route_order' => $pn, 'estimated_arrival' => sprintf('%02d:00:00', 7 + $pn), 'estimated_departure' => sprintf('%02d:00:00', 8 + $pn),
            'status' => $status === 'in_progress' ? 'in_progress' : $status, 'notes' => 'LOCAL TEST stop']);
        ins($db, 'calendar_stop_crew', ['stop_id' => $stopId, 'user_id' => $crewId]);
        $vid = ins($db, 'job_visits', ['visit_number' => sprintf('%s-V%03d', $planNo, $k + 1), 'plan_id' => $planId, 'stop_id' => $stopId,
            'scheduled_date' => $date->format('Y-m-d'), 'scheduled_time_start' => sprintf('%02d:00:00', 7 + $pn), 'scheduled_time_end' => sprintf('%02d:00:00', 8 + $pn),
            'sequence_index' => $k + 1, 'assigned_crew_id' => $crewId, 'status' => $status, 'service_type' => $st,
            'started_at' => $status !== 'scheduled' ? $date->format('Y-m-d') . ' 09:00:00' : null,
            'completed_at' => $status === 'completed' ? $date->format('Y-m-d') . ' 10:00:00' : null,
            'actual_duration_minutes' => $status === 'completed' ? 60 : null, 'actual_amount' => $status === 'completed' ? $price : null,
            'completion_notes' => $status === 'completed' ? 'Done (local test)' : null]);
        ins($db, 'visit_crew_assignments', ['visit_id' => $vid, 'user_id' => $crewId, 'role' => 'lead']);
        $V[] = ['id' => $vid, 'ci' => $ci, 'date' => $date, 'status' => $status, 'price' => $price, 'title' => $title];
    }
}
say('job plans: ' . count($P) . ', visits this week: ' . count($V));

// Invoices — draft / sent / paid / overdue / partial
$invSpecs = [
    [1, 'paid',    -40, -10, 55 * 4],
    [2, 'sent',    -5,  25,  180],
    [3, 'overdue', -60, -30, 520],
    [4, 'draft',   0,   30,  120],
    [5, 'partial', -15, 15,  480],
    [6, 'sent',    -2,  28,  110],
];
$I = []; $in = 0;
foreach ($invSpecs as [$ci, $status, $issued, $dueIn, $sub]) {
    $in++;
    $tax = round($sub * 0.05, 2); $total = $sub + $tax;
    $paid = $status === 'paid' ? $total : ($status === 'partial' ? round($total / 2, 2) : 0);
    $iid = ins($db, 'invoices', ['company_id' => $C[$ci]['company'], 'client_id' => $C[$ci]['client'], 'contact_id' => $C[$ci]['contact'],
        'property_id' => $C[$ci]['property'], 'plan_id' => $P[$ci] ?? null, 'contract_id' => $ci === 1 ? $contractId : null,
        'invoice_number' => sprintf('INV-%s-%04d', $year, $in), 'invoice_date' => $today->modify("$issued days")->format('Y-m-d'),
        'issue_date' => $today->modify("$issued days")->format('Y-m-d'), 'due_date' => $today->modify("$dueIn days")->format('Y-m-d'),
        'subtotal' => $sub, 'tax_amount' => $tax, 'tax_rate' => 0.05, 'total_amount' => $total, 'total' => $total, 'amount_paid' => $paid,
        'balance_due' => $total - $paid, 'status' => $status, 'payment_method' => $paid > 0 ? 'e_transfer' : null,
        'payment_date' => $paid > 0 ? $today->modify('-5 days')->format('Y-m-d') : null, 'paid_at' => $status === 'paid' ? $today->modify('-5 days')->format('Y-m-d H:i:s') : null,
        'sent_to_email' => $status === 'draft' ? null : $C[$ci]['email'], 'sent_at' => $status === 'draft' ? null : $today->modify("$issued days")->format('Y-m-d H:i:s'),
        'sent_date' => $status === 'draft' ? null : $today->modify("$issued days")->format('Y-m-d H:i:s'),
        'bill_to_name' => $C[$ci]['name'], 'service_address' => $C[$ci]['addr'], 'service_city' => 'Vancouver', 'service_province' => 'BC',
        'billing_address' => $C[$ci]['addr'], 'billing_city' => 'Vancouver', 'billing_province' => 'BC',
        'access_token' => bin2hex(random_bytes(32)), 'token_expires_at' => $today->modify('+90 days')->format('Y-m-d H:i:s'),
        'notes' => 'LOCAL TEST invoice', 'created_by' => $adminId]);
    ins($db, 'invoice_line_items', ['invoice_id' => $iid, 'title' => 'Landscape maintenance', 'description' => 'Services rendered (local test)',
        'quantity' => 1, 'unit_price' => $sub, 'line_total' => $sub, 'service_date' => $today->modify("$issued days")->format('Y-m-d')]);
    ins($db, 'invoice_items', ['invoice_id' => $iid, 'property_id' => $C[$ci]['property'], 'description' => 'Services rendered (local test)',
        'quantity' => 1, 'unit_price' => $sub, 'amount' => $sub, 'service_date' => $today->modify("$issued days")->format('Y-m-d')]);
    if (hasTable($db, 'invoice_contacts')) {
        try { ins($db, 'invoice_contacts', ['invoice_id' => $iid, 'contact_id' => $C[$ci]['contact']]); } catch (Throwable $e) {}
    }
    $I[$status][] = $iid;
}
// Mark one completed visit as invoiced on the paid invoice.
foreach ($V as $v) if ($v['status'] === 'completed' && $v['ci'] === 1) { upd($db, 'job_visits', $v['id'], ['is_invoiced' => 1, 'invoice_id' => $I['paid'][0]]); break; }
say('invoices: ' . $in . ' (draft/sent/paid/overdue/partial)');

// Vendors + receipts/expenses
$vendorIds = [];
foreach (['Test Garden Supply Co', 'Fake Fuel Station', 'Sample Hardware Ltd'] as $vn) {
    $vendorIds[] = ins($db, 'vendors', ['name' => $vn, 'default_accounting_category' => 'Materials & Supplies', 'is_active' => 1, 'notes' => 'LOCAL TEST vendor']);
}
$expSpecs = [
    [0, 'Mulch and soil (local test)', 84.00, 'approved', $adminId],
    [1, 'Diesel (local test)',         62.50, 'pending_approval', $crewId],
    [2, 'Trimmer line (local test)',   23.99, 'draft', $crewId],
    [0, 'Fertilizer (local test)',     140.00, 'approved', $userIds['manager']],
];
foreach ($expSpecs as $k => [$vi, $desc, $amt, $status, $by]) {
    $gst = round($amt * 0.05, 2);
    ins($db, 'expenses', ['expense_date' => $today->modify('-' . ($k * 3 + 1) . ' days')->format('Y-m-d'), 'vendor_id' => $vendorIds[$vi],
        'vendor_name_raw' => strtoupper('test vendor ' . $vi), 'description' => $desc, 'amount' => $amt, 'gst_amount' => $gst, 'total' => $amt + $gst,
        'accounting_category' => 'Materials & Supplies', 'payment_method' => 'credit_card', 'status' => $status, 'created_by' => $by,
        'approved_by' => $status === 'approved' ? $adminId : null, 'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        'source' => 'local_seed', 'idempotency_key' => sprintf('00000000-0000-4000-8000-%012d', $k + 1)]);
}
say('expenses: ' . count($expSpecs) . ' (draft / pending_approval / approved), vendors: ' . count($vendorIds));

// Journal for the Ledger (Accountant's Ledger, 2026-10-13): invoices, receipts, a manual entry, a locked
// December 2025 with one entry from before its lock, a reversal of it after the lock, and a late entry.
try {
    require_once APP_ROOT . '/Modules/Accounting/Services/LedgerService.php';
    $L = new LedgerService($db);
    $acc = fn(string $code) => (int)$db->query("SELECT id FROM chart_of_accounts WHERE code = " . $db->quote($code))->fetchColumn();
    $db->exec("INSERT IGNORE INTO accounting_periods (year, month, label, status, closed_at, closed_by, notes)
               VALUES (2025, 12, 'December 2025', 'locked', '2026-01-15 09:00:00', $adminId, 'LOCAL TEST lock')");
    foreach ($db->query("SELECT id, invoice_number, COALESCE(issue_date, invoice_date) d, subtotal, tax_amount FROM invoices WHERE status <> 'draft'")->fetchAll() as $iv) {
        $L->postEntry(['entry_date' => $iv['d'], 'memo' => 'Invoice ' . $iv['invoice_number'], 'source_type' => 'invoice', 'source_id' => (int)$iv['id'], 'lines' => [
            ['account_id' => $acc('1100'), 'debit' => (float)$iv['subtotal'] + (float)$iv['tax_amount'], 'credit' => 0],
            ['account_id' => $acc('4900'), 'debit' => 0, 'credit' => (float)$iv['subtotal'], 'description' => 'Services rendered (local test)'],
            ['account_id' => $acc('2200'), 'debit' => 0, 'credit' => (float)$iv['tax_amount']]]]);
    }
    foreach ($db->query("SELECT id, expense_date, description, amount, gst_amount FROM expenses WHERE status = 'approved'")->fetchAll() as $ex) {
        $L->postEntry(['entry_date' => $ex['expense_date'], 'memo' => 'Expense #' . $ex['id'], 'source_type' => 'expense', 'source_id' => (int)$ex['id'], 'lines' => [
            ['account_id' => $acc('5200'), 'debit' => (float)$ex['amount'], 'credit' => 0, 'description' => $ex['description']],
            ['account_id' => $acc('2210'), 'debit' => (float)$ex['gst_amount'], 'credit' => 0],
            ['account_id' => $acc('2400'), 'debit' => 0, 'credit' => (float)$ex['amount'] + (float)$ex['gst_amount']]]]);
    }
    $L->postEntry(['entry_date' => $today->modify('-3 days')->format('Y-m-d'), 'memo' => 'Owner moved $500 from savings (local test)', 'source_type' => 'manual',
                   'created_by' => $adminId, 'proposed_by' => 'owner', 'lines' => [
        ['account_id' => $acc('1010'), 'debit' => 500, 'credit' => 0], ['account_id' => $acc('1020'), 'debit' => 0, 'credit' => 500]]]);
    // Posted in December 2025 BEFORE the lock (as the real FY2025 history was).
    $db->exec("INSERT INTO journal_entries (entry_date, memo, source_type, source_id, status, entry_kind, created_at)
               VALUES ('2025-12-20', 'December fuel (local test, before the lock)', 'manual', NULL, 'posted', 'manual', '2026-01-02 10:00:00')");
    $decId = (int)$db->lastInsertId();
    $db->exec("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES ($decId, {$acc('6100')}, 75, 0), ($decId, {$acc('1010')}, 0, 75)");
    $L->reverseEntry($decId, $adminId, 'duplicate of a card line (local test)');                         // → exception: reversed after the lock
    $L->postEntry(['entry_date' => '2025-12-30', 'memo' => 'Deposit found late (local test)', 'source_type' => 'bank_deposit', 'source_id' => 999001,
                   'created_by' => $adminId, 'lines' => [                                               // → moved to today as a late entry
        ['account_id' => $acc('1010'), 'debit' => 120, 'credit' => 0], ['account_id' => $acc('1100'), 'debit' => 0, 'credit' => 120]]]);
    say('journal: ' . (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() . ' entries (incl. a locked-period reversal and a late entry)');
} catch (Throwable $e) {
    say('journal: skipped — ' . $e->getMessage());
}

$db->exec('SET FOREIGN_KEY_CHECKS=1');

// Portal link for the smoke test (fake token on a fake invoice).
$tok = $db->query("SELECT access_token FROM invoices WHERE status = 'sent' ORDER BY id LIMIT 1")->fetchColumn();
file_put_contents(__DIR__ . '/.credentials.local', "\n# Customer-portal invoice link (fake): /customer/invoice.php?token=$tok\n", FILE_APPEND);
say('seed complete.');
