<?php
/**
 * Supplier price search (owner, 2026-10-19): GET ?q=japanese+maple+%235[&property_id=12][&limit=12]
 * → SupplierSearchService::search — the latest list of every supplier, cheapest in stock first, with
 * stock, list age, order rules, and (with a job) about how far each supplier's yard is. Read-only.
 * products.view or quotes.edit (Sam's quote editor uses it).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
header('Content-Type: application/json');
if (!userHasAnyPermission(['products.view', 'quotes.edit', 'billing.edit'])) {   // billing.edit: the quote editor's permission
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => [], 'message' => 'Type a plant or material, e.g. "japanese maple #5".']);
    exit;
}
try {
    require_once APP_ROOT . '/Modules/Operations/Services/SupplierSearchService.php';
    $pid = (int)($_GET['property_id'] ?? 0);
    $limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));
    $r = (new SupplierSearchService(getDB()))->search(mb_substr($q, 0, 80), $pid > 0 ? $pid : null, $limit);
    // Bulk results: which installed-pricing family Sam's engine prices them as (MulchPricingService: mulch,
    // soil, compost — manure counts as compost), and what the local market charges (MarketPriceService).
    require_once APP_ROOT . '/Modules/Sales/Services/MulchPricingService.php';
    require_once APP_ROOT . '/Modules/Operations/Services/MarketPriceService.php';
    $market = new MarketPriceService(getDB());
    foreach ($r['results'] as &$g) {
        if (empty($g['bulk'])) continue;
        $mat = MarketPriceService::material($g['name']);
        // Only what Sam's engine itself recognises (+ manure = compost): a gravel "…Blend" must never be priced as soil (review 2026-10-19).
        $g['install_family'] = MulchPricingService::family($g['name']) ?? (preg_match('/\bmanure\b/i', $g['name']) ? 'compost' : null);
        $g['market'] = $market->current($r['words'], $mat);
    }
    unset($g);
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('supplier-search: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'The search failed — try again.']);
}
