<?php
/**
 * /crm/api/schema-snapshot.php
 * Schema Snapshot API Handler
 *
 * Provides endpoints for:
 * - Generating a new schema snapshot
 * - Returning the current cached snapshot
 * - Comparing snapshots for drift detection
 * - Listing available historical snapshots
 *
 * All endpoints require:
 * - Admin role
 * - Valid CSRF token
 * - POST method
 */

declare(strict_types=1);

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/schema-functions.php';

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
        case 'generate':
            handleGenerate();
            break;

        case 'current':
            handleCurrent();
            break;

        case 'compare':
            handleCompare($input);
            break;

        case 'history':
            handleHistory();
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
 * Generate a new schema snapshot from the live database
 */
function handleGenerate() {
    $db = getDB();
    $storagePath = getStoragePath();

    $snapshot = getSchemaSnapshot($db);
    $written = writeSchemaSnapshot($snapshot, $storagePath);

    if (!$written) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to write snapshot file']);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Snapshot generated: %d tables, %d columns, %d indexes',
            $snapshot['summary']['table_count'],
            $snapshot['summary']['total_columns'],
            $snapshot['summary']['total_indexes']
        ),
        'summary' => $snapshot['summary'],
        'generated_at' => $snapshot['generated_at'],
    ]);
}

/**
 * Return the current cached snapshot (fast, reads from file)
 */
function handleCurrent() {
    $storagePath = getStoragePath();
    $snapshot = readSchemaSnapshot($storagePath);

    if ($snapshot === null) {
        echo json_encode([
            'success' => false,
            'error' => 'No snapshot found. Generate one first.',
            'has_snapshot' => false,
        ]);
        return;
    }

    echo json_encode([
        'success' => true,
        'has_snapshot' => true,
        'snapshot' => $snapshot,
    ]);
}

/**
 * Compare current snapshot against a historical one
 */
function handleCompare($input) {
    $historyFile = $input['history_file'] ?? null;

    if (!$historyFile) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing history_file parameter']);
        return;
    }

    // Validate filename (prevent path traversal)
    if (preg_match('/\.\./', $historyFile) || !preg_match('/^snapshot_[\d-_]+\.json$/', $historyFile)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid history filename']);
        return;
    }

    $storagePath = getStoragePath();
    $historyPath = getHistoryPath();

    // Read current snapshot
    $current = readSchemaSnapshot($storagePath);
    if ($current === null) {
        echo json_encode(['success' => false, 'error' => 'No current snapshot found']);
        return;
    }

    // Read historical snapshot
    $historyFilePath = $historyPath . '/' . $historyFile;
    if (!file_exists($historyFilePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Historical snapshot not found']);
        return;
    }

    $previous = json_decode(file_get_contents($historyFilePath), true);
    if ($previous === null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to parse historical snapshot']);
        return;
    }

    $diff = compareSnapshots($current, $previous);

    echo json_encode([
        'success' => true,
        'diff' => $diff,
    ]);
}

/**
 * List available historical snapshots
 */
function handleHistory() {
    $historyPath = getHistoryPath();
    $history = getSnapshotHistory($historyPath);

    echo json_encode([
        'success' => true,
        'count' => count($history),
        'history' => $history,
    ]);
}
