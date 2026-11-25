<?php
/**
 * Receipt Export / Archive API — manual trigger for ReceiptArchiveService.
 *
 * POST only, CSRF protected, requires expenses.send permission.
 *
 * Body: { csrf_token: string, date_from?: 'YYYY-MM-DD', date_to?: 'YYYY-MM-DD' }
 *
 * Bundles confirmed receipts in the range into ZIP(s), emails them to the configured
 * recipients (owner / accountant / bookkeeping inbox), then removes the originals from
 * the web disk (keeping a thumbnail) once delivery is confirmed. Manual runs ignore the
 * "archive after N days" age gate so a chosen range archives immediately.
 *
 * Returns: { success: bool, result: {...} }
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/messaging.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptArchiveService.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.send');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    // Validate optional date range (YYYY-MM-DD or null).
    $validDate = static function ($d): ?string {
        $d = trim((string) $d);
        if ($d === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : false;
    };
    $from = $validDate($input['date_from'] ?? '');
    $to   = $validDate($input['date_to'] ?? '');
    if ($from === false || $to === false) {
        echo json_encode(['success' => false, 'error' => 'Dates must be YYYY-MM-DD.']);
        exit;
    }
    if ($from && $to && $from > $to) {
        echo json_encode(['success' => false, 'error' => 'Start date is after end date.']);
        exit;
    }

    // Session no longer needed; the archival/email work can be slow on shared hosting.
    session_write_close();
    @set_time_limit(0);
    ignore_user_abort(true);

    $svc    = new ReceiptArchiveService(getDB());
    // afterDaysOverride = 0 → manual run archives the chosen range immediately.
    $result = $svc->run($from, $to, 'manual', (int) ($user['id'] ?? 0), 0);

    echo json_encode(['success' => true, 'result' => $result]);

} catch (Throwable $e) {
    error_log('receipt-export.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Export failed. Please try again.']);
}
