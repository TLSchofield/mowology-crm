<?php
/**
 * SMS Gateway Diagnostic Tool
 * Tests mail() delivery to SMS gateway domains
 * Admin only
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once __DIR__ . '/includes/sms_gateway.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    die('Access denied');
}

$testResults = [];
$phoneNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $phoneNumber = $_POST['phone'] ?? '';
    
    if ($phoneNumber) {
        // Normalize phone
        $cleanPhone = preg_replace('/\D/', '', $phoneNumber);
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
            $cleanPhone = substr($cleanPhone, 1);
        }
        
        if (strlen($cleanPhone) !== 10) {
            $testResults['error'] = 'Invalid phone format';
        } else {
            // Test each carrier individually
            $testResults = [
                'phone' => $cleanPhone,
                'carriers' => []
            ];
            
            foreach (CANADIAN_SMS_GATEWAYS as $carrier => $domain) {
                $recipient = $cleanPhone . '@' . $domain;
                $message = "SMS test to {$carrier} at " . date('Y-m-d H:i:s');
                $subject = "Test from Mowology";
                $headers = "From: Mowology <office@mowology.ca>\r\n";
                
                // Attempt send
                $result = mail($recipient, $subject, $message, $headers);
                
                $testResults['carriers'][$carrier] = [
                    'domain' => $domain,
                    'recipient' => $recipient,
                    'mail_returned' => $result,
                    'status' => $result ? '✓ Queued' : '✗ Rejected'
                ];
                
                error_log("SMS Diagnostic: {$carrier} ({$recipient}) - mail() returned: " . ($result ? 'true' : 'false'));
            }
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html>
<head>
    <title>SMS Diagnostic Test</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 20px; border-radius: 8px; max-width: 900px; margin: 0 auto; }
        h1 { color: #333; }
        form { margin: 20px 0; }
        input[type="text"] { padding: 10px; width: 300px; font-size: 14px; border: 1px solid #ddd; border-radius: 4px; }
        button { padding: 10px 20px; background: #2D8659; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1A5F4A; }
        .results { margin-top: 30px; }
        .carrier-row { display: grid; grid-template-columns: 150px 250px 150px 100px; gap: 15px; padding: 12px; border-bottom: 1px solid #ddd; }
        .carrier-row.header { font-weight: bold; background: #f8f9fa; }
        .queued { color: green; }
        .rejected { color: red; }
        .warning { background: #fff3cd; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .info { background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 20px 0; }
        code { background: #f4f4f4; padding: 2px 4px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="container">
    <h1>SMS Gateway Diagnostic Test</h1>
    
    <div class="info">
        <strong>Purpose:</strong> Tests whether the server's mail() function can reach Canadian carrier SMS gateway domains.
        A "✓ Queued" result means mail() accepted the message. But if you don't receive SMS, the server's mail
        configuration may not be routing to these domains correctly.
    </div>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <label>Your Phone Number (10 digits, any format):</label><br><br>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($phoneNumber); ?>" placeholder="(202) 555-1234 or 2025551234" required>
        <button type="submit">Run Diagnostic Test</button>
    </form>
    
    <?php if (!empty($testResults)): ?>
        <div class="results">
            <?php if (isset($testResults['error'])): ?>
                <div style="color: red; padding: 10px; background: #ffe6e6; border-radius: 4px;">
                    Error: <?php echo htmlspecialchars($testResults['error']); ?>
                </div>
            <?php else: ?>
                <h2>Results for <?php echo $testResults['phone']; ?></h2>
                
                <div class="carrier-row header">
                    <div>Carrier</div>
                    <div>SMS Gateway Address</div>
                    <div>mail() Status</div>
                    <div>Queued?</div>
                </div>
                
                <?php foreach ($testResults['carriers'] as $carrier => $result): ?>
                    <div class="carrier-row">
                        <div><?php echo $carrier; ?></div>
                        <div><code><?php echo htmlspecialchars($result['recipient']); ?></code></div>
                        <div class="<?php echo $result['mail_returned'] ? 'queued' : 'rejected'; ?>">
                            <?php echo $result['status']; ?>
                        </div>
                        <div><?php echo $result['mail_returned'] ? '✓' : '✗'; ?></div>
                    </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 30px;">
                    <?php 
                        $allQueued = array_reduce($testResults['carriers'], function($carry, $item) {
                            return $carry && $item['mail_returned'];
                        }, true);
                        
                        $someQueued = array_reduce($testResults['carriers'], function($carry, $item) {
                            return $carry || $item['mail_returned'];
                        }, false);
                    ?>
                    
                    <?php if ($allQueued): ?>
                        <div class="warning">
                            <strong>✓ All carriers accepted the message</strong><br>
                            The server can send to SMS gateway domains. If you still don't receive SMS:
                            <ul>
                                <li>Check your phone's spam/junk messages</li>
                                <li>Verify the phone number is correct</li>
                                <li>Wait up to 5 minutes (carriers can be slow)</li>
                                <li>Contact your carrier support</li>
                            </ul>
                        </div>
                    <?php elseif ($someQueued): ?>
                        <div class="warning">
                            <strong>⚠ Some carriers accepted, some rejected</strong><br>
                            The SMS might arrive via the accepted carriers. If not, contact hosting support.
                        </div>
                    <?php else: ?>
                        <div class="warning">
                            <strong>✗ All carriers rejected the message</strong><br>
                            <strong>This is the problem!</strong> The server's mail configuration is blocking
                            emails to SMS gateway domains. Contact your hosting provider and ask them to:
                            <ul>
                                <li>Whitelist Canadian carrier SMS gateway domains (txt.bellmobility.ca, msg.telus.com, etc.)</li>
                                <li>Check firewall rules blocking outbound email</li>
                                <li>Check mail server logs for rejection reasons</li>
                                <li>Enable email delivery to .ca and .com domains</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px; font-size: 12px;">
                    <strong>Technical Note:</strong> Results are also logged to PHP error log.
                    Check <code>/var/log/error_log</code> for "SMS Diagnostic:" entries.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
