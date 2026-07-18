<?php
/**
 * SMS Gateway - Email-to-SMS Integration
 *
 * Sends SMS via carrier email-to-SMS gateways (free, no API required)
 * Supports major Canadian carriers: Bell, Rogers, Telus, Koodo, Freedom Mobile, etc.
 *
 * How it works:
 * - Convert phone to carrier SMS email format (e.g., 2025551234@txt.bell.ca)
 * - Send via standard email to multiple carrier domains (fallback approach)
 * - Message is delivered as SMS to customer's phone
 *
 * Usage:
 *   sendSmsViaMail($phoneNumber, $message, $senderName = 'Mowology');
 *
 * Returns: array['success' => bool, 'attempts' => int, 'delivered_carriers' => []]
 *
 * Migrated from: public/crm/includes/sms_gateway.php
 */

declare(strict_types=1);

/**
 * Canadian Carrier Email-to-SMS Gateways
 * Format: [carrier_name => 'sms_domain', ...]
 *
 * These are the most common Canadian carriers and their SMS email domains
 */
const CANADIAN_SMS_GATEWAYS = [
    'Bell' => 'txt.bellmobility.ca',           // Bell Mobility
    'Rogers' => 'sms.rogers.com',               // Rogers Wireless
    'Telus' => 'msg.telus.com',                 // Telus Mobility
    'Koodo' => 'msg.koodomobile.com',           // Koodo (TELUS network)
    'Virgin' => 'sms.virginmobile.ca',          // Virgin Mobile Canada
    'Fido' => 'sms.fido.ca',                    // Fido (Rogers network)
    'Freedom' => 'sms.freedommobile.ca',        // Freedom Mobile (formerly Wind)
    'PC Mobile' => 'sms.pcmobilecanada.com',    // PC Mobile
    'Eastlink' => 'sms.eastlinktelecom.com',    // Eastlink (Atlantic Canada)
    'SaskTel' => 'sms.sasktel.com',             // SaskTel (Saskatchewan)
];

/**
 * Send SMS via email-to-SMS gateway to multiple carriers
 * Tries all major Canadian carriers - one will succeed
 *
 * @param string      $phoneNumber    Phone in format: 2025551234 or (202)555-1234 or +1-202-555-1234
 * @param string      $message        SMS message (max 160 chars recommended)
 * @param string      $senderName     Display name for email (default: Mowology)
 * @return array      ['success' => bool, 'attempts' => int, 'delivered_carriers' => [], 'errors' => []]
 */
if (!function_exists('_stripHeaderInjection')) {
    /**
     * Strip CR/LF (and other control characters) from a value before it's
     * interpolated into a raw mail() header or subject line.
     */
    function _stripHeaderInjection(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1F]+/', ' ', $value));
    }
}

