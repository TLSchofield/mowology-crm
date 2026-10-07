<?php
/**
 * Charlie (Foreman) — urgent alerts every run; the morning brief once, at 7 am Mon–Fri.
 *
 * Every run: the urgent list (failed payments over the threshold, same-day weather) —
 * anything not yet sent goes to the owner by email at once (CharlieUrgentService).
 * Hourly (runs in the first quarter of the hour, or ?force=1): ask every head, remember
 * what they said and learn from what was dealt with (CharlieDeskService::today, via
 * CharlieForemanService so bad news leads). Then, if it's a weekday between 07:00 and
 * 09:59 America/Vancouver and today's brief hasn't been emailed, email it to the owner
 * (ops_settings charlie_owner_user_id → users.email) through sendEmail(). The window past
 * 07:59 only catches up a missed 7 o'clock run; the brief still goes once.
 * Gating in PHP means the cPanel server's own timezone doesn't matter.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php
 * Web:  /crm/cron/charlie_morning_brief.php (admin only, via _guard.php); ?force=1 skips the time gates
 * cPanel schedule: every 15 minutes (2,17,32,47 * * * *) for prompt urgent alerts —
 *                  hourly (2 * * * *) still works, alerts just wait up to an hour.
 * Registry key: charlie_morning_brief (public/crm/database_appstack.php) — recordCronRun() on every exit.
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
    \App\Core\CronLock::acquire('charlie_morning_brief');
} else {
    header('Content-Type: application/json');
}

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
// recordCronRun() and getWorkQueueItems() live here; the CLI bootstrap does NOT load it.
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieForemanService.php';
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieBriefEmail.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('charlie_morning_brief', $status, $summary,
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
    $db = getDB();
    $f = new CharlieForemanService($db);
    $desk = $f->desk;
    if (!$desk->ready()) {
        $__finish('warning', 'Migration 1170 not run yet — nothing to do');
        exit;
    }
    $owner = $desk->owner();
    if (!$owner) {
        $__finish('warning', 'No owner found (ops_settings charlie_owner_user_id) — nothing to do');
        exit;
    }
    $name = CharlieVoice::firstName($owner);
    $force = !$__cli && !empty($_GET['force']);

    $company = EmailWrapper::getCompanyInfo();
    $base = 'https://mowology.ca';
    if (!empty($company['company_website'])) {
        $w = trim((string)$company['company_website']);
        $base = preg_match('#^https?://#', $w) ? $w : 'https://' . $w;
    }

    // Urgent — every run.
    $parts = [];
    $u = $f->sendUrgent($owner, $base, $company);
    if ($u['sent'] > 0) $parts[] = $u['sent'] . ' urgent alert' . ($u['sent'] === 1 ? '' : 's') . ' emailed';
    if ($u['error']) $parts[] = 'urgent alert failed: ' . $u['error'];

    // Brief — hourly, and the email once on weekday mornings.
    if (!$force && (int)date('i') >= 15) {
        $__finish($u['error'] ? 'warning' : 'success', ($parts ? implode('; ', $parts) . '; ' : '') . 'urgent check only (brief runs at the top of the hour)');
        exit;
    }
    $view = $f->card($name)['view'];
    $parts[] = $view['total'] . ' item' . ($view['total'] === 1 ? '' : 's') . ' from ' . count($view['connected']) . ' heads'
             . ($view['failed'] ? ' (' . implode(', ', array_keys($view['failed'])) . ' failed)' : '');
    $summary = implode('; ', $parts);

    $today = date('Y-m-d');
    $brief = $desk->brief($today);
    if (!$force && !CharlieDeskService::isSendTime(date('Y-m-d H:i:s'))) {
        $__finish('success', $summary . '; not brief time');
        exit;
    }
    if ($brief && $brief['emailed_at'] !== null) {
        $__finish('success', $summary . '; brief already emailed at ' . date('g:i a', strtotime((string)$brief['emailed_at'])));
        exit;
    }
    $to = trim((string)($owner['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $desk->markEmailed($today, 'Owner has no valid email');
        $__finish('warning', $summary . '; owner has no valid email address');
        exit;
    }

    $brief = $desk->brief($today);
    $payload = json_decode((string)($brief['payload'] ?? ''), true) ?: CharlieDeskService::payload($view);
    $mail = CharlieBriefEmail::render($payload, $name, $base);
    $html = EmailWrapper::wrap($mail['body'], 'Open your dashboard', rtrim($base, '/') . '/crm/dashboard_appstack.php', $company);
    $res = sendEmail($to, $mail['subject'], $html, null, 'Charlie at ' . ($company['company_name'] ?? 'Mowology'));

    if (!empty($res['success'])) {
        $desk->markEmailed($today, null);
        $__finish('success', $summary . '; brief emailed via ' . ($res['method'] ?? 'email'));
    } else {
        $desk->markEmailed($today, (string)($res['error'] ?? 'send failed'));
        $__finish('error', $summary . '; brief email failed', (string)($res['error'] ?? 'send failed'));
    }
} catch (Throwable $e) {
    error_log('[charlie_morning_brief] ' . $e->getMessage());
    $__finish('error', 'Cron failed', $e->getMessage());
}
