<?php
/**
 * Messaging Diagnostics — Test Runner Functions
 *
 * Provides 5 diagnostic tests for the email/SMS pipeline:
 *   1. PHP mail() function test
 *   2. PHPMailer SMTP test
 *   3. Email-to-SMS gateway test
 *   4. Contact consent verification test
 *   5. Full pipeline end-to-end test
 *
 * Each test returns a standardized result array:
 *   ['status', 'message', 'details', 'duration_ms', 'error']
 */

declare(strict_types=1);

// ─── DB Helpers ──────────────────────────────────────────────────────

/**
 * Save a diagnostic test result to the database
 *
 * @param string $testType  One of: mail_function, phpmailer, sms_gateway, consent_check, full_pipeline
 * @param array  $result    Standardized result array from a test runner
 * @param int    $userId    User who ran the test
 * @return int|null         Inserted row ID or null on failure
 */
function saveTestResult(string $testType, array $result, int $userId): ?int
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO messaging_diagnostics
                (test_type, test_status, test_input, test_output, duration_ms, error_message, run_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $testType,
            $result['status'],
            json_encode($result['input'] ?? []),
            json_encode($result['details'] ?? []),
            $result['duration_ms'] ?? null,
            $result['error'] ?? null,
            $userId
        ]);
        return (int) $db->lastInsertId();
    } catch (Throwable $e) {
        error_log("saveTestResult error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get recent diagnostic test results
 *
 * @param int $limit Max rows to return
 * @return array
 */
function getRecentTestResults(int $limit = 20): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT md.*, u.full_name AS run_by_name
            FROM messaging_diagnostics md
            LEFT JOIN users u ON md.run_by = u.id
            ORDER BY md.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getRecentTestResults error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a test was run within the last N seconds (rate limiting)
 *
 * @param string $testType
 * @param int    $seconds  Cooldown period
 * @return bool  True if rate-limited (test ran too recently)
 */
function isTestRateLimited(string $testType, int $seconds = 30): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM messaging_diagnostics
            WHERE test_type = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$testType, $seconds]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get contacts with phone numbers for the consent test dropdown
 *
 * @param int $limit
 * @return array
 */
function getContactsWithPhone(int $limit = 200): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, phone, email,
                   receive_sms, consent_sms
            FROM contacts
            WHERE phone IS NOT NULL AND phone != ''
            ORDER BY first_name, last_name
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getContactsWithPhone error: " . $e->getMessage());
        return [];
    }
}


// ─── Test 1: PHP mail() Function ─────────────────────────────────────

/**
 * Test the native PHP mail() function
 *
 * @param string $recipientEmail
 * @return array Standardized result
 */
