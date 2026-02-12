<?php
/**
 * /crm/cron/schema_snapshot.php
 * Daily Schema Snapshot Generator
 *
 * Purpose:
 *   Query INFORMATION_SCHEMA to capture the live database structure
 *   Write snapshot to /storage/schema_snapshot.json
 *   Archive previous snapshots for drift detection
 *   Idempotent: safe to run multiple times per day
 *
 * Run via cron (daily @ 4 AM):
 *   0 4 * * * php /home/mowology/public_html/crm/cron/schema_snapshot.php
 *
 * Also callable via web with admin auth + CSRF token (POST only)
 */

declare(strict_types=1);

// ============================================================================
// CONTEXT + SAFE JSON RESPONDER
// ============================================================================

$isCli = (php_sapi_name() === 'cli');

function jsonRespond(bool $success, string $message, array $extra = [], int $httpCode = 200): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

// Catch fatals that normally produce a blank response in web mode
if (!$isCli) {
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $msg = "Fatal error: {$err['message']} in {$err['file']}:{$err['line']}";
            jsonRespond(false, $msg, ['error' => $err], 500);
        }
    });
}

error_reporting(E_ALL);
ini_set('display_errors', $isCli ? '1' : '0');
ini_set('log_errors', '1');

// ============================================================================
// INITIALIZATION
// ============================================================================

try {
    require_once dirname(__DIR__) . '/../app_config/config.php';
    require_once dirname(__DIR__) . '/includes/schema-functions.php';
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "[FATAL] Bootstrap include failed: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    jsonRespond(false, "Bootstrap include failed: " . $e->getMessage(), [], 500);
}

// ============================================================================
// WEB SECURITY (admin + CSRF, POST only)
// ============================================================================

$user = ['id' => null, 'role' => 'system'];

if (!$isCli) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonRespond(false, 'POST required', [], 405);
    }

    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    requireLogin();
    $user = getCurrentUser();

    if (!$user || ($user['role'] ?? '') !== 'admin') {
        jsonRespond(false, 'Admin access required', [], 403);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrfToken)) {
        jsonRespond(false, 'CSRF token invalid', [], 403);
    }
}

// ============================================================================
// RUN SNAPSHOT GENERATION
// ============================================================================

$startTime = microtime(true);

if ($isCli) {
    echo "[START] Schema Snapshot Generator\n";
    echo "[INFO]  " . date('Y-m-d H:i:s') . "\n";
}

try {
    $db = getDB();
    $storagePath = getStoragePath();

    if ($isCli) {
        echo "[STEP]  Querying INFORMATION_SCHEMA...\n";
    }

    // Generate snapshot from live database
    $snapshot = getSchemaSnapshot($db);

    if ($isCli) {
        echo "[STEP]  Found {$snapshot['summary']['table_count']} tables, "
           . "{$snapshot['summary']['total_columns']} columns, "
           . "{$snapshot['summary']['total_indexes']} indexes, "
           . "{$snapshot['summary']['total_foreign_keys']} foreign keys\n";
        echo "[STEP]  Writing snapshot to {$storagePath}/schema_snapshot.json...\n";
    }

    // Write snapshot to disk (archives previous)
    $written = writeSchemaSnapshot($snapshot, $storagePath);

    if (!$written) {
        throw new RuntimeException("Failed to write snapshot file to {$storagePath}/schema_snapshot.json");
    }

    $runtime = round(microtime(true) - $startTime, 2);

    $stats = [
        'tables' => $snapshot['summary']['table_count'],
        'columns' => $snapshot['summary']['total_columns'],
        'indexes' => $snapshot['summary']['total_indexes'],
        'foreign_keys' => $snapshot['summary']['total_foreign_keys'],
        'total_rows' => $snapshot['summary']['total_rows'],
        'total_size_mb' => $snapshot['summary']['total_size_mb'],
        'runtime_seconds' => $runtime,
    ];

    $message = sprintf(
        "Schema Snapshot: %d tables, %d columns, %d indexes, %d FKs in %ss",
        $stats['tables'],
        $stats['columns'],
        $stats['indexes'],
        $stats['foreign_keys'],
        $stats['runtime_seconds']
    );

    if ($isCli) {
        echo "[DONE]  {$message}\n";
        exit(0);
    }

    jsonRespond(true, $message, ['stats' => $stats]);

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    error_log("[schema_snapshot] FAILED: {$errorMsg}");

    if ($isCli) {
        fwrite(STDERR, "[ERROR] {$errorMsg}\n");
        exit(1);
    }

    jsonRespond(false, "Schema snapshot failed: {$errorMsg}", [], 500);
}
