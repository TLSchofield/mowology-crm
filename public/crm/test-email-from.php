<?php
/**
 * Email From Address Test
 * Tests which From address actually works on your hosting
 */

$testEmail = isset($_POST['test_email']) ? trim($_POST['test_email']) : '';
$testRun = !empty($testEmail);

?><!DOCTYPE html>
<html>
<head>
    <title>Email From Address Test</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f9f9f9; }
        .container { max-width: 800px; margin: 0 auto; }
        .test { border: 1px solid #ddd; padding: 15px; margin: 15px 0; background: white; border-radius: 4px; }
        .info { color: #0066cc; padding: 10px; background: #e6f2ff; border-left: 4px solid #0066cc; margin: 10px 0; }
        .warning { color: #856404; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; margin: 10px 0; }
        code { background: #f5f5f5; padding: 3px 6px; border-radius: 3px; }
        h2 { color: #333; }
        .result-pass { color: green; font-weight: bold; }
        .result-fail { color: red; font-weight: bold; }
        input { padding: 8px; font-size: 14px; width: 100%; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Email From Address Test</h1>
        <div class="info">
            <strong>Purpose:</strong> Find which email From address is properly configured on your hosting.
        </div>

        <?php if (!$testRun): ?>
            <div class="test">
                <h2>Enter Your Email</h2>
                <form method="POST">
                    <p>Enter your personal email address where you want to receive test emails:</p>
                    <input type="email" name="test_email" placeholder="your.email@gmail.com" required>
                    <p style="margin-top: 10px;">
                        <button type="submit">Send Test Emails</button>
                    </p>
                </form>
            </div>
        <?php else: ?>

            <p class="warning"><strong>⚠️ Tests Complete:</strong> Check your inbox at <strong><?php echo htmlspecialchars($testEmail); ?></strong> for emails from these addresses:</p>

            <?php
                // Test different From addresses
                $testAddresses = [
                    'noreply@mowology.ca' => 'System noreply address',
                    'office@mowology.ca' => 'Office address',
                    'no-reply@mowology.ca' => 'Alternative noreply',
                ];

                foreach ($testAddresses as $fromAddress => $description) {
                    echo '<div class="test">';
                    echo '<h3>' . htmlspecialchars($fromAddress) . '</h3>';
                    echo '<p><small>' . htmlspecialchars($description) . '</small></p>';

                    $subject = "Mowology CRM Email Test - From: {$fromAddress}";
                    $message = "This is a test email from Mowology CRM.\n\nFrom Address: {$fromAddress}\nTime: " . date('Y-m-d H:i:s') . "\n\nIf you received this email, the sender address is working!";

                    $headers = "From: Mowology CRM <{$fromAddress}>\r\n";
                    $headers .= "Reply-To: {$fromAddress}\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                    $result = @mail($testEmail, $subject, $message, $headers);

                    error_log("Email test from {$fromAddress} to {$testEmail}: " . ($result ? 'queued' : 'rejected'));

                    echo '<p>Server response: <span class="' . ($result ? 'result-pass' : 'result-fail') . '">' . ($result ? '✓ QUEUED' : '✗ REJECTED') . '</span></p>';

                    if ($result) {
                        echo '<p class="info">The server accepted this address. It should arrive in a moment.</p>';
                    } else {
                        echo '<p class="warning">The server rejected this address.</p>';
                    }

                    echo '</div>';
                }
            ?>

            <div class="test">
                <h2>✓ What to Do Next</h2>
                <ol>
                    <li>Wait 2-5 minutes for emails to arrive</li>
                    <li>Check your inbox: <strong><?php echo htmlspecialchars($testEmail); ?></strong></li>
                    <li><strong>Note which email addresses actually arrived</strong></li>
                    <li>Report back with which ones worked</li>
                </ol>
            </div>

            <div class="test">
                <p><a href="<?php echo $_SERVER['REQUEST_URI']; ?>">← Run Test Again</a></p>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
