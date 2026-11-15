// Register every non-default business with the vendor commons hub (kb_tenants), idempotent.
// Run on production through scripts/prod-run.sh: preview first, --commit only after review.
// Never prints keys or hashes. Needs KB_HUB_SECRET in secrets.php (the key is derived from it per business).
require_once APP_ROOT . '/Modules/Knowledge/Services/VendorKnowledgeService.php';
$out = [];
if (!defined('KB_HUB_SECRET') || (string)KB_HUB_SECRET === '') {
    $out[] = 'ERROR: KB_HUB_SECRET is not configured; nothing registered.';
} else {
    foreach (Tenant::businesses() as $business) {
        $key = (string)($business['key'] ?? '');
        if ($key === '' || $key === Tenant::DEFAULT || $key === Tenant::DIRECTORY) continue;
        $hash = hash('sha256', VendorKnowledgeService::clientKey($key, (string)KB_HUB_SECRET));
        $tenantHash = VendorIdentity::tenantHash($key);
        $find = $db->prepare('SELECT id FROM kb_tenants WHERE name = ? LIMIT 1');
        $find->execute([$key]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            $out[] = 'update ' . $key;
            if ($commit) $db->prepare('UPDATE kb_tenants SET api_key_hash = ?, tenant_hash = ?, is_active = 1 WHERE id = ?')->execute([$hash, $tenantHash, (int)$id]);
        } else {
            $out[] = 'create ' . $key . ($business['test'] ? ' (test: reads only)' : '');
            if ($commit) $db->prepare('INSERT INTO kb_tenants (name, api_key_hash, tenant_hash, is_active) VALUES (?, ?, ?, 1)')->execute([$key, $hash, $tenantHash]);
        }
    }
}
