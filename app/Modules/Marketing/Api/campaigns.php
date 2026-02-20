<?php
/**
 * Marketing Campaigns API
 *
 * Actions:
 *   list           — paginated campaign list with stats
 *   get            — single campaign detail
 *   save           — create or update campaign
 *   delete         — soft-delete (cancel) campaign
 *   get-recipients — preview recipient list for a segment
 *   queue          — populate campaign_sends and mark as queued
 *   send-now       — queue + immediately start sending
 *   pause          — pause a sending campaign
 *   resume         — resume a paused campaign
 *   templates      — list available email templates
 *   save-template  — create or update email template
 *   stats          — campaign dashboard stats
 *
 * @package Mowology\Marketing
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
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
require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = getCurrentUser();

$action = $_GET['action'] ?? '';
$db = getDB();

try {
    switch ($action) {

        // ── List campaigns ───────────────────────────────────────────
        case 'list':
            requirePermission('marketing.view');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? '';

            $where = '1=1';
            $params = [];
            if ($status) {
                $where .= ' AND mc.status = ?';
                $params[] = $status;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM marketing_campaigns mc WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT mc.*,
                       et.name AS template_name,
                       p.name AS product_name,
                       u.full_name AS created_by_name
                FROM marketing_campaigns mc
                LEFT JOIN email_templates et ON et.id = mc.template_id
                LEFT JOIN products p ON p.id = mc.product_id
                LEFT JOIN users u ON u.id = mc.created_by
                WHERE $where
                ORDER BY mc.created_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get status counts
            $counts = $db->query("
                SELECT status, COUNT(*) AS cnt
                FROM marketing_campaigns
                GROUP BY status
            ")->fetchAll(PDO::FETCH_KEY_PAIR);

            echo json_encode([
                'success'   => true,
                'campaigns' => $campaigns,
                'total'     => $total,
                'page'      => $page,
                'pages'     => max(1, (int)ceil($total / $perPage)),
                'counts'    => $counts,
            ]);
            break;

        // ── Get single campaign ──────────────────────────────────────
        case 'get':
            requirePermission('marketing.view');
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID required']); break; }

            $stmt = $db->prepare("
                SELECT mc.*,
                       et.name AS template_name, et.subject AS template_subject,
                       et.body_html AS template_body, et.merge_fields AS template_merge_fields,
                       p.name AS product_name
                FROM marketing_campaigns mc
                LEFT JOIN email_templates et ON et.id = mc.template_id
                LEFT JOIN products p ON p.id = mc.product_id
                WHERE mc.id = ?
            ");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                echo json_encode(['success' => false, 'error' => 'Campaign not found']);
                break;
            }

            // Get send stats
            $sendStats = $db->prepare("
                SELECT status, COUNT(*) AS cnt
                FROM campaign_sends
                WHERE campaign_id = ?
                GROUP BY status
            ");
            $sendStats->execute([$id]);
            $campaign['send_stats'] = $sendStats->fetchAll(PDO::FETCH_KEY_PAIR);

            // Get recent sends
            $recentSends = $db->prepare("
                SELECT cs.*, c.first_name, c.last_name
                FROM campaign_sends cs
                LEFT JOIN contacts c ON c.id = cs.contact_id
                WHERE cs.campaign_id = ?
                ORDER BY cs.sent_at DESC
                LIMIT 50
            ");
            $recentSends->execute([$id]);
            $campaign['recent_sends'] = $recentSends->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'campaign' => $campaign]);
            break;

        // ── Save (create/update) campaign ────────────────────────────
        case 'save':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
                break;
            }

            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Campaign name required']);
                break;
            }

            $fields = [
                'name'             => $name,
                'template_id'     => !empty($input['template_id']) ? (int)$input['template_id'] : null,
                'product_id'      => !empty($input['product_id']) ? (int)$input['product_id'] : null,
                'segment_type'    => $input['segment_type'] ?? 'all_marketing',
                'segment_rules'   => !empty($input['segment_rules']) ? json_encode($input['segment_rules']) : null,
                'trigger_type'    => $input['trigger_type'] ?? 'manual',
                'auto_send'       => (int)($input['auto_send'] ?? 0),
                'schedule_date'   => !empty($input['schedule_date']) ? $input['schedule_date'] : null,
                'subject_override' => !empty($input['subject_override']) ? trim($input['subject_override']) : null,
                'body_override'   => !empty($input['body_override']) ? trim($input['body_override']) : null,
            ];

            if ($id) {
                // Update — only if draft or scheduled
                $existing = $db->prepare("SELECT status FROM marketing_campaigns WHERE id = ?");
                $existing->execute([$id]);
                $existingStatus = $existing->fetchColumn();
                if (!in_array($existingStatus, ['draft', 'scheduled'])) {
                    echo json_encode(['success' => false, 'error' => 'Cannot edit campaign in status: ' . $existingStatus]);
                    break;
                }

                $setClauses = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setClauses[] = "`$col` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                $db->prepare("UPDATE marketing_campaigns SET " . implode(', ', $setClauses) . " WHERE id = ?")->execute($params);
            } else {
                // Insert
                $fields['status'] = 'draft';
                $fields['created_by'] = $user['id'];
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                $db->prepare(
                    "INSERT INTO marketing_campaigns (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")"
                )->execute(array_values($fields));
                $id = (int)$db->lastInsertId();
            }

            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ── Delete (cancel) campaign ─────────────────────────────────
        case 'delete':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID required']); break; }

            $db->prepare("UPDATE marketing_campaigns SET status = 'cancelled' WHERE id = ? AND status IN ('draft','scheduled','paused')")
               ->execute([$id]);

            echo json_encode(['success' => true]);
            break;

        // ── Preview recipients for a segment ─────────────────────────
        case 'get-recipients':
            requirePermission('marketing.view');
            $segmentType = $_GET['segment_type'] ?? 'all_marketing';
            $productId = (int)($_GET['product_id'] ?? 0);

            $recipients = getSegmentRecipients($db, $segmentType, $productId);

            echo json_encode([
                'success'    => true,
                'count'      => count($recipients),
                'recipients' => array_slice($recipients, 0, 50), // Preview max 50
            ]);
            break;

        // ── Queue campaign for sending ───────────────────────────────
        case 'queue':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);

            $campaign = $db->prepare("SELECT * FROM marketing_campaigns WHERE id = ?")->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) {
                $stmt = $db->prepare("SELECT * FROM marketing_campaigns WHERE id = ?");
                $stmt->execute([$id]);
                $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$campaign) {
                echo json_encode(['success' => false, 'error' => 'Campaign not found']);
                break;
            }
            if (!in_array($campaign['status'], ['draft', 'scheduled'])) {
                echo json_encode(['success' => false, 'error' => 'Campaign already queued or sent']);
                break;
            }

            // Build recipient list
            $recipients = getSegmentRecipients($db, $campaign['segment_type'], (int)($campaign['product_id'] ?? 0));

            if (empty($recipients)) {
                echo json_encode(['success' => false, 'error' => 'No recipients match this segment']);
                break;
            }

            // Clear any existing sends (in case of re-queue)
            $db->prepare("DELETE FROM campaign_sends WHERE campaign_id = ? AND status = 'pending'")->execute([$id]);

            // Insert sends
            $insertStmt = $db->prepare("
                INSERT INTO campaign_sends (campaign_id, contact_id, email, status, created_at)
                VALUES (?, ?, ?, 'pending', NOW())
            ");
            $queued = 0;
            foreach ($recipients as $r) {
                $insertStmt->execute([$id, $r['id'], $r['email']]);
                $queued++;
            }

            // Update campaign status
            $db->prepare("
                UPDATE marketing_campaigns
                SET status = 'queued', recipient_count = ?
                WHERE id = ?
            ")->execute([$queued, $id]);

            echo json_encode(['success' => true, 'queued' => $queued]);
            break;

        // ── Send now (queue + mark as sending) ───────────────────────
        case 'send-now':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);

            // First queue it
            $stmt = $db->prepare("SELECT * FROM marketing_campaigns WHERE id = ?");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) {
                echo json_encode(['success' => false, 'error' => 'Campaign not found']);
                break;
            }

            if ($campaign['status'] === 'draft' || $campaign['status'] === 'scheduled') {
                // Build and queue recipients
                $recipients = getSegmentRecipients($db, $campaign['segment_type'], (int)($campaign['product_id'] ?? 0));
                if (empty($recipients)) {
                    echo json_encode(['success' => false, 'error' => 'No recipients match this segment']);
                    break;
                }

                $db->prepare("DELETE FROM campaign_sends WHERE campaign_id = ? AND status = 'pending'")->execute([$id]);

                $insertStmt = $db->prepare("
                    INSERT INTO campaign_sends (campaign_id, contact_id, email, status, created_at)
                    VALUES (?, ?, ?, 'pending', NOW())
                ");
                foreach ($recipients as $r) {
                    $insertStmt->execute([$id, $r['id'], $r['email']]);
                }

                $db->prepare("
                    UPDATE marketing_campaigns
                    SET status = 'sending', recipient_count = ?
                    WHERE id = ?
                ")->execute([count($recipients), $id]);
            } elseif ($campaign['status'] === 'queued' || $campaign['status'] === 'paused') {
                $db->prepare("UPDATE marketing_campaigns SET status = 'sending' WHERE id = ?")->execute([$id]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Cannot send campaign in status: ' . $campaign['status']]);
                break;
            }

            echo json_encode(['success' => true, 'message' => 'Campaign is now sending. Emails will be processed by the campaign sender cron.']);
            break;

        // ── Pause campaign ───────────────────────────────────────────
        case 'pause':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);
            $db->prepare("UPDATE marketing_campaigns SET status = 'paused' WHERE id = ? AND status = 'sending'")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        // ── Resume campaign ──────────────────────────────────────────
        case 'resume':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);
            $db->prepare("UPDATE marketing_campaigns SET status = 'sending' WHERE id = ? AND status = 'paused'")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        // ── List email templates ─────────────────────────────────────
        case 'templates':
            requirePermission('marketing.view');
            $category = $_GET['category'] ?? '';
            $where = 'is_active = 1';
            $params = [];
            if ($category) {
                $where .= ' AND category = ?';
                $params[] = $category;
            }
            $stmt = $db->prepare("SELECT * FROM email_templates WHERE $where ORDER BY category, name");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── Save email template ──────────────────────────────────────
        case 'save-template':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $bodyHtml = trim($input['body_html'] ?? '');

            if (empty($name) || empty($subject) || empty($bodyHtml)) {
                echo json_encode(['success' => false, 'error' => 'Name, subject, and body are required']);
                break;
            }

            $fields = [
                'name'         => $name,
                'category'     => $input['category'] ?? 'custom',
                'subject'      => $subject,
                'body_html'    => $bodyHtml,
                'merge_fields' => $input['merge_fields'] ?? '',
                'is_active'    => (int)($input['is_active'] ?? 1),
            ];

            if ($id) {
                $setClauses = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setClauses[] = "`$col` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                $db->prepare("UPDATE email_templates SET " . implode(', ', $setClauses) . " WHERE id = ?")->execute($params);
            } else {
                $fields['created_by'] = $user['id'];
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                $db->prepare(
                    "INSERT INTO email_templates (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")"
                )->execute(array_values($fields));
                $id = (int)$db->lastInsertId();
            }

            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ── Preview rendered email ───────────────────────────────────
        case 'preview':
            requirePermission('marketing.view');
            $campaignId = (int)($_GET['campaign_id'] ?? 0);
            $templateId = (int)($_GET['template_id'] ?? 0);

            // Get template content
            $subject = '';
            $bodyHtml = '';
            if ($campaignId) {
                $stmt = $db->prepare("
                    SELECT mc.subject_override, mc.body_override,
                           et.subject AS template_subject, et.body_html AS template_body,
                           mc.product_id
                    FROM marketing_campaigns mc
                    LEFT JOIN email_templates et ON et.id = mc.template_id
                    WHERE mc.id = ?
                ");
                $stmt->execute([$campaignId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) { echo json_encode(['success' => false, 'error' => 'Campaign not found']); break; }
                $subject = $row['subject_override'] ?: ($row['template_subject'] ?? '');
                $bodyHtml = $row['body_override'] ?: ($row['template_body'] ?? '');
                $templateId = 0; // Already have it
            } elseif ($templateId) {
                $stmt = $db->prepare("SELECT subject, body_html FROM email_templates WHERE id = ?");
                $stmt->execute([$templateId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) { echo json_encode(['success' => false, 'error' => 'Template not found']); break; }
                $subject = $row['subject'];
                $bodyHtml = $row['body_html'];
            }

            // Get a sample contact for preview
            $sampleContact = $db->query("
                SELECT c.*, p.address, p.city
                FROM contacts c
                LEFT JOIN properties p ON p.site_contact_id = c.id
                WHERE c.is_active = 1 AND c.email IS NOT NULL AND c.email != ''
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if (!$sampleContact) {
                $sampleContact = [
                    'first_name' => 'John', 'last_name' => 'Doe',
                    'email' => 'john@example.com',
                    'address' => '123 Main St', 'city' => 'Vancouver',
                ];
            }

            $property = ['address' => $sampleContact['address'] ?? '', 'city' => $sampleContact['city'] ?? ''];
            $mergeData = buildMergeData($sampleContact, $property);
            $renderedSubject = renderTemplate($subject, $mergeData);
            $renderedBody = renderTemplate($bodyHtml, $mergeData);
            $fullEmail = wrapInBrandedEmail($renderedBody, generateUnsubscribeUrl($sampleContact['email'] ?? 'test@example.com'));

            echo json_encode([
                'success' => true,
                'subject' => $renderedSubject,
                'body'    => $fullEmail,
                'contact' => [
                    'name'  => ($sampleContact['first_name'] ?? '') . ' ' . ($sampleContact['last_name'] ?? ''),
                    'email' => $sampleContact['email'] ?? '',
                ],
            ]);
            break;

        // ── Dashboard stats ──────────────────────────────────────────
        case 'stats':
            requirePermission('marketing.view');

            $campaignCount = (int)$db->query("SELECT COUNT(*) FROM marketing_campaigns")->fetchColumn();
            $sentThisMonth = (int)$db->query("
                SELECT COUNT(*) FROM campaign_sends
                WHERE status = 'sent' AND sent_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            ")->fetchColumn();
            $totalSent = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE status = 'sent'")->fetchColumn();
            $totalOpened = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE opened_at IS NOT NULL")->fetchColumn();
            $totalClicked = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE clicked_at IS NOT NULL")->fetchColumn();
            $openRate = $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 1) : 0;

            echo json_encode([
                'success'        => true,
                'campaigns'      => $campaignCount,
                'sent_this_month' => $sentThisMonth,
                'total_sent'     => $totalSent,
                'total_opened'   => $totalOpened,
                'total_clicked'  => $totalClicked,
                'open_rate'      => $openRate,
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (Throwable $e) {
    error_log('campaigns API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


// ── Segment Query Builder ────────────────────────────────────────────────

/**
 * Get recipients matching a segment definition.
 *
 * @param PDO    $db
 * @param string $segmentType
 * @param int    $productId  For 'never_purchased_product' segment
 * @return array  Array of [id, first_name, last_name, email, address, city]
 */