function runMailFunctionTest(string $recipientEmail): array
{
    $startTime = microtime(true);
    $details = [];
    $error = null;

    try {
        // Validate email
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'      => 'error',
                'message'     => 'Invalid recipient email address.',
                'details'     => [],
                'input'       => ['email' => $recipientEmail],
                'duration_ms' => 0,
                'error'       => 'Invalid email: ' . $recipientEmail
            ];
        }

        // Gather server info
        $details['mail_function_exists'] = function_exists('mail');
        $details['sendmail_path']        = ini_get('sendmail_path') ?: '(not set)';
        $details['smtp_server']          = ini_get('SMTP') ?: '(not set)';
        $details['smtp_port']            = ini_get('smtp_port') ?: '(not set)';
        $details['php_version']          = PHP_VERSION;
        $details['server_name']          = gethostname() ?: '(unknown)';

        if (!$details['mail_function_exists']) {
            return [
                'status'      => 'fail',
                'message'     => 'PHP mail() function is not available on this server.',
                'details'     => $details,
                'input'       => ['email' => $recipientEmail],
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'error'       => 'mail() function does not exist'
            ];
        }

        // Build test email
        $subject = 'Mowology Diagnostic: mail() Test — ' . date('H:i:s');
        $body    = '<html><body style="font-family:Arial,sans-serif;">'
                 . '<h2>PHP mail() Diagnostic Test</h2>'
                 . '<p>This email confirms that the native PHP <code>mail()</code> function is working on your server.</p>'
                 . '<p><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '</p>'
                 . '<p><strong>Server:</strong> ' . htmlspecialchars($details['server_name']) . '</p>'
                 . '</body></html>';

        $fromEmail = 'no-reply@mowology.ca';
        $headers   = "From: Mowology Diagnostics <{$fromEmail}>\r\n"
                   . "Reply-To: office@mowology.ca\r\n"
                   . "MIME-Version: 1.0\r\n"
                   . "Content-Type: text/html; charset=UTF-8\r\n"
                   . "X-Mailer: Mowology Diagnostics\r\n";

        $details['from_email'] = $fromEmail;
        $details['headers']    = $headers;
        $details['subject']    = $subject;

        // Send
        $mailResult = @mail($recipientEmail, $subject, $body, $headers);
        $details['mail_return'] = $mailResult;

        // Log via existing email logger if available
        if (function_exists('logEmailAttempt')) {
            logEmailAttempt($recipientEmail, $subject, $body, $headers, $mailResult);
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($mailResult) {
            return [
                'status'      => 'pass',
                'message'     => 'mail() accepted the message. Check inbox for delivery confirmation.',
                'details'     => $details,
                'input'       => ['email' => $recipientEmail],
                'duration_ms' => $durationMs,
                'error'       => null
            ];
        } else {
            return [
                'status'      => 'fail',
                'message'     => 'mail() returned FALSE — the server rejected the message.',
                'details'     => $details,
                'input'       => ['email' => $recipientEmail],
                'duration_ms' => $durationMs,
                'error'       => 'mail() returned false'
            ];
        }
    } catch (Throwable $e) {
        return [
            'status'      => 'error',
            'message'     => 'Exception during mail() test: ' . $e->getMessage(),
            'details'     => $details,
            'input'       => ['email' => $recipientEmail],
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'error'       => $e->getMessage()
        ];
    }
}


// ─── Test 2: PHPMailer SMTP ──────────────────────────────────────────

/**
 * Test PHPMailer via SMTP (vendor library, loaded manually)
 *
 * @param string $recipientEmail
 * @return array Standardized result
 */
