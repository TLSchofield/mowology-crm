<?php
/**
 * API: Reorder quote line items + update section assignments.
 * POST (JSON body) { quote_id, items: [{id, sort_order, section_name}], csrf_token }
 */
require_once dirname(dirname(__DIR__)) . '/loginAuth/auth.php';
requireLogin();
requirePermission('billing.edit');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : null;
if (!$data) {
    $data = $_POST;
}

$csrfToken = $data['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
session_write_close();

$quoteId = intval($data['quote_id'] ?? 0);
$items   = $data['items'] ?? [];

if (!$quoteId || empty($items) || !is_array($items)) {
    echo json_encode(['success' => false, 'error' => 'Missing required data']);
    exit;
}

$db = getDB();

// Verify the quote exists
$stmt = $db->prepare("SELECT id FROM quotes WHERE id = ?");
$stmt->execute([$quoteId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Quote not found']);
    exit;
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare(
        "UPDATE quote_line_items SET sort_order = ?, section_name = ? WHERE id = ? AND quote_id = ?"
    );

    foreach ($items as $item) {
        $itemId      = intval($item['id'] ?? 0);
        $sortOrder   = intval($item['sort_order'] ?? 0);
        $sectionName = (isset($item['section_name']) && trim($item['section_name']) !== '')
            ? trim($item['section_name'])
            : null;

        if ($itemId > 0) {
            $stmt->execute([$sortOrder, $sectionName, $itemId, $quoteId]);
        }
    }

    $db->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $db->rollBack();
    error_log("Quote reorder error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
