<?php
/**
 * /crm/api/mia-invoice-suggestion.php — Mia's suggestion for the invoice note (read) and the owner's taps (write).
 *
 * Thin controller over MiaInvoiceSuggestion (app/Modules/Marketing/Services/MiaInvoiceSuggestion.php).
 * Session auth + billing.edit. Nothing here changes an invoice: the page inserts the text, a person saves it.
 *
 *   GET  ?mode=suggest&property_id=39&contact_id=36&company_id=0[&invoice_id=511]&services[]=…
 *        → { success, suggestion: {event_id, key, method, text, auto}|null, proposals: [{id, library_key, title, …}] }
 *   POST {mode:'record', event_id, action:'inserted'|'dismissed'|'edited', csrf_token}
 *   POST {mode:'decide', rule_id, decision:'active'|'not_now'|'never', csrf_token}
 *
 * `mode`, never `action` as the query key (the /api/ rewrite appends its own).
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once APP_ROOT . '/Modules/Marketing/Services/MiaInvoiceLibrary.php';
require_once APP_ROOT . '/Modules/Marketing/Services/MiaInvoiceSuggestion.php';
require_once APP_ROOT . '/Modules/Marketing/Services/MiaWording.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireLogin();
$user = getCurrentUser();

if (!userHasPermission('billing.edit') && ($user['role'] ?? '') !== 'admin') {
    session_write_close();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Billing permission required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'suggest');

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    session_write_close();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Your session token is stale. Reload the page.']);
    exit;
}
session_write_close();

try {
    $db  = getDB();
    $svc = new MiaInvoiceSuggestion($db);

    if ($method === 'POST' && $mode === 'record') {
        echo json_encode(['success' => $svc->record((int)($input['event_id'] ?? 0), (string)($input['action'] ?? ''))]);
        exit;
    }
    if ($method === 'POST' && $mode === 'decide') {
        if (!in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only an admin or manager can decide automations.']);
            exit;
        }
        echo json_encode(['success' => $svc->decideRule((int)($input['rule_id'] ?? 0), (string)($input['decision'] ?? ''))]);
        exit;
    }
    if ($method !== 'GET' || $mode !== 'suggest') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown mode.']);
        exit;
    }

    $contactId  = (int)($input['contact_id'] ?? 0);
    $companyId  = (int)($input['company_id'] ?? 0);
    $services   = array_values(array_filter(array_map('strval', (array)($input['services'] ?? []))));
    $first = '';
    if ($contactId > 0) {
        $c = $db->prepare("SELECT first_name FROM contacts WHERE id = ?");
        $c->execute([$contactId]);
        $first = ucwords(strtolower(trim((string)$c->fetchColumn())));
    }
    $u = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'google_review_url'");
    $u->execute();
    $reviewUrl = trim((string)$u->fetchColumn());

    // Residential only, decided from the saved invoice when there is one, not from what the page says.
    $invoiceId = (int)($input['invoice_id'] ?? 0);
    if ($invoiceId > 0) {
        $iv = $db->prepare("SELECT company_id FROM invoices WHERE id = ?");
        $iv->execute([$invoiceId]);
        $companyId = max($companyId, (int)$iv->fetchColumn());
    }
    $suggestion = $svc->suggest($services, $companyId > 0 ? 'pm' : 'home', $invoiceId,
        (int)($input['property_id'] ?? 0), $contactId, $first, $reviewUrl, MiaWording::PHONE);

    $proposals = array_map(function ($p) {
        $p['title'] = ucfirst(str_replace('_', ' ', $p['library_key']));
        return $p;
    }, $svc->pendingProposals());

    echo json_encode(['success' => true, 'suggestion' => $suggestion, 'proposals' => $proposals]);
} catch (Throwable $e) {
    error_log('mia-invoice-suggestion.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load the suggestion.']);
}
