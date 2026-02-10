<?php
/**
 * Simple Email Logs Viewer - No dependencies
 * View email send attempts for debugging
 */

$logDir = __DIR__ . '/email-logs';
$logFile = $logDir . '/email-' . date('Y-m-d') . '.log';
$logContent = '';
$logExists = false;

if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    $logExists = true;
}

?><!DOCTYPE html>
<html>
<head>
    <title>Email Logs</title>
    <style>
        body {
            font-family: monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #252526;
            padding: 20px;
            border-radius: 4px;
            border: 1px solid #3e3e42;
        }
        h1 { color: #4ec9b0; margin-top: 0; }
        .info {
            background: #1e1e1e;
            padding: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #007acc;
            border-radius: 2px;
        }
        .log-content {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 2px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #3e3e42;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Email Send Logs</h1>

        <?php if ($logExists): ?>
            <div class="info">
                <strong>✅ Log file found:</strong> email-<?php echo date('Y-m-d'); ?>.log
            </div>

            <div class="log-content">
<?php echo htmlspecialchars($logContent); ?>
            </div>

        <?php else: ?>
            <div class="info" style="border-left-color: #ffc107;">
                <strong>ℹ️ No logs yet</strong>
                <br>Send a quote to generate logs
            </div>

            <p>Log directory: <?php echo htmlspecialchars($logDir); ?></p>
            <p>Expected file: <?php echo htmlspecialchars($logFile); ?></p>
            <p>File exists: <?php echo file_exists($logDir) ? 'Yes' : 'No'; ?></p>

        <?php endif; ?>

        <p style="margin-top: 30px;">
            <a href="/crm/quotes/index.php" style="color: #007acc;">← Back to Quotes</a>
        </p>
    </div>
</body>
</html>
