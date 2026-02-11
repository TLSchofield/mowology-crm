<?php
/**
 * Unified Messaging Module — Email + SMS Delivery
 *
 * Consolidates all CRM email and SMS delivery into a single reusable module.
 *
 * Email: PHPMailer SMTP (primary) with native mail() fallback
 * SMS:   Canadian carrier email-to-SMS gateways via mail()
 *
 * SMTP credentials loaded from secrets.php (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS)
 * From address: no-reply@mowology.ca
 * Reply-To: office@mowology.ca
 *
 * Usage:
 *   require_once __DIR__ . '/messaging.php';
 *
 *   $result = sendEmail($to, $subject, $htmlBody);
 *   // $result = ['success' => true, 'method' => 'PHPMailer SMTP', 'error' => null]
 *
 *   $result = sendSms($phone, $message);
 *   // $result = ['success' => true, 'carrier' => 'Bell', 'attempts' => 1, 'errors' => []]
 */

declare(strict_types=1);

require_once __DIR__ . '/email_logger.php';

// ─── Canadian Carrier Email-to-SMS Gateways ─────────────────────────

const CANADIAN_SMS_GATEWAYS = [
    'Bell'      => 'txt.bellmobility.ca',
    'Rogers'    => 'sms.rogers.com',
    'Telus'     => 'msg.telus.com',
    'Koodo'     => 'msg.koodomobile.com',
    'Virgin'    => 'sms.virginmobile.ca',
    'Fido'      => 'sms.fido.ca',
    'Freedom'   => 'sms.freedommobile.ca',
    'PC Mobile' => 'sms.pcmobilecanada.com',
    'Eastlink'  => 'sms.eastlinktelecom.com',
    'SaskTel'   => 'sms.sasktel.com',
];


// ═══════════════════════════════════════════════════════════════════════
// PUBLIC API
// ═══════════════════════════════════════════════════════════════════════

/**
 * Send an HTML email via PHPMailer SMTP with mail() fallback.
 *
 * @param string      $to             Recipient email
 * @param string      $subject        Subject line
 * @param string      $htmlBody       HTML body content
 * @param string|null $attachmentPath Filesystem path to attachment (or null)
 * @param string      $fromName       Display name (default: 'Mowology')
 * @return array      ['success' => bool, 'method' => string, 'error' => string|null]
 */
function sendEmail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath = null,
    string $fromName = 'Mowology'
): array {
    // Validate email
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'method' => 'none', 'error' => "Invalid recipient email: {$to}"];
    }

    // Validate attachment exists
    if ($attachmentPath && !file_exists($attachmentPath)) {
        error_log("sendEmail: attachment not found: {$attachmentPath}, sending without it");
        $attachmentPath = null;
    }

    $subject  = trim($subject);
    $htmlBody = trim($htmlBody);

    // Try PHPMailer SMTP first
    $phpMailerResult = _sendViaPhpMailer($to, $subject, $htmlBody, $attachmentPath, $fromName);

    if ($phpMailerResult !== null) {
        // PHPMailer was available and attempted the send
        return [
            'success' => $phpMailerResult['success'],
            'method'  => 'PHPMailer SMTP',
            'error'   => $phpMailerResult['error'] ?? null,
        ];
    }

    // Fallback: native mail()
    error_log("PHPMailer unavailable — falling back to native mail()");

    if ($attachmentPath) {
        $result = _sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $fromName);
    } else {
        $result = _sendSimpleHtmlEmail($to, $subject, $htmlBody, $fromName);
    }

    return [
        'success' => $result,
        'method'  => 'native mail()',
        'error'   => $result ? null : 'mail() returned false',
    ];
}

/**
 * Send SMS via Canadian carrier email-to-SMS gateways.
 *
 * Tries carriers in order and stops after the first one accepts the message.
 *
 * @param string $phone      Phone number (any format — digits extracted)
 * @param string $message    SMS text (truncated to 160 chars)
 * @param string $senderName From display name (default: 'Mowology')
 * @return array ['success' => bool, 'carrier' => string|null, 'attempts' => int, 'errors' => string[]]
 */
