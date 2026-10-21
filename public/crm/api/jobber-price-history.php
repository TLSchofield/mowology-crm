<?php
/**
 * GET ?company_id= | ?property_id= | ?contact_id=  → price history by service from the Jobber invoice
 * history (JobberPriceHistory), plus how many Jobber invoices matched. Admin only, read only.
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
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

// POST (multipart, the host rejects JSON bodies over ~1 MB): backfill empty line items from an Invoices export.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']); exit;
    }
    session_write_close();
    set_time_limit(300);
    require_once APP_ROOT . '/Modules/Accounting/Services/JobberCsv.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/JobberPriceHistory.php';
    if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'No file received.']); exit;
    }
    try {
        $res = (new JobberPriceHistory(getDB()))->backfillLineItems((string)file_get_contents($_FILES['csv_file']['tmp_name']));
        echo json_encode(['ok' => empty($res['error'])] + $res);
    } catch (Throwable $e) {
        http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
session_write_close();

require_once APP_ROOT . '/Modules/Accounting/Services/JobberPriceHistory.php';
$db = getDB();
$svc = new JobberPriceHistory($db);
try {
    if (isset($_GET['company_id']))      $out = $svc->forCompany((int)$_GET['company_id']);
    elseif (isset($_GET['property_id'])) $out = $svc->forProperty((int)$_GET['property_id']);
    elseif (isset($_GET['contact_id']))  $out = $svc->forContact((int)$_GET['contact_id']);
    else { http_response_code(400); echo json_encode(['error' => 'company_id, property_id or contact_id required']); exit; }
    $diag = [];
    if (isset($_GET['company_id'])) {
        $n = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
        $n->execute([(int)$_GET['company_id']]);
        $name = (string)$n->fetchColumn();
        $c = $db->prepare("SELECT COUNT(*) AS invoices, SUM(line_items IS NOT NULL AND line_items <> '') AS with_lines,
                                  SUM(company_id = ?) AS by_company FROM jobber_invoices WHERE client_name LIKE ?");
        $c->execute([(int)$_GET['company_id'], $name . '%']);
        $diag = ['company_name' => $name, 'name_prefix_matches' => $c->fetch(PDO::FETCH_ASSOC)];
    }
    echo json_encode(['ok' => true, 'diag' => $diag, 'services' => $out]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
