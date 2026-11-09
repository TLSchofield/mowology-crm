<?php
/**
 * Mia workspace UI harness (dev tool, never deployed).
 *
 * Serves the REAL page (public/crm/marketing/mia.php), the REAL script (public/crm/js/mia-workspace.js), the REAL CSS
 * and the REAL API endpoint (app/Modules/Marketing/Api/mia.php) on a seeded SQLite database, with the login, permission
 * and CSRF functions stubbed. Lets the page be looked at in a browser at phone and desktop widths without MySQL.
 *
 *   php tests/tools/mia_ui_harness.php [port]        # builds /tmp tree + db, starts php -S, prints the URL
 *   Open http://127.0.0.1:<port>/crm/marketing/mia.php
 *   Stop with Ctrl-C. Add ?reset=1 to re-seed.
 */
declare(strict_types=1);

$repo = realpath(__DIR__ . '/../..');
$port = (int)($argv[1] ?? 8765);
$root = sys_get_temp_dir() . '/mia_ui_harness';
exec('rm -rf ' . escapeshellarg($root));
foreach (['app/Core', 'app/Modules/Marketing/Api', 'public/crm/marketing', 'public/crm/includes', 'public/crm/api', 'public/loginAuth'] as $d) { mkdir("$root/$d", 0777, true); }
copy("$repo/app/Core/paths.php", "$root/app/Core/paths.php");
copy("$repo/app/Modules/Marketing/Api/mia.php", "$root/app/Modules/Marketing/Api/mia.php");
symlink("$repo/app/Services", "$root/app/Services");
symlink("$repo/app/Modules/Marketing/Services", "$root/app/Modules/Marketing/Services");
symlink("$repo/public/crm/css", "$root/public/crm/css");
symlink("$repo/public/crm/js", "$root/public/crm/js");
copy("$repo/public/crm/marketing/mia.php", "$root/public/crm/marketing/mia.php");
file_put_contents("$root/public/crm/api/mia.php", "<?php\nrequire dirname(__DIR__, 3) . '/app/Modules/Marketing/Api/mia.php';\n");
file_put_contents("$root/public/crm/includes/functions.php", "<?php\nfunction _av(string \$p): string { return \$p . '?v=' . time(); }\n");
file_put_contents("$root/public/crm/includes/appstack_head.php", <<<'PHP'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? '') ?></title>
<link href="/crm/css/classic.css" rel="stylesheet"><link href="/crm/css/tokens.css" rel="stylesheet"><link href="/crm/css/mowology-brand.css" rel="stylesheet">
<script>window.MW_CSRF_TOKEN = 'good-token';</script></head><body><div class="wrapper"><div class="main"><main class="content"><div class="container-fluid p-0">
PHP);
file_put_contents("$root/public/crm/includes/appstack_footer.php", "</div></main></div></div></body></html>\n");
file_put_contents("$root/public/loginAuth/auth.php", <<<'PHP'
<?php
function getDB(): PDO { static $db = null; if ($db === null) { $db = new PDO('sqlite:' . getenv('MIA_TEST_DB')); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); } return $db; }
function requireLogin(): void {}
function requirePermission(string $p): void {}
function getCurrentUser(): array { return ['id' => 7, 'first_name' => 'Tim']; }
function verifyCSRFToken($t): bool { return $t === 'good-token'; }
PHP);
file_put_contents("$root/router.php", "<?php\n\$p = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif (strpos(\$p, '/crm/api/mia.php') === 0) { require " . var_export("$root/public/crm/api/mia.php", true) . "; return true; }\nif (\$p === '/crm/marketing/mia.php') { require " . var_export("$root/public/crm/marketing/mia.php", true) . "; return true; }\nreturn false;\n");

