<?php
/**
 * Generate Visits — Cron Job
 * /app/Modules/Jobs/Cron/generate_visits.php
 *
 * Generates future job visits for all active plans up to 42 days ahead.
 * This was formerly called synchronously on every schedule.php page load;
 * it now runs as a scheduled cron job so the schedule page is never blocked.
 *
 * Recommended cPanel cron schedule: every 6 hours
 *   0 */6 * * *   php /home/mowology/app/Modules/Jobs/Cron/generate_visits.php
 *
 * Supports both CLI and web POST execution:
 *   CLI:  php generate_visits.php
 *   Web:  POST /crm/cron/generate_visits.php  (admin role required)
 *
 * Output (CLI): plain-text summary
 * Output (web): JSON { success, plans_processed, visits_created, errors[], run_at }
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

// Fatal error handler — convert E_ERROR to catchable exceptions
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Determine execution mode
$isCli = (php_sapi_name() === 'cli');

// Web mode: POST only + admin auth
if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        exit;
    }

    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    header('Content-Type: application/json');
} else {
    // CLI: bootstrap DB + helper functions without HTTP auth
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
}

// Load plan functions (generateVisits, isVisitHorizonCurrent, etc.)
require_once CRM_INCLUDES . '/plan-functions.php';

try {
    $start = microtime(true);

    // generateVisits(planId=null, horizonDays=42) processes ALL active plans
    $result = generateVisits(null, 42);

    $elapsed = round(microtime(true) - $start, 2);

    $output = [
        'success'         => true,
        'plans_processed' => $result['plans_processed'],
        'visits_created'  => $result['visits_created'],
        'errors'          => $result['errors'],
        'elapsed_seconds' => $elapsed,
        'run_at'          => date('Y-m-d H:i:s'),
    ];

    error_log(sprintf(
        '[generate_visits cron] plans_processed=%d visits_created=%d errors=%d elapsed=%.2fs',
        $result['plans_processed'],
        $result['visits_created'],
        count($result['errors']),
        $elapsed
    ));

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            error_log('[generate_visits cron] ERROR: ' . $err);
        }
    }

    $hasErrors = !empty($result['errors']);
    recordCronRun(
        'generate_visits',
        $hasErrors ? 'warning' : 'success',
        "{$result['plans_processed']} plans processed, {$result['visits_created']} visits created",
        (int)round($elapsed * 1000),
        $hasErrors ? implode('; ', array_slice($result['errors'], 0, 3)) : null,
        !$isCli
    );

    if ($isCli) {
        echo "Generate visits complete: {$result['plans_processed']} plans processed, {$result['visits_created']} visits created ({$elapsed}s)\n";
        if (!empty($result['errors'])) {
            echo "Errors:\n";
            foreach ($result['errors'] as $err) {
                echo "  ! {$err}\n";
            }
        }
    } else {
        echo json_encode($output, JSON_PRETTY_PRINT);
    }

} catch (Throwable $e) {
    $error = 'generate_visits fatal error: ' . $e->getMessage();
    error_log($error . ' in ' . $e->getFile() . ':' . $e->getLine());

    recordCronRun('generate_visits', 'error', 'Fatal error', null, $error, !$isCli);

    if ($isCli) {
        echo "FATAL: {$error}\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => $error,
        ]);
    }
}
