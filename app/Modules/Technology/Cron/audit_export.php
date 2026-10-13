<?php
/**
 * audit_export — append yesterday's (and any missed days') activity_log rows to the
 * append-only copy outside the web root (Fable security audit #25c, 2026-10-12).
 * See AuditExportService for the file, the hash chain and why it is never truncated.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Technology/Cron/audit_export.php
 * Web:  /crm/cron/audit_export.php (admin only, POST + CSRF via _guard.php)
 * cPanel schedule: daily 00:20 (20 0 * * *)
 * Registry key: audit_export (public/crm/database_appstack.php) — recordCronRun() on every exit.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
$__cronStart = microtime(true);
require_once APP_ROOT . '/Core/config.php';

$__cli = php_sapi_name() === 'cli';
if ($__cli) {
    require_once APP_ROOT . '/Core/CronLock.php';
    \App\Core\CronLock::acquire('audit_export');
} else {
    header('Content-Type: application/json');
}
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Technology/Services/AuditExportService.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    recordCronRun('audit_export', $status, $summary, (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
    if ($__cli) {
        echo '[' . date('H:i:s') . "] {$summary}" . ($error ? " — {$error}" : '') . "\n";
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

try {
    $svc = new AuditExportService(getDB());
    $r = $svc->run();
    $where = $r['inside_web_root'] ? 'inside the web root (denied by .htaccess) at ' : '';
    $summary = $r['exported']
        ? "Appended {$r['exported']} activity row(s) #{$r['first_id']}–#{$r['last_id']} to {$where}{$r['file']}"
        : "Nothing new before today; copy is at #" . ($r['last_id'] ?? 0) . " in {$where}{$r['file']}";
    // Outside the web root is the goal; the fallback works but is worth a warning.
    $__finish($r['inside_web_root'] ? 'warning' : 'success', $summary);
} catch (Throwable $e) {
    error_log('audit_export failed: ' . $e->getMessage());
    $__finish('error', 'Audit copy not written', $e->getMessage());
}
