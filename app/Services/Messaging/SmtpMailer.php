<?php
/**
 * Email Service — PHPMailer SMTP + mail() Fallback
 *
 * Primary: PHPMailer via authenticated SMTP (mail.mowology.ca:465)
 * Fallback: Native PHP mail() if PHPMailer unavailable or throws an error
 *
 * All CRM email sending flows through sendEmailViaSMTP().
 * The alias sendCrmEmail() ensures backward compatibility.
 *
 * SMTP credentials loaded from secrets.php (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS)
 * From address: no-reply@mowology.ca (verified working)
 * Reply-To: office@mowology.ca
 *
 * Migrated from: public/crm/includes/smtp_mailer.php
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

require_once APP_ROOT . '/Services/Messaging/EmailLogger.php';

// ─── PHPMailer Loader ────────────────────────────────────────────────

/**
 * Load PHPMailer vendor files and return true if available
 */
function _loadPhpMailer(): bool
{
    static $loaded = null;

    if ($loaded !== null) {
        return $loaded;
    }

    $possibleBases = [
        PUBLIC_ROOT . '/crm/vendor/phpmailer/src',
        PUBLIC_ROOT . '/vendor/phpmailer/src',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
        dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
    ];

    foreach ($possibleBases as $base) {
        if (file_exists($base . '/PHPMailer.php')) {
            require_once $base . '/Exception.php';
            require_once $base . '/PHPMailer.php';
            require_once $base . '/SMTP.php';
            $loaded = true;
            return true;
        }
    }

    $loaded = false;
    return false;
}

/**
 * Create a pre-configured PHPMailer instance
 *
 * @return \PHPMailer\PHPMailer\PHPMailer|null  Configured instance or null if unavailable
 */
function _createMailer(): ?\PHPMailer\PHPMailer\PHPMailer
{
    if (!_loadPhpMailer()) {
        return null;
    }

    $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
    $smtpPort = defined('SMTP_PORT') ? (int)SMTP_PORT : null;
    $smtpUser = defined('SMTP_USER') ? SMTP_USER : null;
    $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : null;

    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        return null;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)$smtpHost;
        $mail->Port       = $smtpPort ?: 465;
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$smtpUser;
        $mail->Password   = (string)$smtpPass;
        $mail->SMTPSecure = ($mail->Port === 465)
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = '8bit';
        $mail->XMailer    = 'Mowology CRM';

        // Default addresses
        $mail->setFrom('no-reply@mowology.ca', 'Mowology');
        $mail->addReplyTo('office@mowology.ca', 'Mowology');

        return $mail;
    } catch (\Throwable $e) {
        error_log("_createMailer error: " . $e->getMessage());
        return null;
    }
}


// ─── Public API ──────────────────────────────────────────────────────

/**
 * Send email via PHPMailer SMTP with fallback to native mail()
 *
 * @param string      $to             Recipient email
 * @param string      $subject        Email subject
 * @param string      $htmlBody       HTML email body
 * @param string|null $attachmentPath Full filesystem path to PDF (or null)
 * @param string      $from           From display name (default: 'Mowology')
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
        // Validate email
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("sendEmailViaSMTP: invalid recipient email: {$to}");
            return false;
        }

        // Validate attachment exists
        if ($attachmentPath && !file_exists($attachmentPath)) {
            error_log("sendEmailViaSMTP: attachment not found: {$attachmentPath}, sending without it");
            $attachmentPath = null;
        }

        $subject  = trim($subject);
        $htmlBody = trim($htmlBody);

        // Try PHPMailer first
        $result = _sendViaPhpMailer($to, $subject, $htmlBody, $attachmentPath, $from);

        if ($result !== null) {
            // PHPMailer was available and attempted the send
            return $result;
        }

        // Fallback: native mail()
        error_log("PHPMailer unavailable — falling back to native mail()");
        if ($attachmentPath) {
            return sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $from);
        }
        return sendSimpleHtmlEmail($to, $subject, $htmlBody, $from);

    } catch (Throwable $e) {
        error_log("sendEmailViaSMTP error: " . $e->getMessage());
        return false;
    }
}

/**
 * Alias for backward compatibility
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


// ─── PHPMailer Send ──────────────────────────────────────────────────

/**
 * Send via PHPMailer SMTP
 *
 * @return bool|null  true/false on success/failure, null if PHPMailer unavailable
 */
