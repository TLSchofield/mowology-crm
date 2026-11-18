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
        $csv = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
        if (($_POST['kind'] ?? '') === 'quotes') {
            // Quotes → jobber_quotes (history only), linked to CRM clients the way the invoice import does.
            require_once APP_ROOT . '/Modules/Accounting/Services/JobberImportService.php';
            require_once APP_ROOT . '/Modules/Accounting/Services/JobberQuoteHistory.php';
            $db = getDB();
            $q = new JobberQuoteHistory($db);
            if (!$q->ready()) { echo json_encode(['ok' => false, 'error' => 'Run migration 1392 (jobber_quotes) first.']); exit; }
            $res = $q->import($csv, (new JobberImportService($db))->index());
        } else {
            $res = (new JobberPriceHistory(getDB()))->backfillLineItems($csv);
        }
        echo json_encode(['ok' => empty($res['error'])] + $res);
    } catch (Throwable $e) {
        http_response_code(500); error_log('jobber-price-history: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing was changed. See the server log.']);
    }
    exit;
}
session_write_close();

require_once APP_ROOT . '/Modules/Accounting/Services/JobberCsv.php';
require_once APP_ROOT . '/Modules/Accounting/Services/JobberPriceHistory.php';
$db = getDB();
$svc = new JobberPriceHistory($db);
// ?html=1 — the two cards as an HTML fragment (contact page loads it on demand). Empty string when there is no history.
if (isset($_GET['html'])) {
    header('Content-Type: text/html; charset=utf-8');
    require_once APP_ROOT . '/Modules/Accounting/Services/JobberQuoteHistory.php';
    $p = new JobberPriceHistory($db);
    try {
        $scope = isset($_GET['company_id']) ? $p->scopeForCompany((int)$_GET['company_id'])
               : (isset($_GET['property_id']) ? $p->scopeForProperty((int)$_GET['property_id']) : $p->scopeForContact((int)($_GET['contact_id'] ?? 0)));
        $jobberPrices = JobberPriceHistory::summarize((function () use ($db, $scope) {
            $s = $db->prepare("SELECT jobber_number, issued_date, status, line_items FROM jobber_invoices WHERE ($scope[0]) AND line_items IS NOT NULL ORDER BY issued_date DESC LIMIT 3000");
            $s->execute($scope[1]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })());
        $jobberQuotes = (new JobberQuoteHistory($db))->forScope($scope);
        include dirname(__DIR__) . '/includes/jobber_price_history_card.php';
        include dirname(__DIR__) . '/includes/jobber_quotes_card.php';
    } catch (Throwable $e) {
        error_log('jobber-price-history html: ' . $e->getMessage());
    }
    exit;
}
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
    $resp = ['ok' => true, 'diag' => $diag, 'services' => $out];
    if (isset($_GET['quotes'])) {
        require_once APP_ROOT . '/Modules/Accounting/Services/JobberQuoteHistory.php';
        $p = new JobberPriceHistory($db);
        $scope = isset($_GET['company_id']) ? $p->scopeForCompany((int)$_GET['company_id'])
               : (isset($_GET['property_id']) ? $p->scopeForProperty((int)$_GET['property_id']) : $p->scopeForContact((int)($_GET['contact_id'] ?? 0)));
        $resp['quotes'] = array_map(fn($q) => array_diff_key($q, ['line_items' => 1]), (new JobberQuoteHistory($db))->forScope($scope, 300));
    }
    echo json_encode($resp);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('jobber-price-history: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing was changed. See the server log.']);
}
