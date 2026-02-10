<?php
/**
 * Email Logger - Writes email details to a visible file for debugging
 *
 * This helps diagnose email sending issues on shared hosting where
 * server logs aren't easily accessible
 */

declare(strict_types=1);

/**
 * Log email send attempt to a file
 */
function logEmailAttempt(
    string $to,
    string $subject,
    string $htmlBody,
    string $headers,
    bool $result
): void {
    try {
        $logDir = dirname(__DIR__) . '/email-logs';

        // Create directory if it doesn't exist
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Create a readable log file
        $timestamp = date('Y-m-d H:i:s');
        $filename = $logDir . '/email-' . date('Y-m-d') . '.log';

        $logEntry = "\n" . str_repeat('=', 80) . "\n";
        $logEntry .= "TIMESTAMP: {$timestamp}\n";
        $logEntry .= "RECIPIENT: {$to}\n";
        $logEntry .= "SUBJECT: {$subject}\n";
        $logEntry .= "RESULT: " . ($result ? "✅ ACCEPTED (mail() returned TRUE)" : "❌ FAILED (mail() returned FALSE)") . "\n";
        $logEntry .= "HEADERS:\n" . $headers . "\n";
        $logEntry .= "BODY PREVIEW:\n" . substr(strip_tags($htmlBody), 0, 500) . "...\n";
        $logEntry .= "BODY LENGTH: " . strlen($htmlBody) . " characters\n";
        $logEntry .= str_repeat('=', 80) . "\n";

        file_put_contents($filename, $logEntry, FILE_APPEND);

    } catch (Throwable $e) {
        error_log("Email logger error: " . $e->getMessage());
    }
}
