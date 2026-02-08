<?php
echo "PHP is working!<br>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Current directory: " . __DIR__ . "<br>";
echo "Parent directory exists: " . (file_exists(dirname(__DIR__) . '/session_config.php') ? 'YES' : 'NO') . "<br>";
echo "Config exists: " . (file_exists(dirname(__DIR__) . '/config.php') ? 'YES' : 'NO') . "<br>";

// Test session
echo "<br>Testing session...<br>";
$sessionPath = dirname(__DIR__) . '/sessions';
echo "Session path: " . $sessionPath . "<br>";
echo "Session dir exists: " . (file_exists($sessionPath) ? 'YES' : 'NO') . "<br>";
echo "Session dir writable: " . (is_writable($sessionPath) ? 'YES' : 'NO') . "<br>";

// Try to start session manually
ini_set('session.save_path', $sessionPath);
if (session_status() === PHP_SESSION_NONE) {
    $result = @session_start();
    echo "Session start result: " . ($result ? 'SUCCESS' : 'FAILED') . "<br>";
}
echo "Session ID: " . session_id() . "<br>";
