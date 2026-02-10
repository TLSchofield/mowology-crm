<?php
/**
 * Email Logs Viewer
 * View email send attempts and debug delivery issues
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

$logDir = dirname(__DIR__) . '/email-logs';
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
    <title>Email Send Logs</title>
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
        }
        .log-content::-webkit-scrollbar { width: 8px; }
        .log-content::-webkit-scrollbar-track { background: #1e1e1e; }
        .log-content::-webkit-scrollbar-thumb { background: #464647; border-radius: 4px; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        .back-link { display: inline-block; margin-top: 15px; color: #007acc; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Email Send Logs</h1>

        <?php if ($logExists): ?>
            <div class="info">
                <strong>✅ Log file found:</strong> email-<?php echo date('Y-m-d'); ?>.log
                <br><small>Shows all email send attempts for today</small>
            </div>

            <div class="log-content">
<?php echo htmlspecialchars($logContent); ?>
            </div>

            <div class="info" style="margin-top: 20px; border-left-color: #dcdcaa;">
                <strong>📋 How to read this log:</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li><span class="success">✅ ACCEPTED</span> - mail() returned TRUE (email queued)</li>
                    <li><span class="error">❌ FAILED</span> - mail() returned FALSE (rejected by server)</li>
                    <li>Check the headers for any issues with From address, authentication, etc.</li>
                    <li>If mail() returned TRUE but email doesn't arrive, the issue is with email delivery (SPF, DKIM, spam filters)</li>
                </ul>
            </div>

        <?php else: ?>
            <div class="info" style="border-left-color: #f48771; color: #f48771;">
                <strong>❌ No log file found for today</strong>
                <br><small>Email logs appear here after you send a quote</small>
            </div>

            <ol style="line-height: 1.8;">
                <li>Go to a quote: /crm/quotes/view.php?id=1</li>
                <li>Click "Send to Customer" button</li>
                <li>Return to this page to see what happened</li>
            </ol>

        <?php endif; ?>

        <a href="quotes/index.php" class="back-link">&larr; Back to Quotes</a>
    </div>
</body>
</html>
