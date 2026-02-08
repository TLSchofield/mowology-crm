<?php
// View recent PHP error logs
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/config.php';

// Check if user is logged in (basic auth check)
if (!isset($_SESSION['user_id'])) {
    die('Please log in first');
}

echo "<h2>Recent Error Log Entries</h2>";
echo "<pre style='background:#1e1e1e; color:#d4d4d4; padding:20px; overflow:auto; max-height:600px;'>";

// Try to find the error log
$possiblePaths = [
    ini_get('error_log'),
    '/home/mowology/logs/error.log',
    '/home/mowology/public_html/error_log',
    dirname(__DIR__) . '/error_log',
];

$logPath = null;
foreach ($possiblePaths as $path) {
    if ($path && file_exists($path) && is_readable($path)) {
        $logPath = $path;
        break;
    }
}

if ($logPath) {
    echo "Reading from: " . htmlspecialchars($logPath) . "\n\n";

    // Get last 100 lines
    $lines = [];
    $fp = fopen($logPath, 'r');
    if ($fp) {
        // Seek to end
        fseek($fp, -10000, SEEK_END);
        fgets($fp); // discard partial line
        while (!feof($fp)) {
            $lines[] = fgets($fp);
        }
        fclose($fp);
    }

    // Show last 50 lines
    $lines = array_slice($lines, -50);
    foreach ($lines as $line) {
        // Highlight JOBFLOW entries
        if (strpos($line, 'JOBFLOW') !== false) {
            echo "<span style='color:#4ec9b0;'>" . htmlspecialchars($line) . "</span>";
        } else {
            echo htmlspecialchars($line);
        }
    }
} else {
    echo "Could not find readable error log.\n";
    echo "Checked paths:\n";
    foreach ($possiblePaths as $path) {
        echo "  - " . htmlspecialchars($path ?: '(empty)') . "\n";
    }
    echo "\nCurrent error_log setting: " . ini_get('error_log') . "\n";
}

echo "</pre>";
echo "<p><a href='test-session.php'>Back to Session Test</a></p>";
?>
