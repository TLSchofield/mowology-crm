<?php
/**
 * Vendor knowledge commons — hub API for other installs.
 *
 *   GET  /api/knowledge/vendor.php?mode=resolve&gst_number=…&phone_digits=…&domain=…&name_key=…
 *   POST /api/knowledge/vendor.php?mode=contribute      body: {identity:{…}, display_name, facts:[{fact,value}]}
 *   Header: X-KB-Key: <tenant key>   (kb_tenants.api_key_hash = sha256(key))
 *
 * Returns ONLY commons fields (identity, kind, shared facts). Never a tenant's data.
 * `?mode=` not `?action=` — the /api/ rewrite appends a duplicate `action` (see memory).
 * 600 calls per tenant per day. No session, no CSRF: key-authenticated machine endpoint.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Modules/Knowledge/Services/VendorIdentity.php';
require_once APP_ROOT . '/Modules/Knowledge/Services/LocalVendorKnowledgeStore.php';

function kb_out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }

$key = $_SERVER['HTTP_X_KB_KEY'] ?? '';
if ($key === '' || strlen($key) < 20) kb_out(401, ['ok' => false, 'error' => 'missing key']);

try {
    $db = Database::pdo();
    $t = $db->prepare("SELECT id, name, tenant_hash, calls_today, calls_day, is_active FROM kb_tenants WHERE api_key_hash = ?");
    $t->execute([hash('sha256', $key)]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    kb_out(503, ['ok' => false, 'error' => 'commons not ready']);
}
if (!$tenant || !(int)$tenant['is_active']) kb_out(403, ['ok' => false, 'error' => 'unknown key']);

// Daily quota.
$today = date('Y-m-d');
$calls = $tenant['calls_day'] === $today ? (int)$tenant['calls_today'] : 0;
if ($calls >= 600) kb_out(429, ['ok' => false, 'error' => 'daily quota reached']);
$db->prepare("UPDATE kb_tenants SET calls_today = ?, calls_day = ?, last_seen_at = NOW() WHERE id = ?")->execute([$calls + 1, $today, (int)$tenant['id']]);

$store = new LocalVendorKnowledgeStore($db, false);   // remote tenants see shared facts only
if (!$store->ready()) kb_out(503, ['ok' => false, 'error' => 'commons not ready']);

$mode = $_GET['mode'] ?? '';
if ($mode === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $identity = [
        'gst_number'   => isset($_GET['gst_number']) ? substr(preg_replace('/[^0-9A-Z]/', '', strtoupper((string)$_GET['gst_number'])), 0, 20) : null,
        'phone_digits' => VendorIdentity::phoneDigits($_GET['phone_digits'] ?? null),
        'domain'       => VendorIdentity::domainOf($_GET['domain'] ?? null),
        'name_key'     => VendorIdentity::nameKey($_GET['name_key'] ?? null),
    ];
    $v = $store->resolve($identity);
    if ($v) unset($v['tenant_count']);   // how many tenants know a vendor is the hub's business
    kb_out(200, ['ok' => true, 'vendor' => $v]);
}

if ($mode === 'contribute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 64 * 1024) kb_out(413, ['ok' => false, 'error' => 'too large']);
    $lesson = json_decode($raw, true);
    if (!is_array($lesson) || !is_array($lesson['identity'] ?? null)) kb_out(400, ['ok' => false, 'error' => 'bad body']);
    // Re-normalise everything server side; trust nothing from the client.
    $id = $lesson['identity'];
    $lesson['identity'] = [
        'gst_number'   => isset($id['gst_number']) ? substr(preg_replace('/[^0-9A-Z]/', '', strtoupper((string)$id['gst_number'])), 0, 20) : null,
        'phone_digits' => VendorIdentity::phoneDigits($id['phone_digits'] ?? null),
        'domain'       => VendorIdentity::domainOf($id['domain'] ?? null),
        'name_key'     => VendorIdentity::nameKey($id['name_key'] ?? ($lesson['display_name'] ?? null)),
    ];
    $lesson['display_name'] = mb_substr(trim((string)($lesson['display_name'] ?? '')), 0, 160);
    $facts = [];
    foreach ((array)($lesson['facts'] ?? []) as $f) {
        if (!is_array($f) || !isset($f['fact'], $f['value'])) continue;
        if (!in_array($f['fact'], ['receipt_layout', 'sku', 'category_default', 'tax_treatment', 'kind'], true)) continue;
        if (strlen(json_encode($f['value'])) > 2000) continue;
        $facts[] = ['fact' => $f['fact'], 'value' => $f['value']];
        if (count($facts) >= 60) break;
    }
    $lesson['facts'] = $facts;
    $res = $store->contribute($lesson, 'tenant:' . $tenant['tenant_hash']);
    kb_out(200, ['ok' => $res !== false]);
}

kb_out(400, ['ok' => false, 'error' => 'mode must be resolve (GET) or contribute (POST)']);