function sendSms(
    string $phone,
    string $message,
    string $senderName = 'Mowology'
): array {
    // Normalize phone: extract digits only
    $cleanPhone = preg_replace('/\D/', '', $phone);

    // Strip leading country code 1
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
        $cleanPhone = substr($cleanPhone, 1);
    }

    if (strlen($cleanPhone) !== 10) {
        return [
            'success' => false,
            'carrier' => null,
            'attempts' => 0,
            'errors' => ['Invalid phone number format. Expected 10 digits (North American).'],
        ];
    }

    if (empty($message)) {
        return [
            'success' => false,
            'carrier' => null,
            'attempts' => 0,
            'errors' => ['Message cannot be empty'],
        ];
    }

    // Truncate to SMS limit
    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
    }

    $attempts = 0;
    $errors = [];
    $sentCarriers = [];
    $fromEmail = 'no-reply@mowology.ca';

    // Use native mail() for SMS gateways — NOT PHPMailer.
    // PHPMailer adds MIME headers (Content-Type, MIME-Version, Message-ID, etc.)
    // that carrier email-to-SMS gateways reject. Native mail() with minimal
    // headers is exactly what worked in the diagnostics test yesterday.
    // Send to ALL carriers since we can't detect which one the recipient uses.
    foreach (CANADIAN_SMS_GATEWAYS as $carrierName => $smsDomain) {
        $attempts++;
        $smsRecipient = $cleanPhone . '@' . $smsDomain;

        try {
            $headers = "From: {$senderName} <{$fromEmail}>\r\n"
                     . "X-Mailer: Mowology SMS Gateway\r\n";

            $result = @mail($smsRecipient, '', $message, $headers);

            if ($result) {
                $sentCarriers[] = $carrierName;
            } else {
                $errors[] = "{$carrierName}: mail() returned false";
            }
        } catch (\Throwable $e) {
            $errors[] = "{$carrierName}: " . $e->getMessage();
        }
    }

    $success = !empty($sentCarriers);

    if ($success) {
        error_log("SMS: sent to {$phone} via " . implode(', ', $sentCarriers));
    } else {
        error_log("SMS: failed to send to {$phone} — all carriers rejected");
    }

    return [
        'success' => $success,
        'carrier' => $success ? $sentCarriers[0] : null,
        'attempts' => $attempts,
        'errors' => $errors,
    ];
}


// ═══════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════

/**
 * Check if a contact has SMS consent.
 *
 * @param int $contactId Contact ID from contacts table
 * @return bool True if customer consented to SMS
 */
