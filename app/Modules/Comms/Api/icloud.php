<?php
/**
 * Tim's iCloud mailbox — admin checks for IcloudInboxRouter. Nothing here stores anything.
 *
 * GET  ?mode=status                       Which mailboxes are configured (never passwords),
 *                                         and where the iCloud reader got to.
 * GET|POST mode=icloud_test               Log in read-only, list folders + message counts.
 * GET|POST mode=icloud_dry_run&days=7     Classify the last N days (1–90, default 7) of every
 *                                         readable folder and return counts per route and per
 *                                         folder, with subjects for enquiries and vendor mail
 *                                         only — never bodies, never personal mail. Stops at
 *                                         240 s: partial: true, remaining, and a `resume`
 *                                         cursor ({folder: uid}) to POST back to carry on.
 *                                         Always includes scanned + elapsed (seconds).
 *
 * Admin only. icloud_test / icloud_dry_run need the CSRF token (POST body csrf_token, or
 * the X-CSRF-Token header on a GET) — they log into a mailbox. ?mode=, not ?action= (the
 * /api/ router appends its own action).
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = (string)($method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? 'status'));

    if ($mode !== 'status') {
        $token = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verifyCSRFToken($token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Modules/Comms/Services/IcloudInboxRouter.php';
    $router = new IcloudInboxRouter($db);

    switch ($mode) {
        case 'status': {
            $state = [];
            if ($router->stateReady()) {
                $state = $db->query("SELECT folder, last_uid, first_run_at, last_run_at, last_summary FROM mailbox_poll_state WHERE mailbox_key = 'icloud'")
                            ->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['ok' => true, 'mailboxes' => MailboxConfig::status(), 'icloud' => IcloudInboxRouter::notConfigured() ?: 'configured',
                              'state' => $state, 'migration_1223' => $router->stateReady()]);
            break;
        }

        case 'icloud_test':
            @set_time_limit(60);
            echo json_encode($router->test());
            break;

        case 'icloud_dry_run': {
            @set_time_limit(300);   // the router stops itself at DRY_RUN_BUDGET (240 s)
            $days = (int)($input['days'] ?? $_GET['days'] ?? IcloudInboxRouter::DRY_RUN_DAYS);
            $resume = [];
            foreach ((array)($input['resume'] ?? []) as $folder => $uid) {
                if (is_scalar($uid) && (int)$uid > 0) $resume[(string)$folder] = (int)$uid;
            }
            echo json_encode($router->poll(true, max(1, min(IcloudInboxRouter::BACKFILL_DAYS, $days)), null, $resume));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[icloud] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The iCloud check hit a snag — see the error log.']);
}