function runPhpMailerTest(string $recipientEmail): array
{
    $startTime = microtime(true);
    $details   = [];
    $error     = null;

    try {
        // Validate email
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'      => 'error',
                'message'     => 'Invalid recipient email address.',
                'details'     => [],
                'input'       => ['email' => $recipientEmail],
                'duration_ms' => 0,
                'error'       => 'Invalid email: ' . $recipientEmail
            ];
        }

        // Load PHPMailer manually (no Composer autoload)
        // Try multiple possible vendor locations:
        //   1. CRM vendor (deployed with CRM): /crm/vendor/
        //   2. Repo root (local dev): project_root/vendor/
        //   3. Inside public_html (cPanel): /public_html/vendor/
        //   4. Above public_html (cPanel): /home/user/vendor/
        $possibleBases = [
            dirname(__DIR__) . '/vendor/phpmailer/src',                         // /crm/vendor/ (primary)
            dirname(dirname(dirname(__DIR__))) . '/vendor/phpmailer/src',       // repo root
            dirname(dirname(__DIR__)) . '/vendor/phpmailer/src',                // inside public/
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',        // docroot/vendor/
            dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src', // above docroot
        ];

        $vendorBase = null;
        foreach ($possibleBases as $candidate) {
            if (file_exists($candidate . '/PHPMailer.php')) {
                $vendorBase = $candidate;
                break;
            }
        }

        if (!$vendorBase) {
            $searchedPaths = implode(', ', array_map(function($p) { return $p . '/PHPMailer.php'; }, $possibleBases));
            return [
                'status'      => 'fail',
                'message'     => 'PHPMailer vendor files not found in any expected location.',
                'details'     => ['searched_paths' => $searchedPaths],
                'input'       => ['email' => $recipientEmail],
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'error'       => 'PHPMailer not found. Searched: ' . $searchedPaths
            ];
        }

        $requiredFiles = [
            $vendorBase . '/Exception.php',
            $vendorBase . '/PHPMailer.php',
            $vendorBase . '/SMTP.php',
        ];

        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                return [
                    'status'      => 'fail',
                    'message'     => 'PHPMailer vendor file not found: ' . basename($file),
                    'details'     => ['missing_file' => $file],
                    'input'       => ['email' => $recipientEmail],
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    'error'       => 'Vendor file missing: ' . $file
                ];
            }
            require_once $file;
        }

        $details['vendor_files_loaded'] = true;
        $details['vendor_path'] = $vendorBase;

        // Check SMTP constants from secrets.php
        $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
        $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : null;
        $smtpUser = defined('SMTP_USER') ? SMTP_USER : null;
        $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : null;

        $details['smtp_host']       = $smtpHost ?: '(not defined)';
        $details['smtp_port']       = $smtpPort ?: '(not defined)';
        $details['smtp_user']       = $smtpUser ?: '(not defined)';
        $details['smtp_pass_set']   = !empty($smtpPass) ? 'Yes (masked)' : 'No';

        // ── Attempt 1: SMTP mode ────────────────────────────
        $debugOutput = '';
        $smtpSuccess = false;
        $smtpError   = null;

        if ($smtpHost && $smtpUser && $smtpPass) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->Port       = (int) $smtpPort;
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtpUser;
                $mail->Password   = $smtpPass;
                $mail->SMTPSecure = ((int) $smtpPort === 465)
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

                // Capture debug output
                $mail->SMTPDebug   = 2; // SMTP::DEBUG_SERVER
                $mail->Debugoutput = function ($str, $level) use (&$debugOutput) {
                    $debugOutput .= date('H:i:s') . " [{$level}] " . $str;
                };

                $mail->setFrom($smtpUser, 'Mowology Diagnostics');
                $mail->addAddress($recipientEmail);
                $mail->Subject = 'Mowology Diagnostic: PHPMailer SMTP Test — ' . date('H:i:s');
                $mail->isHTML(true);
                $mail->Body = '<html><body style="font-family:Arial,sans-serif;">'
                    . '<h2>PHPMailer SMTP Diagnostic Test</h2>'
                    . '<p>This email was sent using PHPMailer via SMTP.</p>'
                    . '<p><strong>Host:</strong> ' . htmlspecialchars($smtpHost) . '</p>'
                    . '<p><strong>Port:</strong> ' . htmlspecialchars((string) $smtpPort) . '</p>'
                    . '<p><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '</p>'
                    . '</body></html>';

                $smtpSuccess = $mail->send();
                $details['smtp_result'] = 'pass';
            } catch (\PHPMailer\PHPMailer\Exception $e) {
                $smtpError = $e->getMessage();
                $details['smtp_result'] = 'fail';
                $details['smtp_error']  = $smtpError;
            } catch (Throwable $e) {
                $smtpError = $e->getMessage();
                $details['smtp_result'] = 'fail';
                $details['smtp_error']  = $smtpError;
            }
        } else {
            $details['smtp_result'] = 'skipped';
            $details['smtp_error']  = 'SMTP credentials not defined in secrets.php';
        }

        $details['smtp_debug_log'] = $debugOutput;

        // ── Attempt 2: PHPMailer in mail() fallback mode ─────
        $mailModeSuccess = false;
        $mailModeError   = null;

        try {
            $mail2 = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail2->isMail(); // Use PHP mail() internally
            $mail2->setFrom('no-reply@mowology.ca', 'Mowology Diagnostics');
            $mail2->addAddress($recipientEmail);
            $mail2->Subject = 'Mowology Diagnostic: PHPMailer mail() Mode — ' . date('H:i:s');
            $mail2->isHTML(true);
            $mail2->Body = '<html><body style="font-family:Arial,sans-serif;">'
                . '<h2>PHPMailer mail() Mode Test</h2>'
                . '<p>This email was sent using PHPMailer in <code>isMail()</code> mode (PHP mail() backend).</p>'
                . '<p><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '</p>'
                . '</body></html>';

            $mailModeSuccess = $mail2->send();
            $details['mail_mode_result'] = 'pass';
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $mailModeError = $e->getMessage();
            $details['mail_mode_result'] = 'fail';
            $details['mail_mode_error']  = $mailModeError;
        } catch (Throwable $e) {
            $mailModeError = $e->getMessage();
            $details['mail_mode_result'] = 'fail';
            $details['mail_mode_error']  = $mailModeError;
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Determine overall status
        if ($smtpSuccess && $mailModeSuccess) {
            $status  = 'pass';
            $message = 'PHPMailer working in both SMTP and mail() modes.';
        } elseif ($smtpSuccess || $mailModeSuccess) {
            $status  = 'partial';
            $message = 'PHPMailer working in ' . ($smtpSuccess ? 'SMTP' : 'mail()') . ' mode only.';
        } else {
            $status  = 'fail';
            $message = 'PHPMailer failed in both SMTP and mail() modes.';
        }

        return [
            'status'      => $status,
            'message'     => $message,
            'details'     => $details,
            'input'       => ['email' => $recipientEmail],
            'duration_ms' => $durationMs,
            'error'       => $smtpError ?: $mailModeError
        ];
    } catch (Throwable $e) {
        return [
            'status'      => 'error',
            'message'     => 'Exception during PHPMailer test: ' . $e->getMessage(),
            'details'     => $details,
            'input'       => ['email' => $recipientEmail],
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'error'       => $e->getMessage()
        ];
    }
}