function hasSmConsent(int $contactId): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT receive_sms, consent_sms
            FROM contacts
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            return false;
        }

        return !empty($contact['receive_sms']) || !empty($contact['consent_sms']);
    } catch (\Throwable $e) {
        error_log("SMS consent check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a company prefers PDF attachments on emails.
 *
 * @param int $companyId
 * @return bool
 */
function companyPrefersAttachment(int $companyId): bool
{
    if (!$companyId) {
        return true;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT pref_attach_pdf FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (bool)($row['pref_attach_pdf'] ?? 1);
    } catch (\PDOException $e) {
        return true;
    }
}

/**
 * Send a quote notification SMS with a shortened URL.
 *
 * @param string $phoneNumber
 * @param string $quoteNumber
 * @param string $quoteUrl
 * @param string $companyName
 * @return array Result from sendSms()
 */
function sendQuoteNotificationSms(
    string $phoneNumber,
    string $quoteNumber,
    string $quoteUrl,
    string $companyName = 'Mowology'
): array {
    $shortUrl = parse_url($quoteUrl, PHP_URL_HOST) . '/quote';

    $message = "Hi! Your quote {$quoteNumber} from {$companyName} is ready. "
             . "View and accept it here: {$shortUrl}";

    if (strlen($message) > 160) {
        $message = "Your quote {$quoteNumber} from {$companyName} is ready. "
                 . "Visit your account to view: {$shortUrl}";
    }

    return sendSms($phoneNumber, $message, $companyName);
}

/**
 * Test email configuration — sends a test email.
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

    $result = sendEmail($testEmail, $subject, $body);

    return [
        'success'   => $result['success'],
        'method'    => $result['method'],
        'timestamp' => date('Y-m-d H:i:s'),
        'recipient' => $testEmail,
        'message'   => $result['success'] ? 'Test email sent successfully' : ('Test email failed: ' . ($result['error'] ?? 'unknown')),
    ];
}

/**
 * Test SMS gateway to a specific carrier.
 */
function testSmsGateway(string $phoneNumber, string $carrierName = 'all'): array
{
    $message = "Test SMS from Mowology at " . date('H:i:s');

    if ($carrierName !== 'all' && isset(CANADIAN_SMS_GATEWAYS[$carrierName])) {
        $cleanPhone = preg_replace('/\D/', '', $phoneNumber);
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
            $cleanPhone = substr($cleanPhone, 1);
        }

        $domain = CANADIAN_SMS_GATEWAYS[$carrierName];
        $smsRecipient = $cleanPhone . '@' . $domain;

        $result = mail($smsRecipient, "Test", $message, "From: Mowology <no-reply@mowology.ca>\r\n");

        return [
            'success' => $result,
            'carrier' => $carrierName,
            'recipient' => $smsRecipient,
            'message' => $result ? "Test sent successfully" : "Test failed",
        ];
    }

    return sendSms($phoneNumber, $message);
}


// ═══════════════════════════════════════════════════════════════════════
// BACKWARD COMPATIBILITY ALIASES
// ═══════════════════════════════════════════════════════════════════════

if (!function_exists('sendCrmEmail')) {
    /**
     * Backward-compatible alias for sendEmail().
     * Returns bool to match the original signature.
     */
    function sendCrmEmail(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $attachmentPath = null,
        string $from = 'Mowology'
    ): bool {
        $result = sendEmail($to, $subject, $htmlBody, $attachmentPath, $from);
        return $result['success'];
    }
}

if (!function_exists('sendEmailViaSMTP')) {
    /**
     * Backward-compatible alias for sendEmail().
     * Returns bool to match the original signature.
     */
    function sendEmailViaSMTP(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $attachmentPath = null,
        string $from = 'Mowology'
    ): bool {
        $result = sendEmail($to, $subject, $htmlBody, $attachmentPath, $from);
        return $result['success'];
    }
}

if (!function_exists('sendSmsViaMail')) {
    /**
     * Backward-compatible alias for sendSms().
     * Returns the same array shape as the original sms_gateway.php.
     */
    function sendSmsViaMail(
        string $phoneNumber,
        string $message,
        string $senderName = 'Mowology'
    ): array {
        $result = sendSms($phoneNumber, $message, $senderName);

        // Map to original return shape
        return [
            'success' => $result['success'],
            'attempts' => $result['attempts'],
            'delivered_carriers' => $result['carrier'] ? [$result['carrier']] : [],
            'errors' => $result['errors'],
        ];
    }
}


// ═══════════════════════════════════════════════════════════════════════
// INTERNAL: PHPMailer SMTP
// ═══════════════════════════════════════════════════════════════════════

/**
 * Load PHPMailer vendor files.
 * @return bool True if PHPMailer is available
 */
function _loadPhpMailer(): bool
{
    static $loaded = null;

    if ($loaded !== null) {
        return $loaded;
    }

    $possibleBases = [
        dirname(__DIR__) . '/vendor/phpmailer/src',
        dirname(dirname(dirname(__DIR__))) . '/vendor/phpmailer/src',
        dirname(dirname(__DIR__)) . '/vendor/phpmailer/src',
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
 * Create a pre-configured PHPMailer instance.
 *
 * @return \PHPMailer\PHPMailer\PHPMailer|null
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

        $mail->setFrom('no-reply@mowology.ca', 'Mowology');
        $mail->addReplyTo('office@mowology.ca', 'Mowology');

        return $mail;
    } catch (\Throwable $e) {
        error_log("_createMailer error: " . $e->getMessage());
        return null;
    }
}

/**
 * Send via PHPMailer SMTP.
 *
 * @return array|null ['success' => bool, 'error' => string|null] or null if PHPMailer unavailable
 */
function _sendViaPhpMailer(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath,
    string $fromName
): ?array {
    $mail = _createMailer();
    if (!$mail) {
        return null; // PHPMailer not available
    }

    try {
        $mail->setFrom('no-reply@mowology.ca', $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;

        if ($attachmentPath) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        $result = $mail->send();

        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer SMTP (' . ($mail->Host ?? '') . ')', $result);

        if ($result) {
            error_log("PHPMailer: sent to {$to}");
        } else {
            error_log("PHPMailer: send failed — " . $mail->ErrorInfo);
        }

        return ['success' => $result, 'error' => $result ? null : $mail->ErrorInfo];

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer SMTP (FAILED)', false);

        // Fall back to native mail()
        error_log("Falling back to native mail() after PHPMailer error");
        if ($attachmentPath) {
            $fallbackResult = _sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $fromName);
        } else {
            $fallbackResult = _sendSimpleHtmlEmail($to, $subject, $htmlBody, $fromName);
        }

        return [
            'success' => $fallbackResult,
            'error' => $fallbackResult ? null : ('PHPMailer failed: ' . $e->getMessage() . '; mail() fallback also failed'),
        ];

    } catch (\Throwable $e) {
        error_log("PHPMailer unexpected error: " . $e->getMessage());
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer (ERROR)', false);

        if ($attachmentPath) {
            $fallbackResult = _sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $fromName);
        } else {
            $fallbackResult = _sendSimpleHtmlEmail($to, $subject, $htmlBody, $fromName);
        }

        return [
            'success' => $fallbackResult,
            'error' => $fallbackResult ? null : ('Error: ' . $e->getMessage()),
        ];
    }
}