function _sendViaPhpMailer(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath,
    string $from
): ?bool {
    $mail = _createMailer();
    if (!$mail) {
        return null; // PHPMailer not available — caller should use fallback
    }

    try {
        // Override from name if provided
        $mail->setFrom('no-reply@mowology.ca', $from);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;

        // Add attachment if provided
        if ($attachmentPath) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
            error_log("PHPMailer: attachment added — " . basename($attachmentPath));
        }

        error_log("=== PHPMAILER SEND ===");
        error_log("To: {$to}");
        error_log("Subject: {$subject}");
        error_log("Attachment: " . ($attachmentPath ? basename($attachmentPath) : 'none'));

        $result = $mail->send();

        // Log for debugging
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer SMTP (' . ($mail->Host ?? '') . ')', $result);

        if ($result) {
            error_log("PHPMailer: message sent successfully to {$to}");
        } else {
            error_log("PHPMailer: send failed — " . $mail->ErrorInfo);
        }

        return $result;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer SMTP (FAILED)', false);

        // Fall back to native mail()
        error_log("Falling back to native mail() after PHPMailer error");
        if ($attachmentPath) {
            return sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $from);
        }
        return sendSimpleHtmlEmail($to, $subject, $htmlBody, $from);

    } catch (Throwable $e) {
        error_log("PHPMailer unexpected error: " . $e->getMessage());
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer (ERROR)', false);

        // Fall back to native mail()
        if ($attachmentPath) {
            return sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $from);
        }
        return sendSimpleHtmlEmail($to, $subject, $htmlBody, $from);
    }
}


// ─── Native mail() Fallback Functions ────────────────────────────────

/**
 * Send simple HTML email via native mail() (fallback)
 */
if (!function_exists('_stripHeaderInjection')) {
    /**
     * Strip CR/LF (and other control characters) from a value before it's
     * interpolated into a raw mail() header or subject line, so a request-derived
     * string can't inject extra headers into the native-mail() fallback path.
     */
    function _stripHeaderInjection(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1F]+/', ' ', $value));
    }
}

function sendSimpleHtmlEmail(
    string $to,
    string $subject,
    string $htmlBody,
    string $from = 'Mowology'
): bool {
    try {
        $fromEmail = 'no-reply@mowology.ca';
        $from      = _stripHeaderInjection($from);
        $subject   = _stripHeaderInjection($subject);

        $headers = "From: {$from} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";

        $subject  = trim($subject);
        $htmlBody = trim($htmlBody);

        error_log("=== FALLBACK mail() SEND ===");
        error_log("To: {$to}, Subject: {$subject}");

        $result = @mail($to, $subject, $htmlBody, $headers);

        logEmailAttempt($to, $subject, $htmlBody, $headers, $result);

        if ($result) {
            error_log("Fallback mail(): accepted for {$to}");
        } else {
            error_log("Fallback mail(): FAILED for {$to}");
        }

        return $result;

    } catch (Throwable $e) {
        error_log("sendSimpleHtmlEmail error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email with MIME attachment via native mail() (fallback)
 */
function sendEmailWithAttachment(
    string $to,
    string $subject,
    string $htmlBody,
    string $attachmentPath,
    string $from = 'Mowology'
): bool {
    try {
        $from     = _stripHeaderInjection($from);
        $subject  = _stripHeaderInjection($subject);
        $filename = basename($attachmentPath);

        $fileContent = file_get_contents($attachmentPath);
        if ($fileContent === false) {
            error_log("sendEmailWithAttachment: failed to read file {$attachmentPath}");
            return false;
        }

        $encodedFile = chunk_split(base64_encode($fileContent), 76, "\r\n");
        $boundary = '==BOUNDARY_' . md5((string)time() . $to . (string)rand()) . '==';

        $body = "This is a MIME encoded message.\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $encodedFile . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $headers = "From: {$from} <office@mowology.ca>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "Return-Path: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";

        $subject = trim($subject);

        $result = mail($to, $subject, $body, $headers);

        if ($result) {
            error_log("Fallback mail() with attachment: sent to {$to}, file: {$filename}");
        } else {
            error_log("Fallback mail() with attachment: FAILED for {$to}, file: {$filename}");
        }

        return $result;

    } catch (Throwable $e) {
        error_log("sendEmailWithAttachment error: " . $e->getMessage());
        return false;
    }
}


// ─── Test Helper ─────────────────────────────────────────────────────

/**
 * Test email configuration — sends a test email
 */
function testEmailConfig(string $testEmail): array
{
    $subject = "Mowology CRM Email Test";
    $body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Email Configuration Test</h2>
            <p>If you received this email, the email system is working correctly.</p>
            <p><strong>Method:</strong> " . (_loadPhpMailer() ? 'PHPMailer SMTP' : 'Native mail()') . "</p>
            <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p><strong>Server:</strong> " . gethostname() . "</p>
        </body>
        </html>
    ";

    $result = sendEmailViaSMTP($testEmail, $subject, $body);

    return [
        'success'   => $result,
        'method'    => _loadPhpMailer() ? 'PHPMailer SMTP' : 'Native mail()',
        'timestamp' => date('Y-m-d H:i:s'),
        'recipient' => $testEmail,
        'message'   => $result ? 'Test email sent successfully' : 'Test email failed to send'
    ];
}
