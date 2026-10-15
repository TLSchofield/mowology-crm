<?php
/**
 * Tidy iCloud — admin API for MailTidyService (Tim's mowology@icloud.com).
 *
 * GET  ?mode=plan                     The folder plan, settings, the latest preview's summary
 *                                     (from the database — no mailbox login).
 * POST mode=tidy_preview              Start a preview (no preview_id) or continue one (preview_id).
 *                                     READ-ONLY: scans ~20 s per call; the page calls again until
 *                                     status = ready. Returns counts, samples (subject + sender,
 *                                     never bodies), the full Junk rescue list, folders to create.
 * POST mode=tidy_select               preview_id, ids[], selected (tick / untick rescue items).
 * POST mode=tidy_apply                preview_id, kind = apply | rescue, batch_id (to continue).
 *                                     MOVES mail. One click = one batch; the page repeats the call
 *                                     with batch_id until done.
 * POST mode=tidy_undo                 batch (the batch to reverse), undo_batch_id (to continue).
 * POST mode=keep_tidy                 on = 0 | 1 (Keep it tidy, default off).
 *
 * Admin only; every POST needs the CSRF token (body csrf_token or X-CSRF-Token header).
 * ?mode=, not ?action= (the /api/ router appends its own action).
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
    $user = getCurrentUser();
    $userId = (int)($user['id'] ?? 0) ?: null;

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = (string)($method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? 'plan'));

    if ($mode !== 'plan') {
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST only']);
            exit;
        }
        $token = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verifyCSRFToken($token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
    require_once APP_ROOT . '/Modules/Comms/Services/MailTidyService.php';
    require_once APP_ROOT . '/Modules/Comms/Services/ImapTidyPort.php';

    $offline = new MailTidyService($db);
    if (!$offline->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1226 has not run yet.', 'migration_1226' => false]);
        exit;
    }

    /** A service with the live mailbox (read-only reads; writes only through ImapWriter). */
    $connect = function () use ($db): array {
        $mb = MailboxConfig::icloud();
        if ($mb === null) return [null, null, 'iCloud is not configured — ICLOUD_IMAP_USER / ICLOUD_IMAP_PASS are not set in secrets.php.'];
        if (!function_exists('imap_open')) return [null, null, 'PHP imap extension not available.'];
        $port = new ImapTidyPort($mb, static function (string $m): void { error_log('[icloud-tidy] ' . $m); });
        return [new MailTidyService($db, $port), $port, null];
    };

    $plan = $offline->plan();
    switch ($mode) {
        case 'plan': {
            $folders = [];
            foreach ($plan['folders'] as $k => $f) {
                $folders[] = ['key' => $k, 'imap' => $f['imap'], 'label' => $f['label'], 'note' => $f['note'], 'existing' => $f['existing']];
            }
            $pid = $offline->latestPreviewId();
            echo json_encode(['ok' => true, 'folders' => $folders, 'settings' => $plan['settings'],
                              'configured' => MailboxConfig::icloud() !== null,
                              'preview' => $pid ? $offline->summary($pid) : null,
                              'batches' => $offline->batches(null, 10)]);
            break;
        }

        case 'tidy_preview': {
            @set_time_limit(90);
            [$svc, $port, $err] = $connect();
            if ($err) { echo json_encode(['ok' => false, 'error' => $err]); break; }
            $pid = (int)($input['preview_id'] ?? 0);
            if ($pid <= 0) $pid = $svc->startPreview($userId);
            $step = $svc->scanStep($pid, 20.0);
            $out = $svc->summary($pid, $port->folders()) + ['step' => $step];
            $port->close();
            echo json_encode($out);
            break;
        }

        case 'tidy_select': {
            $n = $offline->setSelected((int)($input['preview_id'] ?? 0), (array)($input['ids'] ?? []), !empty($input['selected']));
            echo json_encode(['ok' => true, 'updated' => $n]);
            break;
        }

        case 'tidy_apply': {
            @set_time_limit(90);
            [$svc, $port, $err] = $connect();
            if ($err) { echo json_encode(['ok' => false, 'error' => $err]); break; }
            $batch = isset($input['batch_id']) && (int)$input['batch_id'] > 0 ? (int)$input['batch_id'] : null;
            $kind = (string)($input['kind'] ?? 'apply') === 'rescue' ? 'rescue' : 'apply';
            $out = $svc->applyStep((int)($input['preview_id'] ?? 0), $kind, $batch, $userId, 20.0);
            $port->close();
            echo json_encode($out);
            break;
        }

        case 'tidy_undo': {
            @set_time_limit(90);
            [$svc, $port, $err] = $connect();
            if ($err) { echo json_encode(['ok' => false, 'error' => $err]); break; }
            $undo = isset($input['undo_batch_id']) && (int)$input['undo_batch_id'] > 0 ? (int)$input['undo_batch_id'] : null;
            $out = $svc->undoStep((int)($input['batch'] ?? 0), $undo, $userId, 20.0);
            $port->close();
            echo json_encode($out);
            break;
        }

        case 'keep_tidy': {
            $offline->setSetting('keep_tidy', !empty($input['on']) ? '1' : '0');
            echo json_encode(['ok' => true, 'keep_tidy' => $offline->setting('keep_tidy')]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[icloud-tidy] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The iCloud tidy hit a snag — see the error log.']);
}
