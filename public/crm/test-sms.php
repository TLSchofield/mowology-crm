<?php
/**
 * SMS Gateway Test Page
 * Debug and test SMS delivery to any phone number
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/sms_gateway.php';

requireLogin();
$user = getCurrentUser();

// Only allow admins
if ($user['role'] !== 'admin') {
    die('Access denied');
}

$result = null;
$testPhone = '';
$testMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testPhone = $_POST['phone'] ?? '';
    $testMessage = $_POST['message'] ?? '';
    
    if ($testPhone && $testMessage) {
        $result = sendSmsViaMail($testPhone, $testMessage, 'Mowology Test');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SMS Gateway Test</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 20px; border-radius: 8px; max-width: 600px; }
        h1 { color: #333; }
        form { display: flex; flex-direction: column; gap: 10px; }
        input, textarea { padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        button { padding: 10px 20px; background: #2D8659; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1A5F4A; }
        .result { padding: 15px; margin-top: 20px; border-radius: 4px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class="container">
    <h1>SMS Gateway Test</h1>
    
    <form method="POST">
        <label>Phone Number (e.g., 202-555-1234 or 2025551234)</label>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($testPhone); ?>" required placeholder="Enter 10-digit phone">
        
        <label>Message</label>
        <textarea name="message" rows="3" required placeholder="Enter SMS message (max 160 chars)"><?php echo htmlspecialchars($testMessage); ?></textarea>
        
        <button type="submit">Send Test SMS</button>
    </form>
    
    <?php if ($result): ?>
        <div class="result <?php echo $result['success'] ? 'success' : 'error'; ?>">
            <strong><?php echo $result['success'] ? '✓ SMS Sent Successfully!' : '✗ SMS Failed'; ?></strong>
            <pre><?php echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></pre>
            
            <?php if ($result['success']): ?>
                <p><strong>Message delivered via:</strong> <?php echo implode(', ', $result['delivered_carriers']); ?></p>
                <p><em>Note: Actual SMS delivery depends on the carrier accepting the email-to-SMS gateway. Check your phone's SMS inbox.</em></p>
            <?php else: ?>
                <p><strong>Errors:</strong></p>
                <ul>
                    <?php foreach ($result['errors'] as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="info" style="margin-top: 20px;">
        <h3>How SMS Gateway Works</h3>
        <ul>
            <li>Sends SMS via carrier email-to-SMS gateways (free, no API)</li>
            <li>Tries all major Canadian carriers automatically</li>
            <li>One will succeed - message is delivered as SMS to phone</li>
            <li>Supported carriers: Bell, Rogers, Telus, Koodo, Virgin, Fido, Freedom, PC Mobile, Eastlink, SaskTel</li>
        </ul>
    </div>
</div>
</body>
</html>