// ─── Test 3: Email-to-SMS Gateway ────────────────────────────────────

/**
 * Test SMS delivery via email-to-SMS carrier gateways
 *
 * @param string      $phoneNumber     10-digit phone number
 * @param string|null $specificCarrier  Carrier name or null for all
 * @return array Standardized result
 */
function runSmsGatewayTest(string $phoneNumber, ?string $specificCarrier = null): array
{
    $startTime = microtime(true);
    $details   = [];

    try {
        // Normalize phone
        $cleanPhone = preg_replace('/\D/', '', $phoneNumber);
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
            $cleanPhone = substr($cleanPhone, 1);
        }

        if (strlen($cleanPhone) !== 10) {
            return [
                'status'      => 'error',
                'message'     => 'Invalid phone number. Expected 10 digits.',
                'details'     => ['raw_input' => $phoneNumber, 'cleaned' => $cleanPhone],
                'input'       => ['phone' => $phoneNumber, 'carrier' => $specificCarrier],
                'duration_ms' => 0,
                'error'       => 'Phone must be 10 digits, got ' . strlen($cleanPhone)
            ];
        }

        $details['cleaned_phone'] = $cleanPhone;
        $details['carrier_results'] = [];

        // Include SMS gateway constants
        if (!defined('CANADIAN_SMS_GATEWAYS')) {
            require_once dirname(__DIR__) . '/includes/sms_gateway.php';
        }

        $gateways = CANADIAN_SMS_GATEWAYS;

        // Filter to specific carrier if requested
        if ($specificCarrier && $specificCarrier !== 'all' && isset($gateways[$specificCarrier])) {
            $gateways = [$specificCarrier => $gateways[$specificCarrier]];
        }

        $message   = 'Mowology diagnostic SMS test at ' . date('H:i:s');
        $fromEmail = 'no-reply@mowology.ca';
        $successCount = 0;
        $failCount    = 0;

        foreach ($gateways as $carrierName => $smsDomain) {
            $carrierStart = microtime(true);
            $smsRecipient = $cleanPhone . '@' . $smsDomain;

            $headers = "From: Mowology Diagnostics <{$fromEmail}>\r\n"
                     . "X-Mailer: Mowology SMS Diagnostics\r\n";

            $result = @mail($smsRecipient, "Diagnostic Test", $message, $headers);
            $carrierMs = (int) ((microtime(true) - $carrierStart) * 1000);

            $details['carrier_results'][] = [
                'carrier'   => $carrierName,
                'domain'    => $smsDomain,
                'recipient' => $smsRecipient,
                'accepted'  => $result,
                'timing_ms' => $carrierMs
            ];

            if ($result) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        $totalCarriers = count($gateways);
        $details['total_carriers']  = $totalCarriers;
        $details['success_count']   = $successCount;
        $details['fail_count']      = $failCount;

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($successCount === $totalCarriers) {
            $status  = 'pass';
            $message = "All {$totalCarriers} carrier gateways accepted the message.";
        } elseif ($successCount > 0) {
            $status  = 'partial';
            $message = "{$successCount}/{$totalCarriers} carrier gateways accepted. {$failCount} rejected.";
        } else {
            $status  = 'fail';
            $message = "All {$totalCarriers} carrier gateways rejected the message.";
        }

        return [
            'status'      => $status,
            'message'     => $message,
            'details'     => $details,
            'input'       => ['phone' => $phoneNumber, 'carrier' => $specificCarrier],
            'duration_ms' => $durationMs,
            'error'       => $failCount > 0 ? "{$failCount} carriers rejected" : null
        ];
    } catch (Throwable $e) {
        return [
            'status'      => 'error',
            'message'     => 'Exception during SMS gateway test: ' . $e->getMessage(),
            'details'     => $details,
            'input'       => ['phone' => $phoneNumber, 'carrier' => $specificCarrier],
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'error'       => $e->getMessage()
        ];
    }
}


