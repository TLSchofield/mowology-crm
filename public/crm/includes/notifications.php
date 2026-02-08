<?php
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>



<?php
/**
 * Mowology Notification System
 * Handles email and SMS (via email-to-SMS gateway) notifications
 */

// Notification configuration
if (!defined('NOTIFICATION_EMAIL')) {
    define('NOTIFICATION_EMAIL', 'mowology@icloud.com');
}
if (!defined('NOTIFICATION_SMS_GATEWAY')) {
    define('NOTIFICATION_SMS_GATEWAY', '7788469273@msg.telus.com'); // Telus email-to-SMS gateway
}

/**
 * Send email notification
 *
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body HTML email body
 * @param string $from Sender email (optional)
 * @return bool Success status
 */
function sendEmailNotification($to, $subject, $body, $from = 'Mowology <noreply@mowology.ca>') {
    $headers = "From: {$from}\r\n";
    $headers .= "Reply-To: office@mowology.ca\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    return mail($to, $subject, $body, $headers);
}

/**
 * Send SMS notification via email-to-SMS gateway
 * Telus gateway: number@msg.telus.com
 *
 * @param string $message Short message (160 chars recommended)
 * @return bool Success status
 */
function sendSMSNotification($message) {
    // SMS messages should be plain text and short
    $headers = "From: Mowology <noreply@mowology.ca>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Truncate message if too long (SMS limit is ~160 chars)
    $message = substr($message, 0, 160);

    return mail(NOTIFICATION_SMS_GATEWAY, '', $message, $headers);
}

/**
 * Send quote request notifications (both email and SMS)
 *
 * @param array $data Quote request data
 * @return array Results ['email' => bool, 'sms' => bool]
 */
function sendQuoteRequestNotifications($data) {
    $results = ['email' => false, 'sms' => false];

    // Prepare data
    $name = htmlspecialchars($data['name'] ?? 'Unknown');
    $email = htmlspecialchars($data['email'] ?? 'Not provided');
    $phone = htmlspecialchars($data['phone'] ?? 'Not provided');
    $address = htmlspecialchars($data['address'] ?? 'Not provided');
    $city = htmlspecialchars($data['city'] ?? 'Vancouver');
    $propertyType = htmlspecialchars($data['property_type'] ?? 'residential');
    $services = htmlspecialchars($data['service_types'] ?? 'Not specified');
    $urgency = htmlspecialchars($data['urgency'] ?? 'inquiring');
    $description = htmlspecialchars($data['description'] ?? '');

    // Format urgency for display
    $urgencyLabels = [
        'inquiring' => 'Just Inquiring',
        'this_month' => 'This Month',
        'this_week' => 'This Week',
        'asap' => 'ASAP - Urgent'
    ];
    $urgencyDisplay = $urgencyLabels[$urgency] ?? $urgency;

    // ========================================
    // Send Email Notification
    // ========================================
    $emailSubject = "New Quote Request from {$name}";

    $emailBody = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #1A5F4A 0%, #0D3B2E 100%); padding: 20px; text-align: center;'>
            <h1 style='color: white; margin: 0; font-size: 24px;'>New Quote Request</h1>
        </div>

        <div style='padding: 30px; background: #f9f9f9;'>
            <div style='background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>

                <h2 style='color: #1A5F4A; margin-top: 0; border-bottom: 2px solid #7FD858; padding-bottom: 10px;'>
                    Contact Information
                </h2>

                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; width: 140px;'>Name:</td>
                        <td style='padding: 8px 0;'>{$name}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Email:</td>
                        <td style='padding: 8px 0;'><a href='mailto:{$email}'>{$email}</a></td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Phone:</td>
                        <td style='padding: 8px 0;'><a href='tel:{$phone}'>{$phone}</a></td>
                    </tr>
                </table>

                <h2 style='color: #1A5F4A; margin-top: 25px; border-bottom: 2px solid #7FD858; padding-bottom: 10px;'>
                    Property Details
                </h2>

                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; width: 140px;'>Address:</td>
                        <td style='padding: 8px 0;'>{$address}, {$city}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Property Type:</td>
                        <td style='padding: 8px 0;'>" . ucfirst($propertyType) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Services:</td>
                        <td style='padding: 8px 0;'>" . str_replace(',', ', ', $services) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Timeline:</td>
                        <td style='padding: 8px 0;'>
                            <span style='background: " . ($urgency === 'asap' ? '#dc3545' : '#28a745') . "; color: white; padding: 4px 12px; border-radius: 4px; font-size: 14px;'>
                                {$urgencyDisplay}
                            </span>
                        </td>
                    </tr>
                </table>

                " . ($description ? "
                <h2 style='color: #1A5F4A; margin-top: 25px; border-bottom: 2px solid #7FD858; padding-bottom: 10px;'>
                    Project Description
                </h2>
                <p style='background: #f5f5f5; padding: 15px; border-radius: 8px; margin: 0;'>{$description}</p>
                " : "") . "

            </div>

            <div style='text-align: center; margin-top: 25px;'>
                <a href='mailto:{$email}' style='display: inline-block; background: #2D8659; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin-right: 10px;'>
                    Reply to Lead
                </a>
                <a href='tel:{$phone}' style='display: inline-block; background: #1A5F4A; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                    Call Now
                </a>
            </div>
        </div>

        <div style='background: #333; color: #999; padding: 20px; text-align: center; font-size: 12px;'>
            <p style='margin: 0;'>This notification was sent from the Mowology website quote form.</p>
            <p style='margin: 5px 0 0;'>" . date('F j, Y \a\t g:i A') . "</p>
        </div>
    </body>
    </html>
    ";

    $results['email'] = sendEmailNotification(NOTIFICATION_EMAIL, $emailSubject, $emailBody);

    // ========================================
    // Send SMS Notification
    // ========================================
    // Keep SMS short and actionable
    $smsMessage = "NEW LEAD: {$name}";
    if (!empty($phone)) {
        $smsMessage .= " - {$phone}";
    }
    $smsMessage .= " | " . ucfirst($propertyType);
    if ($urgency === 'asap') {
        $smsMessage .= " | URGENT";
    }

    $results['sms'] = sendSMSNotification($smsMessage);

    // Log notification results
    error_log("Quote notification sent - Email: " . ($results['email'] ? 'OK' : 'FAILED') . ", SMS: " . ($results['sms'] ? 'OK' : 'FAILED'));

    return $results;
}
