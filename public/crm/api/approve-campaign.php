<?php
/**
 * Approve or Reject a pending-approval campaign.
 *
 * Campaigns with auto_send = 0 land in status='queued' and wait here
 * until a human approves or rejects them.
 *
 * POST body (JSON or form-encoded):
 *   csrf_token   string  required
 *   campaign_id  int     required
 *   action       string  required — 'approve' | 'reject'
 *   note         string  optional — reason shown in activity log
 *
 * Returns: { success: bool, message: string, campaign_id: int, new_status: string }
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Accept JSON body or form-encoded
$input = [];
$raw = file_get_contents('php://input');
if ($raw && ($decoded = json_decode($raw, true)) !== null) {
    $input = $decoded;
} else {
    $input = $_POST;
}

if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$campaignId = (int)($input['campaign_id'] ?? 0);
$action     = trim($input['action'] ?? '');
$note       = trim($input['note'] ?? '');

if (!$campaignId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'campaign_id required']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'action must be approve or reject']);
    exit;
}

$db = getDB();

// Load the campaign — must be queued + manual-approval
$stmt = $db->prepare("
    SELECT id, name, status, auto_send
    FROM marketing_campaigns
    WHERE id = ? AND status = 'queued' AND auto_send = 0
    LIMIT 1
");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Campaign not found or not awaiting approval']);
    exit;
}

$newStatus = $action === 'approve' ? 'sending' : 'cancelled';

$db->prepare("
    UPDATE marketing_campaigns
    SET status = ?, updated_at = NOW()
    WHERE id = ?
")->execute([$newStatus, $campaignId]);

$logDetail = $action === 'approve'
    ? "Campaign approved for sending: {$campaign['name']}"
    : "Campaign rejected/cancelled: {$campaign['name']}";
if ($note) {
    $logDetail .= " — {$note}";
}
logActivityExtended($user['id'], "campaign_{$action}d", $logDetail);

$message = $action === 'approve'
    ? "Campaign approved — queued for sending."
    : "Campaign rejected and cancelled.";

echo json_encode([
    'success'     => true,
    'message'     => $message,
    'campaign_id' => $campaignId,
    'new_status'  => $newStatus,
]);
