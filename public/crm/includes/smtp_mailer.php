<?php
/**
 * Email Service - Native PHP Mail Implementation
 *
 * Reliable email delivery via cPanel mail server
 * Uses office@mowology.ca for sending
 *
 * Note: PHPMailer not available on shared hosting.
 * Using native PHP mail() with proper MIME handling and RFC-compliant formatting.
 */

declare(strict_types=1);

require_once __DIR__ . '/email_logger.php';

/**
 * Send email via native PHP mail() with optional PDF attachment
 *
 * @param string      $to             Recipient email
 * @param string      $subject        Email subject
 * @param string      $htmlBody       HTML email body
 * @param string|null $attachmentPath Full filesystem path to PDF (or null)
 * @param string      $from           From display name (uses office@mowology.ca)
 * @return bool       Whether send succeeded
 */
function sendEmailViaSMTP(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath = null,
    string $from = 'Mowology'
): bool {
    try {
        // Validate email address
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("sendEmailViaSMTP: invalid recipient email: {$to}");
            return false;
        }

        // Validate attachment exists
        if ($attachmentPath && !file_exists($attachmentPath)) {
            error_log("sendEmailViaSMTP: attachment not found: {$attachmentPath}");
            $attachmentPath = null;
        }

        // Without attachment: use simple HTML email (works reliably)
        if (!$attachmentPath) {
            return sendSimpleHtmlEmail($to, $subject, $htmlBody, $from);
        }

        // With attachment: use MIME multipart
        return sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $from);

    } catch (Throwable $e) {
        error_log("sendEmailViaSMTP error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send simple HTML email (no attachment)
 * Most reliable method for shared hosting
 */
function sendSimpleHtmlEmail(
    string $to,
    string $subject,
    string $htmlBody,
    string $from = 'Mowology'
): bool {
    try {
        // Use the verified working From address for this hosting account
        // Tested and confirmed working: no-reply@mowology.ca
        $fromEmail = 'no-reply@mowology.ca';

        // RFC-compliant headers
        $headers = "From: {$from} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";

        // Clean subject
        $subject = trim($subject);

        // Clean body
        $htmlBody = trim($htmlBody);

        error_log("=== EMAIL SEND ATTEMPT ===");
        error_log("To: {$to}");
        error_log("From: {$fromEmail}");
        error_log("Subject: {$subject}");
        error_log("Headers:\n{$headers}");
        error_log("Body length: " . strlen($htmlBody) . " chars");

        // Send via native mail()
        // On shared hosting, this just queues the email - actual delivery depends on mail server config
        $result = @mail($to, $subject, $htmlBody, $headers);

        // Log to visible file for debugging (since server logs aren't accessible)
        logEmailAttempt($to, $subject, $htmlBody, $headers, $result);

        if (!$result) {
            error_log("❌ mail() FAILED - returned FALSE");
            error_log("The mail server rejected this email. Possible causes:");
            error_log("  - Invalid recipient address");
            error_log("  - SPF/DKIM authentication failure");
            error_log("  - Rate limiting");
            error_log("  - Server configuration issue");
        } else {
            error_log("✅ mail() ACCEPTED - returned TRUE");
            error_log("Email accepted by mail server queue. Delivery status unknown.");
        }

        return $result;

    } catch (Throwable $e) {
        error_log("sendSimpleHtmlEmail error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email with MIME attachment
 * For PDF quotes and other documents
 */
function sendEmailWithAttachment(
    string $to,
    string $subject,
    string $htmlBody,
    string $attachmentPath,
    string $from = 'Mowology'
): bool {
    try {
        $filename = basename($attachmentPath);

        // Read and encode file
        $fileContent = file_get_contents($attachmentPath);
        if ($fileContent === false) {
            error_log("sendEmailWithAttachment: failed to read file {$attachmentPath}");
            return false;
        }

        // Base64 encode for MIME
        $encodedFile = chunk_split(base64_encode($fileContent), 76, "\r\n");

        // Generate unique boundary
        $boundary = '==BOUNDARY_' . md5(time() . $to . rand()) . '==';

        // Build multipart MIME body
        $body = "This is a MIME encoded message.\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        // PDF attachment
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $encodedFile . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // RFC-compliant headers
        $headers = "From: {$from} <office@mowology.ca>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "Return-Path: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";

        // Clean subject
        $subject = trim($subject);

        // Send via native mail()
        $result = mail($to, $subject, $body, $headers);

        if (!$result) {
            error_log("sendEmailWithAttachment: mail() returned false for {$to}, subject: {$subject}, attachment: {$filename}");
        } else {
            error_log("Email sent successfully to {$to}, subject: {$subject}, attachment: {$filename}");
        }

        return $result;

    } catch (Throwable $e) {
        error_log("sendEmailWithAttachment error: " . $e->getMessage());
        return false;
    }
}

/**
 * Alias for backward compatibility
 * Redirects old sendCrmEmail() calls to new email service
 */
function sendCrmEmail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath = null,
    string $from = 'Mowology'
): bool {
    return sendEmailViaSMTP($to, $subject, $htmlBody, $attachmentPath, $from);
}

/**
 * Test email configuration
 * Sends a test email to verify setup
 */
function testEmailConfig(string $testEmail): array
{
    $subject = "Mowology CRM Email Test";
    $body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Email Configuration Test</h2>
            <p>If you received this email, the email system is working correctly.</p>
            <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p><strong>Server:</strong> " . gethostname() . "</p>
        </body>
        </html>
    ";

    $result = sendSimpleHtmlEmail($testEmail, $subject, $body);

    return [
        'success' => $result,
        'timestamp' => date('Y-m-d H:i:s'),
        'recipient' => $testEmail,
        'message' => $result ? 'Test email sent successfully' : 'Test email failed to send'
    ];
}