// ─── Test 4: Consent Verification ────────────────────────────────────

/**
 * Verify consent data and the hasSmConsent() function for a contact
 *
 * @param int $contactId
 * @return array Standardized result
 */
function runConsentVerificationTest(int $contactId): array
{
    $startTime = microtime(true);
    $details   = [];

    try {
        if ($contactId <= 0) {
            return [
                'status'      => 'error',
                'message'     => 'Invalid contact ID.',
                'details'     => [],
                'input'       => ['contact_id' => $contactId],
                'duration_ms' => 0,
                'error'       => 'Contact ID must be positive'
            ];
        }

        $db = getDB();

        // Fetch contact record
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, phone, email,
                   receive_sms, consent_sms,
                   consent_quote_followup, consent_marketing_email,
                   consent_timestamp, consent_ip_address, consent_source,
                   preferred_contact_method
            FROM contacts
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            return [
                'status'      => 'fail',
                'message'     => 'Contact not found with ID ' . $contactId,
                'details'     => [],
                'input'       => ['contact_id' => $contactId],
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'error'       => 'Contact not found'
            ];
        }

        $details['contact'] = [
            'id'         => $contact['id'],
            'name'       => trim($contact['first_name'] . ' ' . $contact['last_name']),
            'phone'      => $contact['phone'],
            'email'      => $contact['email'],
            'preferred'  => $contact['preferred_contact_method'],
        ];

        $details['consent_fields'] = [
            'receive_sms'             => (bool) $contact['receive_sms'],
            'consent_sms'             => (bool) $contact['consent_sms'],
            'consent_quote_followup'  => (bool) $contact['consent_quote_followup'],
            'consent_marketing_email' => (bool) $contact['consent_marketing_email'],
            'consent_timestamp'       => $contact['consent_timestamp'],
            'consent_ip_address'      => $contact['consent_ip_address'],
            'consent_source'          => $contact['consent_source'],
        ];

        // Fetch consent audit log
        $logStmt = $db->prepare("
            SELECT consent_type, consent_given, consent_text,
                   ip_address, user_agent, consent_source, created_at
            FROM consent_log
            WHERE contact_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $logStmt->execute([$contactId]);
        $details['consent_log'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);
        $details['consent_log_count'] = count($details['consent_log']);

        // Call the existing hasSmConsent() function
        if (!function_exists('hasSmConsent')) {
            require_once dirname(__DIR__) . '/includes/sms_gateway.php';
        }
        $hasConsent = hasSmConsent($contactId);
        $details['hasSmConsent_result'] = $hasConsent;

        // Verify consistency
        $fieldConsent = !empty($contact['receive_sms']) || !empty($contact['consent_sms']);
        $details['fields_say_consent']    = $fieldConsent;
        $details['function_says_consent'] = $hasConsent;
        $details['consistent']            = ($fieldConsent === $hasConsent);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Determine status
        if ($details['consistent']) {
            $status  = 'pass';
            $message = 'Consent data is consistent. SMS consent: ' . ($hasConsent ? 'GRANTED' : 'NOT GRANTED');
        } else {
            $status  = 'fail';
            $message = 'Inconsistency detected between consent fields and hasSmConsent() function.';
        }

        return [
            'status'      => $status,
            'message'     => $message,
            'details'     => $details,
            'input'       => ['contact_id' => $contactId],
            'duration_ms' => $durationMs,
            'error'       => !$details['consistent'] ? 'Consent data inconsistency' : null
        ];
    } catch (Throwable $e) {
        return [
            'status'      => 'error',
            'message'     => 'Exception during consent test: ' . $e->getMessage(),
            'details'     => $details,
            'input'       => ['contact_id' => $contactId],
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'error'       => $e->getMessage()
        ];
    }
}


