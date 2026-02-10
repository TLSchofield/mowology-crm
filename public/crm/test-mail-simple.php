<?php
/**
 * Ultra-simple mail test - no dependencies
 * Just outputs what happens
 */

// Enable error display
ini_set('display_errors', 1);
error_reporting(E_ALL);

?><!DOCTYPE html>
<html>
<head>
    <title>Mail Test</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .test { border: 1px solid #ccc; padding: 10px; margin: 10px 0; }
        .pass { background: #d4edda; color: #155724; }
        .fail { background: #f8d7da; color: #721c24; }
        code { background: #f5f5f5; padding: 2px 5px; }
    </style>
</head>
<body>
    <h1>Mowology Mail Test</h1>

    <div class="test pass">
        <strong>Test 1: mail() function exists?</strong><br>
        <?php echo function_exists('mail') ? '✓ YES' : '✗ NO'; ?>
    </div>

    <div class="test">
        <strong>Test 2: Server Configuration</strong><br>
        <code>sendmail_path:</code> <?php echo ini_get('sendmail_path') ?: '(not set)'; ?><br>
        <code>SMTP:</code> <?php echo ini_get('SMTP') ?: '(not set)'; ?><br>
        <code>SMTP_PORT:</code> <?php echo ini_get('SMTP_PORT') ?: '(not set)'; ?>
    </div>

    <div class="test">
        <strong>Test 3: Attempt to send email</strong><br>
        <?php
            $to = 'test@example.com';
            $subject = 'Test from Mowology';
            $message = 'This is a test message.';
            $headers = "From: office@mowology.ca\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            $result = mail($to, $subject, $message, $headers);

            echo 'Result: ' . ($result ? '<span style="color:green;">✓ TRUE (mail queued)</span>' : '<span style="color:red;">✗ FALSE (failed)</span>');
        ?>
    </div>

    <div class="test">
        <strong>Test 4: Check for PHP errors</strong><br>
        <?php
            $error = error_get_last();
            if ($error) {
                echo '<pre>';
                print_r($error);
                echo '</pre>';
            } else {
                echo '✓ No PHP errors';
            }
        ?>
    </div>

    <hr>
    <p><strong>Summary:</strong></p>
    <ul>
        <li>If Test 3 shows ✓ TRUE: Your mail() function works - email/SMS issue is elsewhere</li>
        <li>If Test 3 shows ✗ FALSE: Your hosting provider has disabled mail() or has issues</li>
        <li>Check your hosting provider's error logs for more details</li>
    </ul>
</body>
</html>
