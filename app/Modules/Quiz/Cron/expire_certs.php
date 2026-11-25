<?php
/**
 * Expire Certifications — Cron Job
 *
 * Marks cert_records past expires_at as 'expired' (CertificationService::expireOverdueCerts,
 * which existed with no caller — lapsed certs read "active" forever). Also counts the
 * certs expiring in the next 30 days for the run summary. Sends nothing to anyone:
 * Otto turns lapsed and soon-due safety certs into tasks for the owner.
 *
 * Cron: nightly
 *   15 2 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Quiz/Cron/expire_certs.php
 * Web: POST /crm/cron/expire_certs.php (admin only, the Database Manager's Run Now).
 *
 * Records a run (key 'expire_certs') on EVERY exit path — a silent healthy no-op reads as
 * "Never run" otherwise (vault: Known-Failure-Patterns, cron recording).
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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';

$__ecCli   = (php_sapi_name() === 'cli');
$__ecStart = microtime(true);

/** Record the run — called once on every way out of this file. */
function __expireCertsRecord(string $status, string $summary, ?string $err = null): void
{
    global $__ecCli, $__ecStart;
    if (!function_exists('recordCronRun')) {
        require_once APP_ROOT . '/Services/CrmFunctions.php';
    }
    if (function_exists('recordCronRun')) {
        try {
            recordCronRun('expire_certs', $status, $summary, (int)round((microtime(true) - $__ecStart) * 1000), $err, !$__ecCli);
        } catch (Throwable $e) {
            error_log('expire_certs: could not record the run: ' . $e->getMessage());
        }
    }
}

function __expireCertsOut(array $payload): void
{
    global $__ecCli;
    if ($__ecCli) {
        echo json_encode($payload) . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
}

try {
    if (!$__ecCli) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            __expireCertsRecord('warning', 'Refused: web call without POST');
            __expireCertsOut(['success' => false, 'error' => 'POST method required']);
            return;
        }
        require_once PUBLIC_ROOT . '/loginAuth/auth.php';
        requireLogin();
        $user = getCurrentUser();
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            __expireCertsRecord('warning', 'Refused: not an admin');
            __expireCertsOut(['success' => false, 'error' => 'Admin access required']);
            return;
        }
    }
    // recordCronRun() is NOT in the CLI bootstrap chain — load it explicitly.
    require_once APP_ROOT . '/Services/CrmFunctions.php';
    require_once APP_ROOT . '/Modules/Quiz/Services/CertificationService.php';

    $db = getDB();
    if ($db->query("SHOW TABLES LIKE 'cert_records'")->rowCount() === 0) {
        __expireCertsRecord('success', 'No cert_records table — nothing to do');
        __expireCertsOut(['success' => true, 'expired' => 0, 'expiring_30d' => 0, 'note' => 'no cert_records table']);
        return;
    }
    $svc = new CertificationService($db);
    $expired = $svc->expireOverdueCerts();
    $soon = count($svc->findExpiringCerts(30));
    $summary = "{$expired} expired, {$soon} expiring within 30 days";
    __expireCertsRecord('success', $summary);
    __expireCertsOut(['success' => true, 'expired' => $expired, 'expiring_30d' => $soon]);
} catch (Throwable $e) {
    error_log('expire_certs cron: ' . $e->getMessage());
    __expireCertsRecord('error', 'Failed', $e->getMessage());
    if (!$__ecCli) http_response_code(500);
    __expireCertsOut(['success' => false, 'error' => 'Expire certs failed']);
}
