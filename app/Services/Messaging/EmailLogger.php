<?php
/**
 * Email Logger - one line per email send attempt, for "did that email go out?"
 *
 * The line holds the time, the result, the recipient, the subject and how it was sent. It never holds the email's
 * text or its headers, and it is kept per business outside the web folder for 30 days (see EmailLog.php;
 * security audit 2026-11-08).
 *
 * Migrated from: public/crm/includes/email_logger.php
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}
require_once __DIR__ . '/EmailLog.php';

/**
 * Log email send attempt to a file. $htmlBody is only measured, never written; $headers only names the transport.
 */
function logEmailAttempt(
    string $to,
    string $subject,
    string $htmlBody,
    string $headers,
    bool $result
): void {
    try {
        EmailLog::record($to, $subject, $headers, $result, strlen($htmlBody));
    } catch (Throwable $e) {
        error_log("Email logger error: " . $e->getMessage());
    }
}
