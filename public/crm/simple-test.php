<?php
/**
 * Simplest possible test - just check mail()
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== Simple Mail Test ===\n\n";

// Test 1: Check mail function exists
echo "1. mail() function exists: " . (function_exists('mail') ? "YES" : "NO") . "\n";

// Test 2: Check sendmail_path
echo "2. sendmail_path: " . ini_get('sendmail_path') . "\n";

// Test 3: Try a simple mail
echo "\n3. Attempting to send test email...\n";
$to = 'test@mowology.ca';
$subject = 'Test Email';
$message = 'This is a test.';
$headers = "From: office@mowology.ca\r\n";

$result = mail($to, $subject, $message, $headers);
echo "   Result: " . ($result ? "TRUE (queued)" : "FALSE (failed)") . "\n";

// Test 4: Check for errors
$error = error_get_last();
if ($error) {
    echo "\n4. Last Error: " . print_r($error, true) . "\n";
} else {
    echo "\n4. No PHP errors detected\n";
}

echo "\nDone. Check /var/log/php-errors.log for details.\n";
?>