// ---- seeded database --------------------------------------------------------------------------------
$db = new PDO('sqlite:' . "$root/test.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = [
 "CREATE TABLE users (id INTEGER PRIMARY KEY)", "INSERT INTO users VALUES (7)",
 "CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT, mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none', capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT, verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200, robots_hash TEXT, created_by INT, created_at TEXT, UNIQUE(site_id, host))",
 "CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, opportunity_key TEXT, type TEXT, side_effect TEXT, status TEXT NOT NULL DEFAULT 'proposed', title TEXT, why TEXT, expected_benefit TEXT, risk TEXT, evidence TEXT, score REAL, score_inputs TEXT, evidence_hash TEXT, draft_ref_table TEXT, draft_ref_id INT, draft_hash TEXT, preview_url TEXT, autonomy_level INT DEFAULT 1, proposed_by TEXT DEFAULT 'mia', proposed_at TEXT, decided_by INT, decided_at TEXT, decision_note TEXT, executed_at TEXT, result TEXT, created_at TEXT)",
 "CREATE TABLE mia_action_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, action_id INT, event TEXT, actor TEXT, detail TEXT, created_at TEXT)",
 "CREATE TABLE mia_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, subject_key TEXT, action_type TEXT, evidence_hash TEXT, decision TEXT, reason_code TEXT, reason_text TEXT, decided_by INT, decided_at TEXT, suppress_until TEXT)",
 "CREATE TABLE mia_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, mem_key TEXT, mem_value TEXT, source TEXT DEFAULT 'owner', updated_by INT, updated_at TEXT)",
 "CREATE TABLE mia_content_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, action_id INT, kind TEXT, page_url TEXT, current_title TEXT, current_desc TEXT, title TEXT, meta_desc TEXT, rationale TEXT, input_hash TEXT, status TEXT DEFAULT 'draft', version INT DEFAULT 1, created_at TEXT)",
 "CREATE TABLE mia_site_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, url_hash TEXT, url TEXT, status_code INT)",
 "CREATE TABLE mia_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, kind TEXT, status TEXT, pages INT, findings INT, started_at TEXT, finished_at TEXT, summary TEXT)",
 "CREATE TABLE mia_audit_findings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, audit_id INT, page_id INT, rule_key TEXT, severity TEXT, evidence TEXT, fingerprint TEXT, first_seen_audit_id INT, resolved_at TEXT)",
 "CREATE TABLE gsc_properties (id INTEGER PRIMARY KEY AUTOINCREMENT, site_url TEXT)", "CREATE TABLE gsc_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INT, snapshot_date TEXT)",
 "INSERT INTO mia_websites (site_id, base_url, host, status, verify_token, verified_via, verified_at) VALUES (1, 'https://mowology.ca', 'mowology.ca', 'verified', 'aabbccddeeff00112233445566778899', 'dns', '" . date('Y-m-d H:i:s') . "')",
 "INSERT INTO mia_websites (site_id, base_url, host, status, verify_token) VALUES (1, 'https://new-site.example', 'new-site.example', 'pending', '00112233445566778899aabbccddeeff')",
 "INSERT INTO mia_audits (site_id, website_id, kind, status, started_at, finished_at, summary) VALUES (1, 1, 'crawl', 'done', '" . date('Y-m-d 05:30:00') . "', '" . date('Y-m-d 05:34:00') . "', '24 pages fetched')",
 "INSERT INTO gsc_properties (site_url) VALUES ('sc-domain:mowology.ca')", "INSERT INTO gsc_snapshots (property_id, snapshot_date) VALUES (1, '" . date('Y-m-d', strtotime('-2 days')) . "')",
];
foreach ($sql as $q) { $db->exec($q); }
for ($i = 1; $i <= 24; $i++) { $db->exec("INSERT INTO mia_site_pages (site_id, website_id, url_hash, url, status_code) VALUES (1, 1, 'h$i', 'u$i', 200)"); }
foreach ([['title_missing', 'high', 2], ['broken_page', 'high', 1], ['meta_missing', 'medium', 5], ['images_missing_alt', 'medium', 3], ['title_long', 'low', 4]] as $f) {
    for ($i = 0; $i < $f[2]; $i++) { $db->exec("INSERT INTO mia_audit_findings (site_id, website_id, rule_key, severity, evidence, fingerprint) VALUES (1, 1, '{$f[0]}', '{$f[1]}', 'e', '{$f[0]}$i')"); }
}
$ins = $db->prepare("INSERT INTO mia_actions (site_id, opportunity_key, type, side_effect, status, title, why, expected_benefit, risk, evidence, score, proposed_at, decided_at, decision_note) VALUES (1, ?, ?, 'none', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$ins->execute(['page:abc', 'seo_meta_update', 'proposed', 'Rewrite the title and description of /services/strata-landscaping',
    'People saw this page 800 times in the last 25 days (about position 4.0) but only 8 clicked (1.0%). Pages at that position typically get around 7.0%. A clearer title and description may help more of them click.',
    'Estimate: roughly 20 to 60 extra visits a month from a better title and description, if click rates move toward typical levels. This is an estimate, not a promise.',
    'Changing the title and description changes what Google shows for this page. Google may also rewrite them. Results can take weeks to show and are an estimate.',
    json_encode([['page' => 'https://mowology.ca/services/strata-landscaping', 'impressions' => 800, 'clicks' => 8, 'avg_position' => 4.0, 'typical_ctr' => 0.07, 'top_queries' => [['query' => 'strata landscaping vancouver', 'impressions' => 500, 'clicks' => 5], ['query' => '<script>alert(1)</script> strata gardeners', 'impressions' => 200, 'clicks' => 2]]]]), 71.5, date('Y-m-d H:i:s'), null, null]);
