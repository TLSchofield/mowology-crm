<?php
/**
 * Quote SMS Status Checker
 * Shows which quotes have SMS consent enabled
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

// Get all recent quotes with SMS consent info
$stmt = $db->prepare("
    SELECT 
        q.id,
        q.quote_number,
        q.status,
        qrc.first_name,
        qrc.last_name,
        qrc.phone,
        qrc.receive_sms,
        qrc.consent_sms,
        q.sent_via,
        q.sent_at
    FROM quotes q
    LEFT JOIN quote_requests qr ON q.id = qr.quote_id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    ORDER BY q.created_at DESC
    LIMIT 20
");
$stmt->execute();
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Quote SMS Status</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: bold; }
        tr:hover { background: #f9f9f9; }
        .status-draft { color: #6c757d; }
        .status-sent { color: #28a745; }
        .sms-yes { background: #d4edda; padding: 4px 8px; border-radius: 3px; }
        .sms-no { background: #f8d7da; padding: 4px 8px; border-radius: 3px; }
        .phone { font-family: monospace; }
        h1 { color: #333; margin-bottom: 10px; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Quote SMS Consent Status</h1>
    <div class="info">
        <strong>Which quotes can receive SMS?</strong><br>
        Only quotes where the contact has <strong>receive_sms = Yes</strong> or <strong>consent_sms = Yes</strong> will receive SMS notifications.
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Quote #</th>
                <th>Status</th>
                <th>Contact</th>
                <th>Phone</th>
                <th>SMS Consent</th>
                <th>Last Sent Via</th>
                <th>Sent At</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($quotes as $q): ?>
                <tr>
                    <td><a href="view.php?id=<?php echo $q['id']; ?>"><?php echo htmlspecialchars($q['quote_number']); ?></a></td>
                    <td class="status-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></td>
                    <td><?php echo htmlspecialchars(($q['first_name'] ?? 'N/A') . ' ' . ($q['last_name'] ?? '')); ?></td>
                    <td class="phone"><?php echo htmlspecialchars($q['phone'] ?? 'N/A'); ?></td>
                    <td>
                        <?php 
                            if ($q['receive_sms'] || $q['consent_sms']) {
                                echo '<span class="sms-yes">✓ Yes</span>';
                            } else {
                                echo '<span class="sms-no">✗ No</span>';
                            }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($q['sent_via'] ?? '-'); ?></td>
                    <td><?php echo $q['sent_at'] ? substr($q['sent_at'], 0, 10) : '-'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
