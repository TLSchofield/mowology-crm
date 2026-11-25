<?php
/**
 * Contact roster sync, nightly: every company and every named contact gets its client, every person gets linked to
 * their company's client, and billable records without a client get one (ContactRosterSync). Adds only what's
 * missing; never changes or removes a row. Why: the roster was filled once in June and drifted — none of the 29
 * contacts added since had a roster row (2026-11-07). "Who is the contact now" reads this list.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Clients/Cron/contact_roster_sync.php
 * Web:  /crm/cron/contact_roster_sync.php (admin only, via _guard.php)
 * Schedule: daily 5:50 am — 50 5 * * * (per business, via BusinessCrons). Registry key: contact_roster_sync.
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
    \App\Core\CronLock::acquire('contact_roster_sync');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(180);

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Clients/Services/ContactRosterSync.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('contact_roster_sync', $status, $summary,
            (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
    }
    if ($__cli) {
        echo '[' . date('H:i:s') . "] {$summary}" . ($error ? " — {$error}" : '') . "\n";
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

try {
    $n = (new ContactRosterSync(getDB()))->sync();
    $__finish('success', sprintf('Roster: %d new client(s), %d new link(s); client set on %d propert(ies), %d invoice(s), %d quote(s), %d plan(s)',
        $n['org_clients'] + $n['person_clients'],
        $n['own_links'] + $n['primary_links'] + $n['billing_links'] + $n['member_links'],
        $n['properties'], $n['invoices'], $n['quotes'], $n['job_plans']));
} catch (Throwable $e) {
    error_log('[contact_roster_sync] ' . $e->getMessage());
    $__finish('error', 'Roster sync failed', $e->getMessage());
}
