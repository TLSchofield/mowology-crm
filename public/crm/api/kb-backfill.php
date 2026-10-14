<?php
/**
 * One-off: seed the vendor knowledge commons from this install's own learning.
 *
 * Admin only. Idempotent — the commons counts confirmations, so a second run only bumps counts.
 * For every active vendor: identity (phone/website/name key, GST number from recent OCR text),
 * receipt layout (vendor_parse_profiles), SKU names (vendor_product_skus), the neutral category
 * default and tax treatment from approved receipts. Links vendors.kb_vendor_id.
 * Run after migration 1330, from a logged-in browser: /crm/api/kb-backfill.php
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
session_write_close();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
require_once APP_ROOT . '/Modules/Knowledge/Services/VendorKnowledgeService.php';

$db  = getDB();
$kb  = new VendorKnowledgeService($db);
$tax = new CategoryTaxonomy($db);
if (!$kb->ready()) { echo json_encode(['error' => 'Run migration 1330 first (kb_vendors missing)']); exit; }
$store = $kb->store();
$tenant = VendorKnowledgeService::tenantKey();

$vendors = $db->query("SELECT id, name, phone, website, gst_exempt, default_accounting_category FROM vendors WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$out = ['vendors' => count($vendors), 'contributed' => 0, 'linked' => 0, 'skipped' => []];

$recentOcr = $db->prepare("SELECT raw_ocr_json FROM expenses WHERE vendor_id = ? AND raw_ocr_json IS NOT NULL ORDER BY id DESC LIMIT 3");
$topCat    = $db->prepare("SELECT accounting_category, COUNT(*) c FROM expenses WHERE vendor_id = ? AND status IN ('approved','forwarded') AND accounting_category IS NOT NULL GROUP BY accounting_category ORDER BY c DESC LIMIT 1");
$profile   = $db->prepare("SELECT gst_label, gst_position, has_pst, pst_label, date_format, barcode_length, item_suffix_pattern, discount_prefix, card_mask_style FROM vendor_parse_profiles WHERE vendor_id = ?");
$skus      = $db->prepare("SELECT sku_raw, item_name FROM vendor_product_skus WHERE vendor_id = ? AND item_name IS NOT NULL ORDER BY times_seen DESC LIMIT 40");

foreach ($vendors as $v) {
    $vid = (int)$v['id'];
    $gst = null;
    $recentOcr->execute([$vid]);
    foreach ($recentOcr->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $gst = VendorIdentity::gstNumber(ocrTextFromStored($raw));
        if ($gst) break;
    }
    $identity = [
        'gst_number'   => $gst,
        'phone_digits' => VendorIdentity::phoneDigits($v['phone']),
        'domain'       => VendorIdentity::domainOf($v['website']),
        'name_key'     => VendorIdentity::nameKey($v['name']),
    ];
    if (!$identity['name_key'] && !$identity['phone_digits'] && !$identity['domain'] && !$gst) { $out['skipped'][] = $v['name']; continue; }

    $facts = [];
    $profile->execute([$vid]);
    if ($p = $profile->fetch(PDO::FETCH_ASSOC)) {
        $layout = array_filter($p, fn($x) => $x !== null && $x !== '' && $x !== 'unknown');
        if ($layout) $facts[] = ['fact' => 'receipt_layout', 'value' => $layout];
    }
    $skus->execute([$vid]);
    foreach ($skus->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $facts[] = ['fact' => 'sku', 'value' => ['sku' => (string)$r['sku_raw'], 'name' => mb_substr((string)$r['item_name'], 0, 120)]];
    }
    $topCat->execute([$vid]);
    $cat = $topCat->fetchColumn() ?: $v['default_accounting_category'];
    if ($code = $tax->fromLocal($cat ?: null)) $facts[] = ['fact' => 'category_default', 'value' => ['code' => $code]];
    $facts[] = ['fact' => 'tax_treatment', 'value' => ['gst_exempt' => !empty($v['gst_exempt'])]];

    $res = $store->contribute(['identity' => $identity, 'display_name' => $v['name'], 'facts' => $facts], $tenant);
    if ($res) {
        $out['contributed']++;
        $kb->linkLocalVendor($vid, is_int($res) ? $res : null, $identity + ['gst_number' => $gst]);
        $out['linked']++;
    }
}
$out['commons'] = method_exists($store, 'stats') ? $store->stats() : null;
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