function getSegmentRecipients(PDO $db, string $segmentType, int $productId = 0): array
{
    $baseSelect = "
        SELECT DISTINCT c.id, c.first_name, c.last_name, c.email,
               p.address, p.city
        FROM contacts c
        LEFT JOIN properties p ON p.site_contact_id = c.id
    ";
    $baseWhere = "
        WHERE c.is_active = 1
          AND c.receive_marketing = 1
          AND c.email IS NOT NULL
          AND c.email != ''
          AND c.email NOT IN (SELECT email COLLATE utf8mb4_0900_ai_ci FROM marketing_unsubscribes)
    ";

    switch ($segmentType) {
        case 'all_marketing':
            $stmt = $db->query($baseSelect . $baseWhere . " ORDER BY c.last_name");
            break;

        case 'never_purchased_product':
            if (!$productId) return [];
            $stmt = $db->prepare($baseSelect . $baseWhere . "
                AND c.id NOT IN (
                    SELECT contact_id FROM contact_product_history WHERE product_id = ?
                )
                ORDER BY c.last_name
            ");
            $stmt->execute([$productId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'inactive_3mo':
            $stmt = $db->query($baseSelect . $baseWhere . "
                AND (c.last_service_date IS NULL OR c.last_service_date < DATE_SUB(NOW(), INTERVAL 3 MONTH))
                ORDER BY c.last_name
            ");
            break;

        case 'inactive_6mo':
            $stmt = $db->query($baseSelect . $baseWhere . "
                AND (c.last_service_date IS NULL OR c.last_service_date < DATE_SUB(NOW(), INTERVAL 6 MONTH))
                ORDER BY c.last_name
            ");
            break;

        default:
            // Fallback to all marketing contacts
            $stmt = $db->query($baseSelect . $baseWhere . " ORDER BY c.last_name");
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
