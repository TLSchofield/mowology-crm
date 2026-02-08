<?php
/**
 * Test Quote Submission Flow
 * Helps diagnose issues with quote requests not being saved
 *
 * URL: https://www.mowology.ca/jobFlow/test-submission.php
 */

header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "=== Quote Submission Diagnostic ===\n\n";

// 1. Check database connection
echo "1. DATABASE CONNECTION:\n";
try {
    $db = getDB();
    echo "   ✓ Connected to database\n";
    echo "   ✓ PDO Instance: " . get_class($db) . "\n";
} catch (Exception $e) {
    echo "   ✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Check if quote_requests table exists
echo "\n2. CHECKING TABLES:\n";
$tables = ['quote_requests', 'contacts', 'properties', 'consent_log', 'activity_log'];
foreach ($tables as $table) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = database() AND table_name = ?");
    $stmt->execute([$table]);
    $exists = $stmt->rowCount() > 0;
    echo "   " . ($exists ? "✓" : "✗") . " `" . $table . "` table " . ($exists ? "exists" : "MISSING") . "\n";
}

// 3. Check notification settings
echo "\n3. NOTIFICATION CONFIGURATION:\n";
echo "   Email recipient: " . (defined('NOTIFICATION_EMAIL') ? NOTIFICATION_EMAIL : 'NOT DEFINED') . "\n";
echo "   SMS gateway: " . (defined('NOTIFICATION_SMS_GATEWAY') ? NOTIFICATION_SMS_GATEWAY : 'NOT DEFINED') . "\n";

// 4. Check if mail() function works
echo "\n4. MAIL FUNCTION TEST:\n";
if (function_exists('mail')) {
    echo "   ✓ mail() function is available\n";

    // Test sending a test email
    $testTo = NOTIFICATION_EMAIL;
    $testSubject = "TEST: Quote Submission System Check";
    $testBody = "This is a test email from the quote submission diagnostic.\n\nIf you received this, the email system is working.";
    $testHeaders = "From: noreply@mowology.ca\r\nContent-Type: text/plain; charset=UTF-8";

    $result = @mail($testTo, $testSubject, $testBody, $testHeaders);
    echo "   Test email sent to: " . $testTo . " - Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "   ✗ mail() function is NOT available\n";
}

// 5. Check file permissions
echo "\n5. FILE PERMISSIONS:\n";
$jobflowDir = __DIR__;
echo "   jobFlow directory: " . (is_writable($jobflowDir) ? "✓ writable" : "✗ NOT writable") . "\n";

// 6. Check recent quote_requests
echo "\n6. RECENT QUOTE REQUESTS IN DATABASE:\n";
$stmt = $db->prepare("
    SELECT
        qr.id,
        c.email,
        c.phone,
        p.address,
        qr.created_at,
        qr.status,
        qr.quote_id
    FROM quote_requests qr
    LEFT JOIN contacts c ON qr.contact_id = c.id
    LEFT JOIN properties p ON qr.property_id = p.id
    ORDER BY qr.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($recent)) {
    echo "   ✗ No quote requests found in database\n";
} else {
    echo "   ✓ Found " . count($recent) . " recent quote requests:\n";
    foreach ($recent as $req) {
        $status = isset($req['status']) ? $req['status'] : 'unknown';
        $quote_id = isset($req['quote_id']) && $req['quote_id'] ? "Quote #{$req['quote_id']}" : "No quote yet";
        echo "      - ID: {$req['id']} | Email: " . ($req['email'] ?? 'N/A') . " | Status: {$status} | {$quote_id} | Created: {$req['created_at']}\n";
    }
}

// 7. Check session data
echo "\n7. SESSION DATA:\n";
echo "   Session ID: " . session_id() . "\n";
echo "   quote_data: " . (isset($_SESSION['quote_data']) ? "SET" : "NOT SET") . "\n";
echo "   temp_quote_data: " . (isset($_SESSION['temp_quote_data']) ? "SET" : "NOT SET") . "\n";
echo "   csrf_token: " . (isset($_SESSION['csrf_token']) ? "SET" : "NOT SET") . "\n";

// 8. Error logs
echo "\n8. ERROR LOGS (last 20 lines mentioning 'quote'):\n";
$logPath = ini_get('error_log');
if ($logPath && file_exists($logPath)) {
    $lines = array_filter(
        array_reverse(explode("\n", file_get_contents($logPath))),
        function($line) { return stripos($line, 'quote') !== false; }
    );
    $lines = array_slice($lines, 0, 20);

    if (empty($lines)) {
        echo "   No quote-related errors found\n";
    } else {
        foreach ($lines as $line) {
            echo "   " . substr($line, 0, 100) . "...\n";
        }
    }
} else {
    echo "   Error log not accessible at: " . $logPath . "\n";
}

echo "\n=== End Diagnostic ===\n";
