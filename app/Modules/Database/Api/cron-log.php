<?php
/**
 * Cron Run Log API
 * /app/Modules/Database/Api/cron-log.php
 *
 * GET  ?action=latest          → last run per cron_key (for dashboard cards)
 * GET  ?action=history&key=X   → paginated run history for one cron key
 * POST ?action=record          → write a run result (called by cron scripts)
 *
 * Auth: requireLogin() + admin role.
 * POST/record is also allowed from CLI context (no HTTP auth).
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

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    // CSRF check for POST (non-record calls only; record is internal)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? ($method === 'POST' ? 'record' : 'latest');
} else {
    $method = 'POST';
    $action = 'record';
}

// ── Ensure table exists (idempotent — runs DDL only if missing) ──────────────
function ensureCronRunsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cron_key      VARCHAR(64)  NOT NULL,
        triggered_by  ENUM('cron','web') NOT NULL DEFAULT 'cron',
        status        ENUM('success','warning','error') NOT NULL DEFAULT 'success',
        duration_ms   INT UNSIGNED NULL,
        summary       VARCHAR(512) NULL,
        error_message TEXT         NULL,
        ran_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_cron_key_ran_at (cron_key, ran_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

try {
    $db = getDB();
    ensureCronRunsTable($db);

    // ── GET latest ────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'latest') {
        // One row per cron_key — the most recent run
        $rows = $db->query("
            SELECT cr1.cron_key, cr1.triggered_by, cr1.status,
                   cr1.duration_ms, cr1.summary, cr1.ran_at
            FROM cron_runs cr1
            INNER JOIN (
                SELECT cron_key, MAX(ran_at) AS max_ran
                FROM cron_runs
                GROUP BY cron_key
            ) cr2 ON cr1.cron_key = cr2.cron_key AND cr1.ran_at = cr2.max_ran
            ORDER BY cr1.cron_key
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // ── GET history ───────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'history') {
        $key    = trim($_GET['key'] ?? '');
        $limit  = min((int)($_GET['limit'] ?? 20), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        if ($key === '') {
            echo json_encode(['success' => false, 'error' => 'key required']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT id, triggered_by, status, duration_ms, summary, error_message, ran_at
            FROM cron_runs
            WHERE cron_key = ?
            ORDER BY ran_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$key, $limit, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM cron_runs WHERE cron_key = ?");
        $countStmt->execute([$key]);
        $total = (int)$countStmt->fetchColumn();

        echo json_encode(['success' => true, 'data' => $rows, 'total' => $total]);
        exit;
    }

    // ── POST record ───────────────────────────────────────────────────────────
    if ($action === 'record') {
        if (!$isCli) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            // CLI callers pass data as globals (require this file then call recordCronRun())
            echo json_encode(['success' => false, 'error' => 'Use recordCronRun() helper']);
            exit;
        }

        $allowed = ['generate_visits','auto_rollover','weather_guard','schema_snapshot',
                    'purchase_history','seo_recommendations','expense_baselines',
                    'campaign_sender','seasonal_triggers','estimating_feedback'];

        $key         = $body['cron_key']     ?? '';
        $triggeredBy = $body['triggered_by'] ?? 'cron';
        $status      = $body['status']       ?? 'success';
        $durationMs  = isset($body['duration_ms']) ? (int)$body['duration_ms'] : null;
        $summary     = isset($body['summary'])      ? substr($body['summary'], 0, 512) : null;
        $errorMsg    = $body['error_message'] ?? null;

        if (!in_array($key, $allowed, true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown cron_key: ' . $key]);
            exit;
        }
        if (!in_array($status, ['success','warning','error'], true)) $status = 'success';
        if (!in_array($triggeredBy, ['cron','web'], true)) $triggeredBy = 'cron';

        $stmt = $db->prepare("
            INSERT INTO cron_runs (cron_key, triggered_by, status, duration_ms, summary, error_message, ran_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$key, $triggeredBy, $status, $durationMs, $summary, $errorMsg]);

        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
