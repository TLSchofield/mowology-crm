<?php
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

$db = getDB();
$dbTime = $db->query("SELECT NOW() AS db_now, UTC_TIMESTAMP() AS db_utc")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'php_time' => date('Y-m-d H:i:s'),
    'php_timestamp' => time(),
    'php_timezone' => date_default_timezone_get(),
    'mysql_now' => $dbTime['db_now'],
    'mysql_utc' => $dbTime['db_utc'],
]);
