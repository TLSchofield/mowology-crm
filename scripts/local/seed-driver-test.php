<?php
$sock = getenv('HOME').'/.mowology-local/mysql.sock';
$pw = trim(file_get_contents(getenv('HOME').'/.mowology-local/db-password'));
$db = new PDO("mysql:unix_socket=$sock;dbname=mowology_local;charset=utf8mb4", 'mowology_local', $pw, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->prepare("UPDATE users SET password_hash=? WHERE id=3")->execute([password_hash('LocalTest-12345', PASSWORD_DEFAULT)]);
$db->exec("INSERT INTO time_clock_settings (setting_key,setting_value) VALUES ('fleet_vehicles','TEST-TRUCK') ON DUPLICATE KEY UPDATE setting_value='TEST-TRUCK'");
$db->prepare("INSERT INTO ops_settings (setting_key,setting_value) VALUES ('manager_override_pin',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([password_hash('4821', PASSWORD_DEFAULT)]);
echo "ok\n";
