<?php
/**
 * SMTP Debug — Tests PHPMailer send path with full debug output (v2)
 * DELETE THIS FILE AFTER DEBUGGING
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { die('Admin only'); }

header('Content-Type: text/plain; charset=UTF-8');

echo "=== SMTP DEBUG TEST ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Check constants
echo "--- SMTP Constants ---\n";
echo "SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : 'NOT DEFINED') . "\n";
echo "SMTP_PORT: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT DEFINED') . "\n";
echo "SMTP_USER: " . (defined('SMTP_USER') ? SMTP_USER : 'NOT DEFINED') . "\n";
echo "SMTP_PASS: " . (defined('SMTP_PASS') ? '***SET***' : 'NOT DEFINED') . "\n\n";

// 2. Check PHPMailer vendor
echo "--- PHPMailer Vendor ---\n";
$possibleBases = [
    dirname(__DIR__) . '/vendor/phpmailer/src',
    dirname(dirname(dirname(__DIR__))) . '/vendor/phpmailer/src',
    dirname(dirname(__DIR__)) . '/vendor/phpmailer/src',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
    dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
];
$found = false;
foreach ($possibleBases as $base) {
    $exists = file_exists($base . '/PHPMailer.php');
    echo "  {$base}/PHPMailer.php => " . ($exists ? 'FOUND' : 'not found') . "\n";
    if ($exists && !$found) $found = true;
}
echo "PHPMailer available: " . ($found ? 'YES' : 'NO') . "\n\n";

// 3. Load smtp_mailer and test _loadPhpMailer
echo "--- Loading smtp_mailer.php ---\n";
require_once __DIR__ . '/includes/smtp_mailer.php';
$loaded = _loadPhpMailer();
echo "_loadPhpMailer(): " . ($loaded ? 'TRUE' : 'FALSE') . "\n\n";

// 4. Test _createMailer
echo "--- Creating Mailer ---\n";
$mailer = _createMailer();
echo "_createMailer(): " . ($mailer ? 'OBJECT CREATED' : 'NULL (failed)') . "\n";
if ($mailer) {
    echo "  Host: {$mailer->Host}\n";
    echo "  Port: {$mailer->Port}\n";
    echo "  SMTPAuth: " . ($mailer->SMTPAuth ? 'true' : 'false') . "\n";
    echo "  Username: {$mailer->Username}\n";
    echo "  SMTPSecure: {$mailer->SMTPSecure}\n\n";
}

// 5. Try actual SMTP send with debug
echo "--- Attempting SMTP Send to mowology@icloud.com ---\n";
if (!$mailer) {
    echo "SKIPPED: No mailer instance\n";
    echo "\nFalling back to test via sendEmailViaSMTP()...\n";
    $result = sendEmailViaSMTP('mowology@icloud.com', 'SMTP Debug Test', '<p>Test from debug script at ' . date('H:i:s') . '</p>');
    echo "sendEmailViaSMTP result: " . ($result ? 'TRUE' : 'FALSE') . "\n";
    echo "(This used the mail() fallback since PHPMailer failed)\n";
} else {
    // Enable SMTP debug output
    $mailer->SMTPDebug = 3; // Full debug: connection + data
    $mailer->Debugoutput = function($str, $level) {
        echo "  [SMTP:{$level}] " . trim($str) . "\n";
    };

    try {
        $mailer->clearAddresses();
        $mailer->setFrom('no-reply@mowology.ca', 'Mowology');
        $mailer->addAddress('mowology@icloud.com');
        $mailer->Subject = 'SMTP Debug Test - ' . date('H:i:s');
        $mailer->isHTML(true);
        $mailer->Body = '<h2>SMTP Debug Test</h2><p>Sent via PHPMailer SMTP at ' . date('Y-m-d H:i:s') . '</p>';

        echo "Calling \$mailer->send()...\n\n";
        $result = $mailer->send();
        echo "\n\nResult: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        if (!$result) {
            echo "ErrorInfo: " . $mailer->ErrorInfo . "\n";
        }
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        echo "\n\nPHPMailer Exception: " . $e->getMessage() . "\n";
        echo "ErrorInfo: " . $mailer->ErrorInfo . "\n";
    } catch (Throwable $e) {
        echo "\n\nError: " . $e->getMessage() . "\n";
    }
}

echo "\n=== END DEBUG ===\n";
