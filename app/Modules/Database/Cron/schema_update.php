<?php
/**
 * Automatic database updater: applies every pending migration file (database/migrations/NNNN_name.sql, number
 * >= SchemaUpdater::FIRST_AUTOMATIC) to ONE business's database, in order, and records it in migrations_log
 * (SchemaUpdater). Statements must be safe to run twice. A failed file stops that business's run; it is retried
 * when the file changes, or with --retry.
 *
 * CLI:  php app/Modules/Database/Cron/schema_update.php --tenant=<key> [--dry-run] [--retry]
 * Schedule: every five minutes (per business, via BusinessCrons). Registry key: schema_update.
 * Exit 0 when nothing failed, 1 otherwise. Last output line is the summary.
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

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}
require_once APP_ROOT . '/Core/CronLock.php';
\App\Core\CronLock::acquire('schema_update');
@set_time_limit(280);

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Database/Services/SchemaUpdater.php';

$__business = Tenant::isDefault() ? 'mowology' : Tenant::key();
$__dryRun = in_array('--dry-run', $argv ?? [], true);
$__retry = in_array('--retry', $argv ?? [], true);

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__dryRun): void {
    if (!$__dryRun && function_exists('recordCronRun')) {
        try {
            recordCronRun('schema_update', $status, $summary, (int)round((microtime(true) - $__cronStart) * 1000), $error, false);
        } catch (Throwable $e) {
            error_log('[schema_update] could not record the run: ' . $e->getMessage());
        }
    }
    echo $summary . "\n";
};

try {
    $r = SchemaUpdater::runHere($__business, Database::ddl(), $__dryRun, $__retry);
    if ($r['failed']) {
        $__finish('error', $r['summary'], $r['error']);
        exit(1);
    }
    $__finish($r['warnings'] ? 'warning' : 'success', $r['summary']);
} catch (Throwable $e) {
    error_log('[schema_update] ' . $e->getMessage());
    $__finish('error', 'schema_update ' . $__business . ': FAILED ' . $e->getMessage(), $e->getMessage());
    exit(1);
}
