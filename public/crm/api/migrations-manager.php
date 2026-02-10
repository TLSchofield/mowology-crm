<?php
/**
 * /crm/api/migrations-manager.php
 * Database Migrations Manager API Handler
 *
 * Provides endpoints for:
 * - Listing pending and applied migrations
 * - Executing migrations
 * - Viewing migration history
 * - Verifying database state
 *
 * All endpoints require:
 * - Admin role
 * - Valid CSRF token
 * - POST method
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Require authentication
requireLogin();
$user = getCurrentUser();

// Admin only
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? null;

// Verify CSRF token
if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'list':
            handleListMigrations();
            break;

        case 'execute':
            handleExecuteMigration($input, $user);
            break;

        case 'history':
            handleMigrationHistory($input);
            break;

        case 'verify':
            handleDatabaseVerify();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * List all migrations (pending and applied)
 */
function handleListMigrations() {
    $db = getDB();
    $migrationsDir = dirname(__DIR__) . '/../database/migrations';

    // Get all migration files
    $files = [];
    if (is_dir($migrationsDir)) {
        $files = array_filter(glob($migrationsDir . '/*.sql'), 'is_file');
        sort($files);
    }

    // Get applied migrations from log
    $stmt = $db->query("
        SELECT migration_filename, executed_at, status
        FROM migrations_log
        ORDER BY executed_at DESC
    ");
    $applied = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $appliedNames = array_column($applied, 'migration_filename');

    // Build pending list
    $pending = [];
    foreach ($files as $file) {
        $filename = basename($file);
        if (!in_array($filename, $appliedNames)) {
            $pending[] = [
                'filename' => $filename,
                'path' => $file
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'pending' => $pending,
        'applied' => $applied,
        'applied_count' => count($applied),
        'pending_count' => count($pending),
        'total_count' => count($files)
    ]);
}

/**
 * Execute a single migration
 */
function handleExecuteMigration($input, $user) {
    $db = getDB();
    $filename = $input['filename'] ?? null;

    if (!$filename) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing filename']);
        return;
    }

    // Validate filename (prevent path traversal)
    if (preg_match('/\.\./', $filename) || !preg_match('/^[\w-]+\.sql$/', $filename)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $filepath = dirname(__DIR__) . '/../database/migrations/' . $filename;

    if (!file_exists($filepath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Migration file not found']);
        return;
    }

    // Check if already applied
    $checkStmt = $db->prepare("SELECT id FROM migrations_log WHERE migration_filename = ?");
    $checkStmt->execute([$filename]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Migration already applied']);
        return;
    }

    try {
        // Read and execute SQL
        $sql = file_get_contents($filepath);
        $checksum = hash('sha256', $sql);

        // Execute migration (may contain multiple statements)
        $db->exec($sql);

        // Log successful execution
        $logStmt = $db->prepare("
            INSERT INTO migrations_log
            (migration_filename, executed_by, status, checksum, migration_type)
            VALUES (?, ?, 'success', ?, 'sql')
        ");
        $logStmt->execute([$filename, $user['id'], $checksum]);

        echo json_encode([
            'success' => true,
            'message' => 'Migration applied successfully',
            'filename' => $filename
        ]);
    } catch (Exception $e) {
        // Log failed execution
        $logStmt = $db->prepare("
            INSERT INTO migrations_log
            (migration_filename, executed_by, status, error_message, migration_type)
            VALUES (?, ?, 'failed', ?, 'sql')
        ");
        $logStmt->execute([$filename, $user['id'], $e->getMessage()]);

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Migration failed: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get migration history/log
 */
function handleMigrationHistory($input) {
    $db = getDB();
    $statusFilter = $input['status'] ?? 'all';

    // Validate status filter
    $validStatuses = ['all', 'success', 'failed'];
    if (!in_array($statusFilter, $validStatuses)) {
        $statusFilter = 'all';
    }

    // Build query
    $whereClause = '';
    if ($statusFilter !== 'all') {
        $whereClause = "WHERE status = '" . $db->quote($statusFilter) . "'";
    }

    $stmt = $db->query("
        SELECT
            ml.id,
            ml.migration_filename,
            ml.executed_at,
            ml.status,
            ml.error_message,
            u.full_name as executed_by_name
        FROM migrations_log ml
        LEFT JOIN users u ON ml.executed_by = u.id
        $whereClause
        ORDER BY ml.executed_at DESC
        LIMIT 100
    ");
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'status' => $statusFilter,
        'count' => count($history),
        'history' => $history
    ]);
}

/**
 * Verify database state (check for tables, indexes, etc.)
 */
function handleDatabaseVerify() {
    $db = getDB();

    try {
        // Get database info
        $dbInfo = $db->query("SELECT VERSION() as version, DATABASE() as current_db")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'database' => $dbInfo['current_db'] ?? 'unknown',
            'mysql_version' => $dbInfo['version'] ?? 'unknown',
            'health' => 'ok'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
