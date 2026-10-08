<?php
/**
 * Receipt facts (migration 1227) — admin endpoint. Logic: ReceiptFactsService.
 *
 * GET  ?mode=status          Dry run: how many receipts the next batch would parse / queue for OCR.
 * GET  ?mode=facts&id=N      One receipt's printed facts + the compact line.
 * POST {mode: 'backfill', parse?: 1..200, requeue?: 0..40, csrf_token}
 *                            Run one batch now (the penny_prepare cron runs the same batch every
 *                            15 min): adopt finished OCR, parse facts, queue never-read receipts.
 *                            Queuing is still capped at REOCR_DAILY_CAP a day.
 *
 * ?mode=, not ?action= (the /api/ router appends its own action). Admin only.
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
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptFactsService.php';

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'status');
    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();
    $svc = new ReceiptFactsService($db);
    if (!$svc->ready()) throw new RuntimeException('Receipt facts not set up (migration 1227)');

    switch ($mode) {
        case 'status':
            echo json_encode(['ok' => true, 'preview' => $svc->backfill(ReceiptFactsService::PARSE_PER_RUN, ReceiptFactsService::REOCR_PER_RUN, true),
                              'queued_today' => $svc->queuedToday(), 'daily_cap' => ReceiptFactsService::REOCR_DAILY_CAP]);
            break;
        case 'facts': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id required');
            $f = $svc->forExpense($id);
            echo json_encode(['ok' => true, 'facts' => $f, 'line' => ReceiptFactsService::line($f)]);
            break;
        }
        case 'backfill': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (function_exists('set_time_limit')) @set_time_limit(120);
            $parse = max(1, min(200, (int)($input['parse'] ?? ReceiptFactsService::PARSE_PER_RUN)));
            $requeue = max(0, min(ReceiptFactsService::REOCR_DAILY_CAP, (int)($input['requeue'] ?? ReceiptFactsService::REOCR_PER_RUN)));
            echo json_encode(['ok' => true, 'result' => $svc->backfill($parse, $requeue, false)]);
            break;
        }
        default:
            throw new RuntimeException('Unknown mode');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
