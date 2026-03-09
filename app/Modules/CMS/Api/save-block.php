<?php
/**
 * API Endpoint: Save Block
 *
 * POST /crm/api/save-block.php
 * Create or update a CMS block with configuration
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
require_once CRM_INCLUDES . '/cms-functions.php';

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
$isVisible = isset($_POST['is_visible']) ? (bool)$_POST['is_visible'] : true;

// Build config from form fields
$config = $_POST['config'] ?? [];

// Store variant in config (reliable — no schema dependency)
$variant = $_POST['variant'] ?? 'default';
$config['variant'] = $variant;

// Store label in config (cms_blocks has no label column)
if (!empty($_POST['label'])) {
    $config['_label'] = $_POST['label'];
}

// Parse and validate JSON fields
foreach ($config as $key => $value) {
    if (is_string($value) && !empty($value)) {
        $firstChar = trim($value)[0] ?? '';
        if ($firstChar === '[' || $firstChar === '{') {
            $decoded = json_decode($value, true);
            if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                $config[$key] = $decoded;
            } elseif ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                error_log("Invalid JSON in block config field '$key': " . json_last_error_msg());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Invalid JSON in field '$key': " . json_last_error_msg()]);
                exit;
            }
        }
    }
}

// Build visibility JSON
$visibilityJson = $isVisible ? null : json_encode(['hidden' => true]);

try {
    if ($blockId) {
        $db = getDB();
        $stmt = $db->prepare('
            UPDATE cms_blocks
            SET config_json = ?, visibility_json = ?, updated_at = NOW()
            WHERE id = ? AND page_id = ?
        ');
        $stmt->execute([
            json_encode($config),
            $visibilityJson,
            $blockId,
            $pageId,
        ]);

        // Invalidate page cache
        cms_invalidateCache("blocks_page_{$pageId}");
        if (function_exists('cms_invalidatePageHtmlCache')) {
            cms_invalidatePageHtmlCache($pageId);
        }

        // Audit log
        cms_logCmsActivity(
            $user['id'],
            'block_updated',
            "Updated block #{$blockId} (page {$pageId})",
            ['block_id' => $blockId, 'page_id' => $pageId]
        );

        echo json_encode([
            'success' => true,
            'block_id' => $blockId,
            'message' => 'Block updated successfully',
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Block ID required for update']);
    }
} catch (PDOException $e) {
    error_log("save-block.php DB error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}
