<?php
/**
 * Messages bridge endpoint — customer texts from Tim's Mac (tools/messages-bridge/).
 *
 * GET  ?mode=numbers     {ok, salt, hashes: [...], personal: [...]} salted SHA-256 of every customer number
 *                        (last 10 digits) and of every number Tim marked personal (the bridge never sends those).
 *                        Contact ids and numbers never leave the CRM. Also moves waiting texts whose number has
 *                        just been added to a customer into that customer's history.
 * POST {mode: 'ingest', messages: [{hash, direction: in|out, sent_at, guid, text, phone?}, ...]}
 *                        at most 500 per call; duplicates ignored. A hash that is not a customer is kept for Tim's
 *                        "texts from numbers we don't know" list when 'phone' (last 10 digits) is sent and hashes
 *                        to it (bridge 1.2, migration 1396); otherwise it is rejected.
 * POST {mode: 'interac', alerts: [{guid, sent_at, sender}, ...]}  Interac alert texts (name + time only)
 * POST {mode: 'heartbeat', scanned, one_to_one, matched_customers, sent, stored, version}
 *
 * A machine endpoint: no session, no login. Auth is a bearer token compared (hash_equals)
 * with SALES_TEXT_BRIDGE_TOKEN in secrets.php; until Tim defines it, everything is 503.
 * ?mode=, not ?action= (the /api/ router appends its own action).
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

$reply = function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

try {
    require_once APP_ROOT . '/Core/config.php';   // secrets + getDB(); no session
    require_once APP_ROOT . '/Modules/Sales/Services/TextBridgeService.php';

    $expected = defined('SALES_TEXT_BRIDGE_TOKEN') ? (string)constant('SALES_TEXT_BRIDGE_TOKEN') : '';
    if (strlen($expected) < 32) {
        $reply(503, ['ok' => false, 'error' => 'not configured']);
    }

    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) { $header = (string)$v; break; }
        }
    }
    $given = stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : '';
    if ($given === '') $given = trim((string)($_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''));   // hosts that strip Authorization
    if ($given === '' || !hash_equals($expected, $given)) {
        $reply(401, ['ok' => false, 'error' => 'unauthorized']);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = [];
    if ($method === 'POST') {
        $raw = (string)file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
        if (strlen($raw) > 2 * 1024 * 1024) $reply(413, ['ok' => false, 'error' => 'too large']);
        $input = json_decode($raw, true);
        if (!is_array($input)) $reply(400, ['ok' => false, 'error' => 'bad json']);
    }
    $mode = (string)($method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? ''));

    $svc = new TextBridgeService(getDB());
    if (!$svc->ready()) {
        $reply(503, ['ok' => false, 'error' => 'Migration 1140 has not run yet']);
    }

    if ($method === 'GET' && $mode === 'numbers') {
        try { $svc->adoptUnmatched(); } catch (Throwable $e) { error_log('text-bridge adopt: ' . $e->getMessage()); }
        [$salt, $map] = $svc->numbers();
        $reply(200, ['ok' => true, 'salt' => $salt, 'hashes' => array_keys($map), 'personal' => $svc->personalHashes()]);
    }
    if ($method === 'POST' && $mode === 'ingest') {
        $msgs = $input['messages'] ?? null;
        if (!is_array($msgs)) $reply(400, ['ok' => false, 'error' => 'messages missing']);
        if (count($msgs) > TextBridgeService::MAX_BATCH) {
            $reply(413, ['ok' => false, 'error' => 'at most ' . TextBridgeService::MAX_BATCH . ' messages per call']);
        }
        $reply(200, ['ok' => true] + $svc->ingest(array_values($msgs)));
    }
    if ($method === 'POST' && $mode === 'interac') {
        // Interac's own alert texts (bridge 1.1): sender name + time only, for Penny (2026-10-14).
        $alerts = $input['alerts'] ?? null;
        if (!is_array($alerts) || count($alerts) > TextBridgeService::MAX_BATCH) $reply(400, ['ok' => false, 'error' => 'alerts missing or too many']);
        $reply(200, ['ok' => true] + $svc->ingestInterac(array_values($alerts)));
    }
    if ($method === 'POST' && $mode === 'heartbeat') {
        $svc->heartbeat($input);
        $reply(200, ['ok' => true]);
    }
    $reply(400, ['ok' => false, 'error' => 'unknown mode']);
} catch (Throwable $e) {
    error_log('text-bridge: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error']);
}