// ─── Test 5: Full Pipeline ───────────────────────────────────────────

/**
 * Run the full messaging pipeline: Email + PHPMailer + SMS + Consent
 *
 * @param string   $recipientEmail
 * @param string   $phoneNumber
 * @param int|null $contactId       Optional contact for consent check
 * @return array Standardized result
 */
function runFullPipelineTest(string $recipientEmail, string $phoneNumber, ?int $contactId = null): array
{
    $startTime = microtime(true);
    $details   = [];

    try {
        // Step A: PHP mail() test
        $details['step_mail'] = runMailFunctionTest($recipientEmail);

        // Step B: PHPMailer test
        $details['step_phpmailer'] = runPhpMailerTest($recipientEmail);

        // Step C: SMS gateway test (test only known carrier — Telus — to avoid spam)
        $details['step_sms'] = runSmsGatewayTest($phoneNumber, 'Telus');

        // Step D: Consent check (if contact provided)
        if ($contactId && $contactId > 0) {
            $details['step_consent'] = runConsentVerificationTest($contactId);
        } else {
            $details['step_consent'] = [
                'status'  => 'partial',
                'message' => 'Skipped — no contact ID provided.',
                'details' => [],
                'duration_ms' => 0,
                'error'   => null
            ];
        }

        // Step E: Test production functions (sendEmailViaSMTP + sendSmsViaMail)
        if (!function_exists('sendEmailViaSMTP')) {
            require_once dirname(__DIR__) . '/includes/smtp_mailer.php';
        }
        if (!function_exists('sendSmsViaMail')) {
            require_once dirname(__DIR__) . '/includes/sms_gateway.php';
        }

        $prodEmailResult = sendEmailViaSMTP(
            $recipientEmail,
            'Mowology Pipeline Test — ' . date('H:i:s'),
            '<html><body style="font-family:Arial,sans-serif;">'
            . '<h2>Full Pipeline Test</h2>'
            . '<p>This email was sent through the production <code>sendEmailViaSMTP()</code> function.</p>'
            . '<p><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '</p>'
            . '</body></html>'
        );
        $details['production_email'] = $prodEmailResult ? 'pass' : 'fail';

        $prodSmsResult = sendSmsViaMail(
            $phoneNumber,
            'Mowology pipeline test at ' . date('H:i:s'),
            'Mowology'
        );
        $details['production_sms'] = $prodSmsResult['success'] ? 'pass' : 'fail';
        $details['production_sms_carriers'] = $prodSmsResult['delivered_carriers'] ?? [];

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Tally results
        $steps = [
            $details['step_mail']['status'],
            $details['step_phpmailer']['status'],
            $details['step_sms']['status'],
            $details['step_consent']['status'],
            $details['production_email'],
            $details['production_sms'],
        ];
        $passCount = count(array_filter($steps, function ($s) { return $s === 'pass'; }));
        $failCount = count(array_filter($steps, function ($s) { return $s === 'fail' || $s === 'error'; }));
        $totalSteps = count($steps);

        if ($passCount === $totalSteps) {
            $status  = 'pass';
            $message = 'All pipeline steps passed.';
        } elseif ($failCount === $totalSteps) {
            $status  = 'fail';
            $message = 'All pipeline steps failed.';
        } else {
            $status  = 'partial';
            $message = "{$passCount}/{$totalSteps} steps passed.";
        }

        return [
            'status'      => $status,
            'message'     => $message,
            'details'     => $details,
            'input'       => [
                'email'      => $recipientEmail,
                'phone'      => $phoneNumber,
                'contact_id' => $contactId
            ],
            'duration_ms' => $durationMs,
            'error'       => $failCount > 0 ? "{$failCount} steps failed" : null
        ];
    } catch (Throwable $e) {
        return [
            'status'      => 'error',
            'message'     => 'Exception during pipeline test: ' . $e->getMessage(),
            'details'     => $details,
            'input'       => [
                'email'      => $recipientEmail,
                'phone'      => $phoneNumber,
                'contact_id' => $contactId
            ],
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'error'       => $e->getMessage()
        ];
    }
}