function sendSmsViaMail(
    string $phoneNumber,
    string $message,
    string $senderName = 'Mowology'
): array {
    $senderName = _stripHeaderInjection($senderName);

    // Normalize phone number: extract digits only
    $cleanPhone = preg_replace('/\D/', '', $phoneNumber);

    // Ensure it's 10 digits (North American format)
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
        $cleanPhone = substr($cleanPhone, 1); // Remove leading 1
    }

    if (strlen($cleanPhone) !== 10) {
        error_log("SMS Gateway: Invalid phone number format: {$phoneNumber}");
        return [
            'success' => false,
            'attempts' => 0,
            'delivered_carriers' => [],
            'errors' => ['Invalid phone number format. Expected 10 digits (North American).']
        ];
    }

    // Validate message
    if (empty($message)) {
        return [
            'success' => false,
            'attempts' => 0,
            'delivered_carriers' => [],
            'errors' => ['Message cannot be empty']
        ];
    }

    // Truncate message to 160 characters (SMS limit)
    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
        error_log("SMS Gateway: Message truncated to 160 chars");
    }

    $attempts = 0;
    $deliveredCarriers = [];
    $errors = [];

    // Try to send via each carrier gateway
    foreach (CANADIAN_SMS_GATEWAYS as $carrierName => $smsDomain) {
        $attempts++;

        // Build SMS email address
        $smsRecipient = $cleanPhone . '@' . $smsDomain;

        try {
            // Send via native mail() to carrier SMS gateway
            // Use the verified working From address: no-reply@mowology.ca
            $fromEmail = 'no-reply@mowology.ca';
            $headers = "From: {$senderName} <{$fromEmail}>\r\n";
            $headers .= "X-Mailer: Mowology SMS Gateway\r\n";

            // Send with a simple subject (carriers ignore this)
            $subject = "Message from {$senderName}";

            error_log("SMS Gateway: Attempting to send to {$smsRecipient} via {$carrierName}");

            // Use the message as body
            $result = mail($smsRecipient, $subject, $message, $headers);

            if ($result) {
                $deliveredCarriers[] = $carrierName;
                error_log("SMS Gateway: Message delivered via {$carrierName} to {$smsRecipient}");

                // Success! Stop after first successful carrier to avoid duplicate messages
                break;
            } else {
                $errors[] = "{$carrierName}: mail() returned false";
                error_log("SMS Gateway: Failed to send via {$carrierName} ({$smsRecipient})");
            }

        } catch (Throwable $e) {
            $errors[] = "{$carrierName}: " . $e->getMessage();
            error_log("SMS Gateway error for {$carrierName}: " . $e->getMessage());
        }
    }

    // Success if at least one carrier accepted the message
    $success = !empty($deliveredCarriers);

    if ($success) {
        error_log("SMS Gateway: Successfully sent to {$phoneNumber} via " . implode(', ', $deliveredCarriers));
    } else {
        error_log("SMS Gateway: Failed to send to {$phoneNumber} - all carriers rejected");
    }

    return [
        'success' => $success,
        'attempts' => $attempts,
        'delivered_carriers' => $deliveredCarriers,
        'errors' => $errors
    ];
}

/**
 * Convenience wrapper: Send SMS quote notification
 *
 * @param string $phoneNumber    Customer phone number
 * @param string $quoteNumber    Quote number for reference
 * @param string $quoteUrl       Public quote acceptance URL
 * @param string $companyName    Company name (for SMS)
 * @return array                 Result from sendSmsViaMail()
 */
function sendQuoteNotificationSms(
    string $phoneNumber,
    string $quoteNumber,
    string $quoteUrl,
    string $companyName = 'Mowology'
): array {
    // Shorten URL if too long (add to message)
    $shortUrl = parse_url($quoteUrl, PHP_URL_HOST) . '/quote';

    $message = "Hi! Your quote {$quoteNumber} from {$companyName} is ready. "
             . "View and accept it here: {$shortUrl}";

    // Ensure message is under 160 chars
    if (strlen($message) > 160) {
        $message = "Your quote {$quoteNumber} from {$companyName} is ready. "
                 . "Visit your account to view: {$shortUrl}";
    }

    return sendSmsViaMail($phoneNumber, $message, $companyName);
}

/**
 * Check if customer has SMS consent before sending
 *
 * @param int $contactId    Contact ID from contacts table
 * @return bool             True if customer consented to SMS
 */
function hasSmConsent(int $contactId): bool {
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

        // Customer must have opted in (receive_sms = 1 or consent_sms = 1)
        return !empty($contact['receive_sms']) || !empty($contact['consent_sms']);

    } catch (Throwable $e) {
        error_log("SMS Consent check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Test SMS gateway to a specific carrier
 * Useful for debugging
 *
 * @param string $phoneNumber    Phone to test
 * @param string $carrierName    Carrier name (or 'all' for all carriers)
 * @return array                 Result
 */
function testSmsGateway(string $phoneNumber, string $carrierName = 'all'): array {
    $message = "Test SMS from Mowology at " . date('H:i:s');

    if ($carrierName !== 'all' && isset(CANADIAN_SMS_GATEWAYS[$carrierName])) {
        // Test specific carrier
        $cleanPhone = preg_replace('/\D/', '', $phoneNumber);
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
            $cleanPhone = substr($cleanPhone, 1);
        }

        $domain = CANADIAN_SMS_GATEWAYS[$carrierName];
        $smsRecipient = $cleanPhone . '@' . $domain;

        $result = mail($smsRecipient, "Test", $message, "From: Mowology <office@mowology.ca>\r\n");

        return [
            'success' => $result,
            'carrier' => $carrierName,
            'recipient' => $smsRecipient,
            'message' => $result ? "Test sent successfully" : "Test failed"
        ];
    } else {
        // Test all carriers
        return sendSmsViaMail($phoneNumber, $message);
    }
}
