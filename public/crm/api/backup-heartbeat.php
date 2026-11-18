<?php
/**
 * Backup heartbeat — the iMac reports each nightly backup / composer audit here (2026-10-13).
 * The server cannot see the Mac, so Ada's backup and dependency checks read these rows.
 *
 *   POST /crm/api/backup-heartbeat.php
 *   Header  X-Heartbeat-Token: <token>     (header only — never a query string)
 *   Body    JSON {"kind":"db"|"files"|"composer", "ok":true, "bytes":123, "finished_at":"2026-10-13T02:41:00-07:00",
 *                 "advisories":0, "detail":"…"}
 *
 * Token: BACKUP_HEARTBEAT_TOKEN in secrets.php when defined, else
 * hash_hmac('sha256', 'backup-heartbeat', BLUEMOON_JWT_SECRET) (SecurityPostureService::heartbeatToken()).
 * The Mac reads it from Keychain item mowology-backup-heartbeat, else scripts/.backup-heartbeat-token.
 * Writes one row to backup_heartbeats (migration 1347). No session, no CSRF: the token is the auth.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST only']);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/Core/paths.php';
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Modules/Technology/Services/SecurityPostureService.php';

$expected = SecurityPostureService::heartbeatToken();
$given = (string)($_SERVER['HTTP_X_HEARTBEAT_TOKEN'] ?? '');
if ($expected === null || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 16384);
$in = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Body must be JSON']);
    exit;
}
$row = SecurityPostureRules::heartbeatRow($in, date('Y-m-d H:i:s'));
if (is_string($row)) {
    http_response_code(400);
    echo json_encode(['error' => $row]);
    exit;
}

try {
    getDB()->prepare("INSERT INTO backup_heartbeats (kind, ok, bytes, advisories, detail, finished_at, received_at, source_ip)
                      VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)")
        ->execute([$row['kind'], $row['ok'], $row['bytes'], $row['advisories'], $row['detail'], $row['finished_at'],
                   substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
} catch (Throwable $e) {
    error_log('backup-heartbeat: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not record the heartbeat']);
    exit;
}
echo json_encode(['ok' => true, 'kind' => $row['kind'], 'finished_at' => $row['finished_at']]);
