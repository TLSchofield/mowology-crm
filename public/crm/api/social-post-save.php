<?php
/**
 * /crm/api/social-post-save.php
 * Wizard post submission endpoint.
 *
 * Creates a social_posts record + social_post_platforms rows for
 * each selected platform. Called by the Post Creation Wizard.
 *
 * POST body (JSON):
 *   csrf_token   string
 *   goal         string
 *   template_id  int|null
 *   caption      string
 *   hashtags     string
 *   neighborhood string
 *   city         string
 *   service_type string
 *   platforms    string[]   e.g. ['gbp','facebook']
 *   schedule     string|null  ISO datetime or null (= pending/draft)
 *   can_approve  bool
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

requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ── Validate & sanitise inputs ────────────────────────────────────────────
$caption     = trim($input['caption']      ?? '');
$hashtags    = trim($input['hashtags']     ?? '');
$neighborhood = trim($input['neighborhood'] ?? '');
$city        = trim($input['city']         ?? 'Vancouver');
$serviceType = trim($input['service_type'] ?? '');
$templateId  = isset($input['template_id']) ? (int)$input['template_id'] : null;
$platforms   = is_array($input['platforms'] ?? null) ? $input['platforms'] : [];
$scheduleRaw = $input['schedule'] ?? null;
$canApprove  = !empty($input['can_approve']);

// Validate caption
if (strlen($caption) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Caption is too short']);
    exit;
}

// Sanitise platform list
$validPlatforms = ['gbp', 'facebook', 'instagram'];
$platforms = array_filter($platforms, fn($p) => in_array($p, $validPlatforms, true));

// Parse schedule
$scheduledAt = null;
if ($scheduleRaw && $scheduleRaw !== 'now') {
    $ts = strtotime($scheduleRaw);
    if ($ts !== false && $ts > time()) {
        $scheduledAt = date('Y-m-d H:i:s', $ts);
    }
}

// Determine post status
if ($canApprove) {
    $status = $scheduledAt ? 'scheduled' : 'approved';
} else {
    $status = 'pending_approval';
}

try {
    $db = getDB();

    // ── Create social_posts row ───────────────────────────────────────────
    $stmt = $db->prepare("
        INSERT INTO social_posts
            (caption, hashtags, neighborhood, city, service_type,
             template_id, status, scheduled_at, created_by, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $caption,
        $hashtags ?: null,
        $neighborhood ?: null,
        $city ?: 'Vancouver',
        $serviceType ?: null,
        $templateId ?: null,
        $status,
        $scheduledAt,
        $user['id']
    ]);
    $postId = (int)$db->lastInsertId();

    // ── Create social_post_platforms rows ─────────────────────────────────
    if ($postId > 0 && !empty($platforms)) {
        // Look up account IDs for selected platforms
        $platStmt = $db->prepare("
            SELECT id, platform
            FROM social_accounts
            WHERE platform = ? AND is_active = 1
            LIMIT 1
        ");

        $ppStmt = $db->prepare("
            INSERT INTO social_post_platforms (post_id, account_id, platform, status)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($platforms as $platform) {
            $platStmt->execute([$platform]);
            $account = $platStmt->fetch(PDO::FETCH_ASSOC);
            if ($account) {
                $ppStmt->execute([
                    $postId,
                    $account['id'],
                    $platform,
                    $status === 'scheduled' ? 'pending' : 'pending'
                ]);
            }
        }
    }

    // ── Enqueue for publishing ────────────────────────────────────────────
    // 'scheduled' posts use the chosen time; 'approved' posts publish immediately (NOW).
    // 'pending_approval' posts are not enqueued — they wait for approval first.
    if (!empty($platforms) && in_array($status, ['scheduled', 'approved'], true)) {
        $effectiveSchedule = $scheduledAt ?? date('Y-m-d H:i:s'); // NOW for approved/immediate
        $qStmt = $db->prepare("
            INSERT INTO social_queue
                (post_id, account_id, platform, scheduled_at, status)
            SELECT spp.post_id, spp.account_id, spp.platform, ?, 'pending'
            FROM social_post_platforms spp
            WHERE spp.post_id = ?
        ");
        $qStmt->execute([$effectiveSchedule, $postId]);
    }

    // ── Audit log ─────────────────────────────────────────────────────────
    try {
        $db->prepare("
            INSERT INTO social_audit_log (user_id, action, entity_type, entity_id, detail, ip_address)
            VALUES (?, 'create_post', 'social_post', ?, ?, ?)
        ")->execute([
            $user['id'],
            $postId,
            json_encode(['status' => $status, 'platforms' => $platforms, 'via' => 'wizard']),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        // Non-fatal — audit logging failure should not block the response
    }

    // ── Update template usage count ───────────────────────────────────────
    if ($templateId) {
        $db->prepare("UPDATE social_templates SET usage_count = usage_count + 1 WHERE id = ?")
           ->execute([$templateId]);
    }

    echo json_encode([
        'success' => true,
        'post_id' => $postId,
        'status'  => $status,
        'message' => $status === 'pending_approval'
            ? 'Post submitted for approval'
            : ($scheduledAt ? 'Post scheduled' : 'Post created')
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to save post: ' . $e->getMessage()
    ]);
}
