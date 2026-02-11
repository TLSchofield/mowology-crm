<?php
/**
 * API Endpoint: Save Block
 *
 * POST /crm/api/save-block.php
 * Create or update a CMS block with configuration
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$blockId = (int)($_POST['id'] ?? 0);
$pageId = (int)($_POST['page_id'] ?? 0);
$label = $_POST['label'] ?? '';
$isVisible = (bool)($_POST['is_visible'] ?? 1);

// Build config from form fields
$config = $_POST['config'] ?? [];

// Parse and validate JSON fields
foreach ($config as $key => $value) {
    if (is_string($value) && !empty($value)) {
        // Try to parse as JSON if it looks like JSON
        $firstChar = trim($value)[0] ?? '';
        if ($firstChar === '[' || $firstChar === '{') {
            $decoded = json_decode($value, true);
            if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                $config[$key] = $decoded;
            } elseif ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                // Invalid JSON detected
                error_log("Invalid JSON in block config field '$key': " . json_last_error_msg());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Invalid JSON in field '$key': " . json_last_error_msg()]);
                exit;
            }
        }
    }
}

try {
    if ($blockId) {
        // Update existing block
        $db = getDB();
        $stmt = $db->prepare('
            UPDATE cms_blocks
            SET label = ?, config = ?, is_visible = ?, updated_at = NOW()
            WHERE id = ? AND page_id = ?
        ');
        $stmt->execute([
            $label,
            json_encode($config),
            $isVisible ? 1 : 0,
            $blockId,
            $pageId,
        ]);

        echo json_encode([
            'success' => true,
            'block_id' => $blockId,
            'message' => 'Block updated successfully',
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Block ID required for update']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}
