<?php
// Test session persistence between pages
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/config.php';

echo "<h2>Session Test</h2>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session Save Path:</strong> " . session_save_path() . "</p>";

// Increment counter
if (!isset($_SESSION['test_counter'])) {
    $_SESSION['test_counter'] = 0;
}
$_SESSION['test_counter']++;

echo "<p><strong>Counter:</strong> " . $_SESSION['test_counter'] . " (refresh to increment)</p>";

// Test quote_data
if (isset($_SESSION['quote_data'])) {
    echo "<p style='color: green;'><strong>quote_data EXISTS!</strong></p>";
    echo "<pre>" . print_r($_SESSION['quote_data'], true) . "</pre>";
} else {
    echo "<p style='color: red;'><strong>quote_data NOT SET</strong></p>";
}

echo "<h3>All Session Data:</h3>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

echo "<p><a href='jobFlow-getQuote.php'>Go to Quote Form</a></p>";
