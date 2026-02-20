<?php
/**
 * Tracking Batch Sync API
 * ───────────────────────
 * Receives batches of GPS points and compliance events from the
 * Android native app's WorkManager (SyncWorker).
 *
 * POST with JSON body:
 *   { "action": "sync_points", "points": [...] }
 *   { "action": "sync_compliance", "events": [...] }
 *
 * Idempotent: uses device_id + timestamp to prevent duplicates.
 * Auth: uses PHPSESSID cookie (passed by WorkManager from SharedPreferences).
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON body');
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'sync_points':
            $result = syncLocationPoints($db, $user, $input['points'] ?? []);
            echo json_encode(['success' => true, 'inserted' => $result['inserted'], 'skipped' => $result['skipped']]);
            break;

        case 'sync_compliance':
            $result = syncComplianceEvents($db, $user, $input['events'] ?? []);
            echo json_encode(['success' => true, 'inserted' => $result['inserted'], 'skipped' => $result['skipped']]);
            break;

        default:
            throw new Exception('Invalid action. Use: sync_points, sync_compliance');
    }

} catch (PDOException $e) {
    error_log('Tracking Sync API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Batch insert GPS location points into crew_location_history.
 * Deduplicates by (crew_id, timestamp) — if a point with the same
 * user and timestamp already exists, skip it.
 */
function syncLocationPoints(PDO $db, array $user, array $points): array
{
    if (empty($points)) {
        return ['inserted' => 0, 'skipped' => 0];
    }

    $userId = (int)$user['id'];
    $inserted = 0;
    $skipped = 0;

    // Prepare insert statement
    $stmt = $db->prepare("
        INSERT INTO crew_location_history
            (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
        VALUES (?, ?, ?, ?, NULL, ?)
    ");

    // Prepare dedup check — check if point already exists within 1 second
    $dedupStmt = $db->prepare("
        SELECT COUNT(*) FROM crew_location_history
        WHERE crew_id = ?
          AND ABS(TIMESTAMPDIFF(SECOND, timestamp, ?)) < 2
          AND ABS(latitude - ?) < 0.00001
          AND ABS(longitude - ?) < 0.00001
    ");

    foreach ($points as $pt) {
        $lat = isset($pt['lat']) ? (float)$pt['lat'] : null;
        $lng = isset($pt['lng']) ? (float)$pt['lng'] : null;
        $accuracy = isset($pt['accuracy']) ? (int)round((float)$pt['accuracy']) : null;
        $timestamp = isset($pt['timestamp']) ? (int)$pt['timestamp'] : null;

        if (!$lat || !$lng || !$timestamp) {
            $skipped++;
            continue;
        }

        // Convert epoch millis to MySQL datetime
        $mysqlTimestamp = date('Y-m-d H:i:s', (int)($timestamp / 1000));

        // Dedup check
        $dedupStmt->execute([$userId, $mysqlTimestamp, $lat, $lng]);
        if ((int)$dedupStmt->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        // Insert
        $stmt->execute([$userId, $lat, $lng, $accuracy, $mysqlTimestamp]);
        $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

/**
 * Batch insert compliance events into a compliance_events table.
 * Creates the table if it doesn't exist (migration-safe).
 */
function syncComplianceEvents(PDO $db, array $user, array $events): array
{
    if (empty($events)) {
        return ['inserted' => 0, 'skipped' => 0];
    }

    // Ensure compliance_events table exists
    ensureComplianceTable($db);

    $userId = (int)$user['id'];
    $inserted = 0;
    $skipped = 0;

    $stmt = $db->prepare("
        INSERT INTO compliance_events
            (user_id, event_type, latitude, longitude, accuracy_meters,
             visit_id, job_id, reason, metadata, device_timestamp, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Dedup: same user + event_type + device_timestamp within 2 seconds
    $dedupStmt = $db->prepare("
        SELECT COUNT(*) FROM compliance_events
        WHERE user_id = ?
          AND event_type = ?
          AND ABS(TIMESTAMPDIFF(SECOND, device_timestamp, ?)) < 2
    ");

    foreach ($events as $ev) {
        $eventType = $ev['event_type'] ?? 'unknown';
        $lat = isset($ev['lat']) ? (float)$ev['lat'] : 0;
        $lng = isset($ev['lng']) ? (float)$ev['lng'] : 0;
        $accuracy = isset($ev['accuracy']) ? (int)round((float)$ev['accuracy']) : null;
        $visitId = !empty($ev['visit_id']) ? (int)$ev['visit_id'] : null;
        $jobId = !empty($ev['job_id']) ? (int)$ev['job_id'] : null;
        $reason = $ev['reason'] ?? null;
        $metadata = $ev['metadata'] ?? null;
        $timestamp = isset($ev['timestamp']) ? (int)$ev['timestamp'] : null;

        if (!$timestamp) {
            $skipped++;
            continue;
        }

        $mysqlTimestamp = date('Y-m-d H:i:s', (int)($timestamp / 1000));

        // Dedup check
        $dedupStmt->execute([$userId, $eventType, $mysqlTimestamp]);
        if ((int)$dedupStmt->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        $stmt->execute([
            $userId, $eventType, $lat, $lng, $accuracy,
            $visitId, $jobId, $reason, $metadata, $mysqlTimestamp
        ]);
        $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

/**
 * Ensure the compliance_events table exists.
 * Safe to call multiple times — uses CREATE TABLE IF NOT EXISTS.
 */
function ensureComplianceTable(PDO $db): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db->exec("
        CREATE TABLE IF NOT EXISTS compliance_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            latitude DECIMAL(10,8) DEFAULT 0,
            longitude DECIMAL(11,8) DEFAULT 0,
            accuracy_meters INT DEFAULT NULL,
            visit_id INT DEFAULT NULL,
            job_id INT DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            metadata TEXT DEFAULT NULL,
            device_timestamp DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_compliance_user (user_id),
            INDEX idx_compliance_type (event_type),
            INDEX idx_compliance_visit (visit_id),
            INDEX idx_compliance_time (device_timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}
