<?php
/**
 * Charlie's morning brief — hourly; emails the owner once, at 7 am Vancouver, Mon–Fri.
 *
 * Every run: ask every head, remember what they said and learn from what was dealt with
 * since the last look (CharlieDeskService::today), so Charlie keeps learning on days the
 * dashboard isn't opened. Then, if it's a weekday between 07:00 and 09:59 America/Vancouver
 * and today's brief hasn't been emailed, email it to the owner (ops_settings
 * charlie_owner_user_id → users.email) through sendEmail(). The window past 07:59 only
 * catches up a missed 7 o'clock run; the brief still goes once.
 * Running hourly and gating in PHP means the cPanel server's own timezone doesn't matter.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php
 * Web:  /crm/cron/charlie_morning_brief.php (admin only, via _guard.php); ?force=1 skips the time gate
 * cPanel schedule: hourly at :02 (2 * * * *)
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
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
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
    $desk = new CharlieDeskService($db);
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
    $view = $desk->today($name);
    $summary = $view['total'] . ' item' . ($view['total'] === 1 ? '' : 's') . ' from ' . count($view['connected']) . ' heads'
             . ($view['failed'] ? ' (' . implode(', ', array_keys($view['failed'])) . ' failed)' : '');

    $force = !$__cli && !empty($_GET['force']);
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
    $base = 'https://mowology.ca';
    require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
    $company = EmailWrapper::getCompanyInfo();
    if (!empty($company['company_website'])) {
        $w = trim((string)$company['company_website']);
        $base = preg_match('#^https?://#', $w) ? $w : 'https://' . $w;
    }
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