// ═══════════════════════════════════════════════════════════════════════
// INTERNAL: Native mail() Fallback
// ═══════════════════════════════════════════════════════════════════════

/**
 * Send simple HTML email via native mail().
 */
function _sendSimpleHtmlEmail(
    string $to,
    string $subject,
    string $htmlBody,
    string $fromName = 'Mowology'
): bool {
    try {
        $fromEmail = 'no-reply@mowology.ca';

        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";

        $result = @mail($to, trim($subject), trim($htmlBody), $headers);

        logEmailAttempt($to, $subject, $htmlBody, $headers, $result);

        if (!$result) {
            error_log("Fallback mail(): FAILED for {$to}");
        }

        return $result;
    } catch (\Throwable $e) {
        error_log("_sendSimpleHtmlEmail error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email with MIME attachment via native mail().
 */
function _sendEmailWithAttachment(
    string $to,
    string $subject,
    string $htmlBody,
    string $attachmentPath,
    string $fromName = 'Mowology'
): bool {
    try {
        $filename = basename($attachmentPath);

        $fileContent = file_get_contents($attachmentPath);
        if ($fileContent === false) {
            error_log("_sendEmailWithAttachment: failed to read {$attachmentPath}");
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

        $headers = "From: {$fromName} <no-reply@mowology.ca>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "Return-Path: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";

        $result = mail($to, trim($subject), $body, $headers);

        if (!$result) {
            error_log("Fallback mail() with attachment: FAILED for {$to}");
        }

        return $result;
    } catch (\Throwable $e) {
        error_log("_sendEmailWithAttachment error: " . $e->getMessage());
        return false;
    }
}
