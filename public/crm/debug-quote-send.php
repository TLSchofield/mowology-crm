<?php
/**
 * Debug Quote Send - Diagnostic Tool
 * Helps diagnose why quote sending is failing
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/smtp_mailer.php';
require_once dirname(__DIR__) . '/includes/sms_gateway.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    die('Access denied');
}

$quoteId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$quoteId) {
    echo "<p>Error: No quote ID provided. Use ?id=123</p>";
    exit;
}

$db = getDB();

// Get quote with contact info
$stmt = $db->prepare("
    SELECT
        q.*,
        qr.contact_id as qr_contact_id,
        qrc.first_name as qr_first_name,
        qrc.last_name as qr_last_name,
        qrc.email as qr_email,
        qrc.phone as qr_phone,
        c.primary_contact_id,
        ct.email as company_contact_email,
        ct.phone as company_contact_phone
    FROM quotes q
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    WHERE q.id = ?
    LIMIT 1
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    echo "<p>Error: Quote not found (ID: $quoteId)</p>";
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Quote Send</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin-bottom: 30px; padding: 15px; background: #f5f5f5; border-radius: 4px; }
        .label { font-weight: bold; color: #333; }
        .value { color: #666; word-break: break-all; }
        .success { color: green; }
        .error { color: red; }
        code { background: #eee; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>

<h1>Debug Quote Send - Quote #<?php echo htmlspecialchars($quote['quote_number']); ?></h1>

<div class="section">
    <h2>Quote Information</h2>
    <div class="label">Quote ID:</div>
    <div class="value"><?php echo $quoteId; ?></div>

    <div class="label">Quote Number:</div>
    <div class="value"><?php echo htmlspecialchars($quote['quote_number']); ?></div>

    <div class="label">Status:</div>
    <div class="value"><?php echo htmlspecialchars($quote['status']); ?></div>
</div>

<div class="section">
    <h2>Contact Information</h2>

    <div class="label">Quote Request Contact ID:</div>
    <div class="value"><?php echo $quote['qr_contact_id'] ?? '(none)'; ?></div>

    <div class="label">Quote Request Contact Name:</div>
    <div class="value"><?php echo htmlspecialchars(($quote['qr_first_name'] ?? '') . ' ' . ($quote['qr_last_name'] ?? '')) ?: '(none)'; ?></div>

    <div class="label">Quote Request Contact Email:</div>
    <div class="value"><?php echo htmlspecialchars($quote['qr_email'] ?? '(none)'); ?></div>

    <div class="label">Quote Request Contact Phone:</div>
    <div class="value"><?php echo htmlspecialchars($quote['qr_phone'] ?? '(none)'); ?></div>

    <div class="label">Company Contact Email:</div>
    <div class="value"><?php echo htmlspecialchars($quote['company_contact_email'] ?? '(none)'); ?></div>

    <div class="label">Company Contact Phone:</div>
    <div class="value"><?php echo htmlspecialchars($quote['company_contact_phone'] ?? '(none)'); ?></div>
</div>

<div class="section">
    <h2>SMS Consent Check</h2>
    <?php
        if ($quote['qr_contact_id']) {
            // Check consent_log
            $consentStmt = $db->prepare("
                SELECT consent_given, created_at
                FROM consent_log
                WHERE contact_id = ?
                AND consent_type = 'sms'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $consentStmt->execute([$quote['qr_contact_id']]);
            $consentRecord = $consentStmt->fetch(PDO::FETCH_ASSOC);

            if ($consentRecord) {
                echo "<div class='label'>SMS Consent (from consent_log):</div>";
                echo "<div class='value " . ($consentRecord['consent_given'] ? 'success' : 'error') . "'>";
                echo $consentRecord['consent_given'] ? '✓ YES' : '✗ NO';
                echo " (recorded: " . htmlspecialchars($consentRecord['created_at']) . ")";
                echo "</div>";
            } else {
                echo "<div class='label'>SMS Consent (from consent_log):</div>";
                echo "<div class='value error'>✗ No consent_log record found</div>";
            }

            // Check contacts table fallback
            $contactStmt = $db->prepare("SELECT receive_sms, consent_sms FROM contacts WHERE id = ?");
            $contactStmt->execute([$quote['qr_contact_id']]);
            $contactPrefs = $contactStmt->fetch(PDO::FETCH_ASSOC);

            if ($contactPrefs) {
                $hasSmsConsent = !empty($contactPrefs['receive_sms']) || !empty($contactPrefs['consent_sms']);
                echo "<div class='label'>SMS Consent (fallback to contacts table):</div>";
                echo "<div class='value" . ($hasSmsConsent ? ' success' : ' error') . "'>";
                echo $hasSmsConsent ? '✓ YES' : '✗ NO';
                echo " (receive_sms=" . ($contactPrefs['receive_sms'] ? '1' : '0') . ", consent_sms=" . ($contactPrefs['consent_sms'] ? '1' : '0') . ")";
                echo "</div>";
            } else {
                echo "<div class='label'>SMS Consent (fallback to contacts table):</div>";
                echo "<div class='value error'>✗ Contact not found</div>";
            }
        } else {
            echo "<div class='value error'>No quote_request contact ID - cannot check consent</div>";
        }
    ?>
</div>

<div class="section">
    <h2>Test Email Send</h2>
    <?php
        $testEmail = $quote['qr_email'] ?? $quote['company_contact_email'];
        if ($testEmail) {
            echo "<p><strong>Email to test:</strong> " . htmlspecialchars($testEmail) . "</p>";
            echo "<form method='POST'>";
            echo "<button type='submit' name='test_email' value='1'>Send Test Email</button>";
            echo "</form>";

            if (isset($_POST['test_email'])) {
                $testSubject = "TEST: Quote " . $quote['quote_number'] . " from Mowology";
                $testBody = "<p>This is a test email sent at " . date('Y-m-d H:i:s') . "</p>";
                $result = sendCrmEmail($testEmail, $testSubject, $testBody);
                echo "<div class='value " . ($result ? 'success' : 'error') . "'>";
                echo $result ? "✓ Test email sent successfully" : "✗ Test email failed";
                echo "</div>";
            }
        } else {
            echo "<div class='value error'>No email address found</div>";
        }
    ?>
</div>

<div class="section">
    <h2>Test SMS Send</h2>
    <?php
        $testPhone = $quote['qr_phone'] ?? $quote['company_contact_phone'];
        if ($testPhone) {
            echo "<p><strong>Phone to test:</strong> " . htmlspecialchars($testPhone) . "</p>";
            echo "<form method='POST'>";
            echo "<button type='submit' name='test_sms' value='1'>Send Test SMS</button>";
            echo "</form>";

            if (isset($_POST['test_sms'])) {
                $testMessage = "Test SMS from Mowology at " . date('H:i:s');
                $result = sendSmsViaMail($testPhone, $testMessage, 'Mowology Test');
                echo "<div class='value" . ($result['success'] ? ' success' : ' error') . "'>";
                if ($result['success']) {
                    echo "✓ SMS sent successfully via: " . implode(', ', $result['delivered_carriers']);
                } else {
                    echo "✗ SMS failed. Errors: " . implode('; ', $result['errors']);
                }
                echo "</div>";
            }
        } else {
            echo "<div class='value error'>No phone number found</div>";
        }
    ?>
</div>

</body>
</html>
