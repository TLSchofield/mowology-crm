<?php
/**
 * AJAX Handler: Save Quote Note
 * POST endpoint for adding notes to quotes
 * Returns JSON response
 */

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

header('Content-Type: application/json');

// Verify authentication
try {
    requireLogin();
    $user = getCurrentUser();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get and validate input
$quoteId = isset($_POST['quote_id']) ? intval($_POST['quote_id']) : 0;
$content = trim($_POST['content'] ?? '');
$noteType = trim($_POST['note_type'] ?? 'general');

// Validate required fields
if (!$quoteId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Quote ID is required']);
    exit;
}

if (empty($content)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Note content is required']);
    exit;
}

// Validate note type
$validTypes = ['general', 'customer_request', 'issue', 'follow_up', 'internal'];
if (!in_array($noteType, $validTypes)) {
    $noteType = 'general';
}

// Add the note
try {
    $note = addQuoteNote($quoteId, $content, $noteType, $user['id']);

    if ($note) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Note added successfully',
            'note' => [
                'id' => $note['id'],
                'content' => htmlspecialchars($note['content']),
                'note_type' => $note['note_type'],
                'created_by_name' => htmlspecialchars($note['created_by_name'] ?? 'System'),
                'created_at' => $note['created_at'],
                'type_label' => getNoteTypeLabel($note['note_type']),
                'type_class' => getNoteTypeClass($note['note_type'])
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to add note']);
    }
} catch (Exception $e) {
    error_log("Note save error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