$db->exec("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, current_title, current_desc, title, meta_desc, rationale, input_hash, status, version, created_at) VALUES (1, 1, 1, 'seo_meta', 'https://mowology.ca/services/strata-landscaping', 'Strata Landscaping', 'Landscaping for strata corporations in Vancouver.', 'Strata Landscaping and Grounds Care in Vancouver', 'Regular landscaping and grounds care for strata corporations in Vancouver, Burnaby and Richmond. Ask for a quote today.', 'Many people see this page but few click. A clearer title may help.', 'h', 'draft', 1, '" . date('Y-m-d') . "')");
$db->exec("UPDATE mia_actions SET draft_ref_table = 'mia_content_drafts', draft_ref_id = 1 WHERE id = 1");
$ins->execute(['audit:1:meta_missing', 'seo_meta_update', 'proposed', 'Add a description to 5 pages', 'A page has no description. Without one, Google writes its own snippet, which may not sell the page. Found on 5 pages, for example https://mowology.ca/about, https://mowology.ca/contact.', 'Estimate: tidier pages that search engines and visitors can understand more easily. We cannot predict a number of extra visits for this kind of fix.', 'We prepare the wording and you approve it before anything on the site changes.', json_encode([['rule' => 'meta_missing', 'severity' => 'medium', 'pages' => 5, 'examples' => ['https://mowology.ca/about', 'https://mowology.ca/contact']]]), 38.2, date('Y-m-d H:i:s'), null, null]);
$ins->execute(['audit:1:broken_page', 'fix_technical', 'proposed', 'Fix 1 page that cannot be found', 'A page that cannot be found. Visitors and search engines land on an error instead of your content.', 'Estimate: tidier pages.', 'Technical changes should be checked on a copy of the page first.', json_encode([['rule' => 'broken_page']]), 30.0, date('Y-m-d H:i:s'), null, null]);
$ins->execute(['old:1', 'internal_link', 'approved', 'Link to 2 pages that nothing links to', 'x', 'Estimate: y.', 'z', '[]', 20.0, date('Y-m-d H:i:s', strtotime('-3 days')), date('Y-m-d H:i:s', strtotime('-2 days')), null]);
$ins->execute(['old:2', 'page_draft', 'rejected', 'Consider a page for the search "snow removal burnaby"', 'x', 'Estimate: y.', 'z', '[]', 15.0, date('Y-m-d H:i:s', strtotime('-9 days')), date('Y-m-d H:i:s', strtotime('-8 days')), 'We do not offer snow removal in Burnaby']);

echo "Mia UI harness: http://127.0.0.1:$port/crm/marketing/mia.php\n";
$cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/router.php"];
pcntl_exec(PHP_BINARY, array_slice($cmd, 1), ['MIA_TEST_DB' => "$root/test.sqlite", 'PATH' => getenv('PATH') ?: '/usr/bin']);
