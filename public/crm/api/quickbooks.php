<?php
/**
 * QuickBooks Online — JSON for /crm/accounting/quickbooks.php (2026-10-07). Admin only.
 *
 * GET  ?mode=status                      connection + config facts (never throws)
 * GET  ?mode=discovery                   the cached read-only report of the company file
 * POST {mode:'refresh_discovery'}        re-read the company file (≈ 60 small GETs), cache it
 * GET  ?mode=accounts                    CRM chart ↔ QBO accounts: suggestions + what is confirmed
 * POST {mode:'map_confirm', crm_account_id, qbo_account_id|null, confidence, matched_on}
 * POST {mode:'map_clear', crm_account_id}
 * POST {mode:'map_accept_all'}           confirm every pre-selected (≥ 85) suggestion in one go
 * POST {mode:'choices', ...}             tax codes / paid-from accounts / service item / dry-run flag
 * GET  ?mode=preview                     phase-2 dry run: what the next approved expenses would become
 * POST {mode:'disconnect'}               revoke at Intuit + forget tokens
 *
 * `mode`, never `action` (Known-Failure-Patterns: the API router appends its own action).
 * qbo_push_enabled is NOT settable here on purpose — flipping it is a deliberate DB change.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}
require_once APP_ROOT . '/Modules/Accounting/Services/QuickBooks/QboPushService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'status');
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();
set_time_limit(300);

$db     = getDB();
$config = QboConfig::fromConstants();
$push   = new QboPushService($db, $config);
$conns  = $push->connections();
$disc   = new QboDiscoveryService($db);
$maps   = new QboAccountMapService($db);

try {
    if ($method === 'GET' && $mode === 'status') {
        $conn = $conns->current();
        $cached = $disc->cached();
        echo json_encode(['ok' => true,
            'connection'   => QboConnectionService::describe($conn, $config, time()),
            'push_enabled' => $push->isEnabled(),
            'dry_run'      => $push->isDryRun(),
            'discovery_at' => $cached['at'],
            'choices'      => [
                'tax_gst'     => $push->setting(QboPushService::SETTING_TAX_GST),
                'tax_gst_pst' => $push->setting(QboPushService::SETTING_TAX_GST_PST),
                'tax_exempt'  => $push->setting(QboPushService::SETTING_TAX_EXEMPT),
                'item'        => $push->setting(QboPushService::SETTING_ITEM),
                'paid_from'   => json_decode((string)$push->setting(QboPushService::SETTING_PAID_FROM, '{}'), true) ?: [],
            ],
            'redirect_uri' => $config->redirectUri(),
        ]);
    } elseif ($method === 'GET' && $mode === 'discovery') {
        $cached = $disc->cached();
        echo json_encode(['ok' => true, 'report' => $cached['report'], 'at' => $cached['at']]);
    } elseif ($method === 'POST' && $mode === 'refresh_discovery') {
        $conn = $conns->active();
        if ($conn === null) { echo json_encode(['ok' => false, 'error' => 'Connect QuickBooks first.']); exit; }
        $api    = $push->client();
        $raw    = $disc->gather($api, (int)date('Y'));
        $report = QboDiscoveryService::summarise($raw, date('Y-m-d'));
        $disc->store($report);
        $conns->rememberCompany((int)$conn['id'], [
            'company_name' => $report['company']['name'], 'country' => $report['company']['country'],
            'home_currency' => $report['company']['home_currency'], 'fiscal_year_start' => $report['company']['fiscal_year_start'],
            'book_close_date' => $report['company']['book_close_date'],
        ]);
        echo json_encode(['ok' => true, 'report' => $report, 'at' => date('Y-m-d H:i:s')]);
    } elseif ($method === 'GET' && $mode === 'accounts') {
        $conn   = $conns->current();
        $report = $disc->cached()['report'];
        $crm    = $maps->crmAccounts();
        $qbo    = $report['accounts'] ?? [];
        echo json_encode(['ok' => true,
            'crm'         => $crm,
            'qbo'         => $qbo,
            'suggestions' => QboAccountMapService::suggest($crm, $qbo),
            'confirmed'   => $conn ? $maps->confirmed((int)$conn['id']) : [],
            'auto_min'    => QboAccountMapService::AUTO_PICK_MIN,
            'has_report'  => $report !== null,
        ]);
    } elseif ($method === 'POST' && ($mode === 'map_confirm' || $mode === 'map_clear' || $mode === 'map_accept_all')) {
        $conn = $conns->current();
        if ($conn === null) { echo json_encode(['ok' => false, 'error' => 'Connect QuickBooks first.']); exit; }
        $connId = (int)$conn['id'];
        if ($mode === 'map_clear') {
            $maps->clear($connId, (int)($input['crm_account_id'] ?? 0));
            echo json_encode(['ok' => true]);
            exit;
        }
        $report = $disc->cached()['report'];
        $byId = [];
        foreach (($report['accounts'] ?? []) as $a) $byId[(string)$a['id']] = $a;
        if ($mode === 'map_accept_all') {
            $crm = $maps->crmAccounts();
            $sug = QboAccountMapService::suggest($crm, $report['accounts'] ?? []);
            $already = $maps->confirmed($connId);
            $n = 0;
            foreach ($sug as $crmId => $s) {
                if (isset($already[$crmId]) || empty($s['best']) || $s['best']['score'] < QboAccountMapService::AUTO_PICK_MIN) continue;
                $a = $byId[$s['best']['qbo_id']] ?? null;
                if ($a === null) continue;
                $maps->confirm($connId, (int)$crmId, $a, $userId, (int)$s['best']['score'], (string)$s['best']['matched_on']);
                $n++;
            }
            echo json_encode(['ok' => true, 'confirmed' => $n]);
            exit;
        }
        $crmId = (int)($input['crm_account_id'] ?? 0);
        $qboId = isset($input['qbo_account_id']) && $input['qbo_account_id'] !== '' && $input['qbo_account_id'] !== null ? (string)$input['qbo_account_id'] : null;
        if ($crmId <= 0) { echo json_encode(['ok' => false, 'error' => 'Which CRM account?']); exit; }
        $a = $qboId !== null ? ($byId[$qboId] ?? null) : null;
        if ($qboId !== null && $a === null) { echo json_encode(['ok' => false, 'error' => 'That QuickBooks account is not in the discovery report — refresh it.']); exit; }
        $maps->confirm($connId, $crmId, $a, $userId, (int)($input['confidence'] ?? 0), (string)($input['matched_on'] ?? 'manual'));
        echo json_encode(['ok' => true]);
    } elseif ($method === 'POST' && $mode === 'choices') {
        $allowed = [
            'tax_gst' => QboPushService::SETTING_TAX_GST, 'tax_gst_pst' => QboPushService::SETTING_TAX_GST_PST,
            'tax_exempt' => QboPushService::SETTING_TAX_EXEMPT, 'item' => QboPushService::SETTING_ITEM,
        ];
        foreach ($allowed as $k => $setting) {
            if (array_key_exists($k, $input)) $push->saveSetting($setting, substr((string)$input[$k], 0, 32), 'QuickBooks push choice: ' . $k);
        }
        if (isset($input['paid_from']) && is_array($input['paid_from'])) {
            $clean = [];
            foreach (['bank', 'credit_card', 'cheque'] as $k) {
                if (!empty($input['paid_from'][$k])) $clean[$k] = substr((string)$input['paid_from'][$k], 0, 32);
            }
            $push->saveSetting(QboPushService::SETTING_PAID_FROM, json_encode($clean), 'QuickBooks push choice: accounts purchases are paid from');
        }
        if (array_key_exists('dry_run', $input)) {
            $push->saveSetting(QboPushService::FLAG_DRY_RUN, !empty($input['dry_run']) ? '1' : '0', 'When push is enabled: 1 = build and show payloads, write nothing to QuickBooks.');
        }
        echo json_encode(['ok' => true]);
    } elseif ($method === 'GET' && $mode === 'preview') {
        echo json_encode(['ok' => true] + $push->preview((int)($input['limit'] ?? 25)));
    } elseif ($method === 'POST' && $mode === 'disconnect') {
        $conn = $conns->current();
        if ($conn === null) { echo json_encode(['ok' => false, 'error' => 'Nothing to disconnect.']); exit; }
        $r = $conns->disconnect($conn);
        $push->log(['connection_id' => (int)$conn['id'], 'direction' => 'push', 'operation' => 'revoke', 'summary' => $r['revoked'] ? 'Revoked at Intuit' : 'Tokens forgotten (revoke not confirmed)', 'created_by' => $userId]);
        echo json_encode(['ok' => true, 'revoked' => $r['revoked']]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (QboThrottledException $e) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[quickbooks] ' . $mode . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
