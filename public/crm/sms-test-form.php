<?php
/**
 * SMS Testing Form
 * Simple form to test SMS sending with basic options
 * Admin only
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/crm/includes/sms_gateway.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    die('Access denied');
}

$result = null;
$testPhone = '';
$testMessage = '';
$sendEmail = false;
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $testPhone = $_POST['phone'] ?? '';
    $testMessage = $_POST['message'] ?? '';
    $sendEmail = isset($_POST['send_email']) ? true : false;
    
    if (!$testPhone || !$testMessage) {
        $result = [
            'success' => false,
            'error' => 'Phone number and message are required'
        ];
    } else {
        // Send SMS
        $smsResult = sendSmsViaMail($testPhone, $testMessage, 'Mowology Test');
        
        $result = [
            'success' => $smsResult['success'],
            'phone' => $testPhone,
            'message' => $testMessage,
            'sms_result' => $smsResult,
            'attempted_at' => date('Y-m-d H:i:s')
        ];
        
        // Optionally also send via email for comparison
        if ($sendEmail) {
            $email = $_POST['email'] ?? null;
            if ($email) {
                $emailBody = "<p><strong>SMS Test Message:</strong></p><p>{$testMessage}</p><p><strong>Sent to:</strong> {$testPhone}</p>";
                $emailSent = mail($email, "SMS Test Message", $emailBody, "Content-Type: text/html; charset=UTF-8\r\nFrom: Mowology <office@mowology.ca>\r\n");
                $result['email_sent'] = $emailSent;
                $result['email_address'] = $email;
            }
        }
    }
}

$pageTitle = 'SMS Test Form';
$activePage = 'settings';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div style="max-width: 700px; margin: 0 auto;">
    <h1 class="mb-4">SMS Testing Form</h1>
    
    <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
        <strong>Purpose:</strong> Test SMS delivery with custom messages. Results show which carriers accepted the message.
    </div>

    <?php if ($result): ?>
        <div style="margin-bottom: 20px; padding: 15px; border-radius: 4px; background: <?php echo $result['success'] ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $result['success'] ? '#c3e6cb' : '#f5c6cb'; ?>; color: <?php echo $result['success'] ? '#155724' : '#721c24'; ?>;">
            <strong><?php echo $result['success'] ? '✓ SMS Sent Successfully!' : '✗ SMS Send Failed'; ?></strong>
            
            <?php if (isset($result['error'])): ?>
                <p><?php echo htmlspecialchars($result['error']); ?></p>
            <?php else: ?>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($result['phone']); ?></p>
                <p><strong>Message:</strong> <?php echo htmlspecialchars($result['message']); ?></p>
                <p><strong>Attempted:</strong> <?php echo $result['attempted_at']; ?></p>
                
                <?php if ($result['sms_result']): ?>
                    <hr style="border: none; border-top: 1px solid; opacity: 0.3; margin: 10px 0;">
                    <p><strong>Carriers Queued:</strong> <?php echo implode(', ', $result['sms_result']['delivered_carriers'] ?? []); ?></p>
                    <?php if (!empty($result['sms_result']['errors'])): ?>
                        <p><strong>Errors:</strong></p>
                        <ul style="margin: 5px 0; padding-left: 20px;">
                            <?php foreach ($result['sms_result']['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($result['email_sent'])): ?>
                    <p><strong>Email Copy Sent:</strong> <?php echo $result['email_sent'] ? '✓ Yes' : '✗ No'; ?> (to <?php echo htmlspecialchars($result['email_address']); ?>)</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="background: white; padding: 20px; border-radius: 4px; border: 1px solid #ddd;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: bold;">Phone Number</label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($testPhone); ?>" placeholder="(778) 846-9273 or 7788469273" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                <small style="color: #666;">10-digit Canadian number (any format)</small>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: bold;">Message</label>
                <textarea name="message" placeholder="Enter your SMS message..." required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; font-family: Arial, sans-serif;" rows="4"><?php echo htmlspecialchars($testMessage); ?></textarea>
                <small style="color: #666;">Message will be truncated to 160 characters if needed</small>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="send_email" value="1" <?php echo $sendEmail ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                    <span>Also send email copy for comparison</span>
                </label>
            </div>

            <?php if ($sendEmail): ?>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: bold;">Email Address (Optional)</label>
                    <input type="email" name="email" placeholder="your@email.com" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 10px;">
                <button type="submit" style="padding: 12px 30px; background: #2D8659; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold;">
                    📱 Send SMS
                </button>
                <button type="reset" style="padding: 12px 30px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                    Clear
                </button>
            </div>
        </form>
    </div>

    <div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-radius: 4px; border: 1px solid #ddd;">
        <h3 style="margin-top: 0;">How It Works</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>Enter a phone number (10 digits, any format)</li>
            <li>Enter your test message</li>
            <li>Click "Send SMS"</li>
            <li>System attempts to send via all 10 Canadian carriers</li>
            <li>Check your phone for the SMS within 1-2 minutes</li>
            <li>Results show which carriers accepted the message</li>
        </ul>

        <h3>Troubleshooting</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li><strong>All carriers show "Queued":</strong> Server can reach SMS gateways. Check phone for message.</li>
            <li><strong>Some carriers rejected:</strong> Server may have delivery issues with those carriers.</li>
            <li><strong>Phone doesn't receive SMS:</strong> Check spam folder, or try different carrier test.</li>
        </ul>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
