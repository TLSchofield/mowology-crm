<?php
/**
 * Campaign Analytics API
 *
 * Actions:
 *   overview      — overall analytics stats dashboard
 *   campaign      — per-campaign detailed analytics
 *   revenue       — revenue attribution summary
 *   territory     — opens/clicks/revenue by city/territory
 *   tag-compare   — performance comparison by contact tag
 *   hourly        — hour-of-day engagement breakdown
 *   device        — device type breakdown
 *   conversions   — conversion funnel (sent → opened → clicked → quoted → booked → paid)
 *   record-event  — record an attribution event (click/quote/job/invoice)
 *
 * @package Mowology\Marketing
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

header('Content-Type: application/json; charset=utf-8');
session_write_close();

requireLogin();
$user = getCurrentUser();

$action = $_GET['action'] ?? '';
$db = getDB();

try {
    switch ($action) {

        // ── Overview dashboard stats ─────────────────────────────────────
        case 'overview':
            requirePermission('marketing.view');

            $period = $_GET['period'] ?? '30'; // days
            $period = in_array($period, ['7','14','30','90','365']) ? $period : '30';

            $sent     = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE status='sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $opened   = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE opened_at IS NOT NULL AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $clicked  = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE click_count > 0 AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $revenue  = (float)$db->query("SELECT COALESCE(SUM(revenue),0) FROM campaign_revenue_log WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();

            $activeCampaigns  = (int)$db->query("SELECT COUNT(*) FROM marketing_campaigns WHERE status IN ('sending','queued')")->fetchColumn();
            $completedCampaigns = (int)$db->query("SELECT COUNT(*) FROM marketing_campaigns WHERE status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();

            $openRate   = $sent > 0 ? round(($opened / $sent) * 100, 1) : 0;
            $clickRate  = $sent > 0 ? round(($clicked / $sent) * 100, 1) : 0;
            $revenuePerRecipient = $sent > 0 ? round($revenue / $sent, 2) : 0;

            // 30-day daily sent/open trend
            $trend = $db->query("
                SELECT DATE(sent_at) AS day,
                       COUNT(*) AS sent,
                       SUM(opened_at IS NOT NULL) AS opened,
                       SUM(click_count > 0) AS clicked
                FROM campaign_sends
                WHERE status = 'sent'
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
                GROUP BY DATE(sent_at)
                ORDER BY day
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'               => true,
                'period_days'           => (int)$period,
                'sent'                  => $sent,
                'opened'                => $opened,
                'clicked'               => $clicked,
                'open_rate'             => $openRate,
                'click_rate'            => $clickRate,
                'revenue'               => $revenue,
                'revenue_per_recipient' => $revenuePerRecipient,
                'active_campaigns'      => $activeCampaigns,
                'completed_campaigns'   => $completedCampaigns,
                'trend'                 => $trend,
            ]);
            break;

        // ── Per-campaign analytics ───────────────────────────────────────
        case 'campaign':
            requirePermission('marketing.view');
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID required']); break; }

            $camp = $db->prepare("
                SELECT mc.*, et.name AS template_name
                FROM marketing_campaigns mc
                LEFT JOIN email_templates et ON et.id = mc.template_id
                WHERE mc.id = ?
            ");
            $camp->execute([$id]);
            $campaign = $camp->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) { echo json_encode(['success' => false, 'error' => 'Campaign not found']); break; }

            // Funnel stats
            $statusCounts = $db->prepare("SELECT status, COUNT(*) AS cnt FROM campaign_sends WHERE campaign_id = ? GROUP BY status");
            $statusCounts->execute([$id]);
            $funnel = $statusCounts->fetchAll(PDO::FETCH_KEY_PAIR);

            $totalSent    = (int)($funnel['sent'] ?? 0);
            $totalOpened  = (int)$db->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND opened_at IS NOT NULL")->execute([$id]) ? 0 : 0;
            $stmt = $db->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND opened_at IS NOT NULL");
            $stmt->execute([$id]);
            $totalOpened = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND click_count > 0");
            $stmt->execute([$id]);
            $totalClicked = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COALESCE(SUM(revenue),0) FROM campaign_revenue_log WHERE campaign_id=?");
            $stmt->execute([$id]);
            $totalRevenue = (float)$stmt->fetchColumn();

            // Attribution breakdown
            $attrStmt = $db->prepare("
                SELECT attribution_type, COUNT(*) AS cnt, COALESCE(SUM(revenue),0) AS revenue
                FROM campaign_attribution
                WHERE campaign_id = ?
                GROUP BY attribution_type
            ");
            $attrStmt->execute([$id]);
            $attribution = $attrStmt->fetchAll(PDO::FETCH_ASSOC);

            // Device breakdown
            $deviceStmt = $db->prepare("
                SELECT COALESCE(device_type,'unknown') AS device, COUNT(*) AS cnt
                FROM campaign_sends
                WHERE campaign_id = ? AND opened_at IS NOT NULL
                GROUP BY device_type
            ");
            $deviceStmt->execute([$id]);
            $devices = $deviceStmt->fetchAll(PDO::FETCH_ASSOC);

            // Hour-of-day opens
            $hourStmt = $db->prepare("
                SELECT HOUR(opened_at) AS hour, COUNT(*) AS cnt
                FROM campaign_sends
                WHERE campaign_id = ? AND opened_at IS NOT NULL
                GROUP BY HOUR(opened_at)
                ORDER BY hour
            ");
            $hourStmt->execute([$id]);
            $hourly = $hourStmt->fetchAll(PDO::FETCH_ASSOC);

            $openRate  = $totalSent > 0 ? round(($totalOpened  / $totalSent) * 100, 1) : 0;
            $clickRate = $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 1) : 0;
            $revPerRecip = $totalSent > 0 ? round($totalRevenue / $totalSent, 2) : 0;

            echo json_encode([
                'success'           => true,
                'campaign'          => $campaign,
                'funnel'            => $funnel,
                'total_sent'        => $totalSent,
                'total_opened'      => $totalOpened,
                'total_clicked'     => $totalClicked,
                'total_revenue'     => $totalRevenue,
                'open_rate'         => $openRate,
                'click_rate'        => $clickRate,
                'revenue_per_recip' => $revPerRecip,
                'attribution'       => $attribution,
                'devices'           => $devices,
                'hourly'            => $hourly,
            ]);
            break;

        // ── Revenue attribution summary ──────────────────────────────────
        case 'revenue':
            requirePermission('marketing.view');
            $period = (int)($_GET['period'] ?? 30);

            // Top campaigns by revenue
            $topCampaigns = $db->query("
                SELECT mc.name, mc.id,
                       COALESCE(SUM(crl.revenue),0) AS revenue,
                       COUNT(DISTINCT crl.contact_id) AS converters
                FROM marketing_campaigns mc
                LEFT JOIN campaign_revenue_log crl ON crl.campaign_id = mc.id
                  AND crl.recorded_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
                GROUP BY mc.id, mc.name
                ORDER BY revenue DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Revenue by event type
            $byType = $db->query("
                SELECT event_type, COUNT(*) AS cnt, COALESCE(SUM(revenue),0) AS revenue
                FROM campaign_revenue_log
                WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
                GROUP BY event_type
            ")->fetchAll(PDO::FETCH_ASSOC);

            $totalRevenue = (float)$db->query("
                SELECT COALESCE(SUM(revenue),0) FROM campaign_revenue_log
                WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
            ")->fetchColumn();

            echo json_encode([
                'success'       => true,
                'total_revenue' => $totalRevenue,
                'top_campaigns' => $topCampaigns,
                'by_type'       => $byType,
            ]);
            break;

        // ── Territory heatmap data ───────────────────────────────────────
        case 'territory':
            requirePermission('marketing.view');

            $rows = $db->query("
                SELECT
                    COALESCE(p.city,'Unknown') AS city,
                    COUNT(DISTINCT cs.id) AS sent,
                    SUM(cs.opened_at IS NOT NULL) AS opened,
                    SUM(cs.click_count > 0) AS clicked,
                    COALESCE(SUM(crl.revenue),0) AS revenue
                FROM campaign_sends cs
                LEFT JOIN contacts c ON c.id = cs.contact_id
                LEFT JOIN properties p ON p.site_contact_id = c.id
                LEFT JOIN campaign_revenue_log crl
                    ON crl.contact_id = cs.contact_id AND crl.campaign_id = cs.campaign_id
                WHERE cs.status = 'sent'
                GROUP BY city
                ORDER BY revenue DESC, sent DESC
                LIMIT 30
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'territory' => $rows]);
            break;

        // ── Tag performance comparison ───────────────────────────────────
        case 'tag-compare':
            requirePermission('marketing.view');

            $rows = $db->query("
                SELECT
                    ct.tag,
                    COUNT(DISTINCT cs.id) AS sent,
                    SUM(cs.opened_at IS NOT NULL) AS opened,
                    SUM(cs.click_count > 0) AS clicked,
                    COALESCE(SUM(crl.revenue),0) AS revenue
                FROM contact_tags ct
                LEFT JOIN campaign_sends cs ON cs.contact_id = ct.contact_id AND cs.status = 'sent'
                LEFT JOIN campaign_revenue_log crl ON crl.contact_id = ct.contact_id
                GROUP BY ct.tag
                HAVING sent > 0
                ORDER BY revenue DESC
                LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'tags' => $rows]);
            break;

        // ── Hour-of-day engagement (all campaigns) ───────────────────────
        case 'hourly':
            requirePermission('marketing.view');

            $rows = $db->query("
                SELECT HOUR(opened_at) AS hour,
                       COUNT(*) AS opens,
                       SUM(click_count > 0) AS clicks
                FROM campaign_sends
                WHERE opened_at IS NOT NULL
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY HOUR(opened_at)
                ORDER BY hour
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'hourly' => $rows]);
            break;

        // ── Device breakdown ─────────────────────────────────────────────
        case 'device':
            requirePermission('marketing.view');

            $rows = $db->query("
                SELECT COALESCE(device_type,'unknown') AS device, COUNT(*) AS cnt
                FROM campaign_sends
                WHERE opened_at IS NOT NULL
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY device_type
                ORDER BY cnt DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'devices' => $rows]);
            break;

        // ── Conversion funnel ────────────────────────────────────────────
        case 'conversions':
            requirePermission('marketing.view');
            $period = (int)($_GET['period'] ?? 30);

            $sent    = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE status='sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $opened  = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE status='sent' AND opened_at IS NOT NULL AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $clicked = (int)$db->query("SELECT COUNT(*) FROM campaign_sends WHERE click_count>0 AND sent_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();

            $quoted  = (int)$db->query("SELECT COUNT(*) FROM campaign_attribution WHERE attribution_type='quote_created' AND attributed_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $booked  = (int)$db->query("SELECT COUNT(*) FROM campaign_attribution WHERE attribution_type='job_booked' AND attributed_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $paid    = (int)$db->query("SELECT COUNT(*) FROM campaign_attribution WHERE attribution_type='invoice_paid' AND attributed_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();
            $revenue = (float)$db->query("SELECT COALESCE(SUM(revenue),0) FROM campaign_revenue_log WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $period DAY)")->fetchColumn();

            echo json_encode([
                'success' => true,
                'funnel' => [
                    ['stage' => 'Sent',    'count' => $sent,   'rate' => 100],
                    ['stage' => 'Opened',  'count' => $opened, 'rate' => $sent  > 0 ? round(($opened  / $sent)  * 100, 1) : 0],
                    ['stage' => 'Clicked', 'count' => $clicked,'rate' => $sent  > 0 ? round(($clicked / $sent)  * 100, 1) : 0],
                    ['stage' => 'Quoted',  'count' => $quoted, 'rate' => $opened > 0 ? round(($quoted  / $opened)* 100, 1) : 0],
                    ['stage' => 'Booked',  'count' => $booked, 'rate' => $quoted > 0 ? round(($booked  / $quoted)* 100, 1) : 0],
                    ['stage' => 'Paid',    'count' => $paid,   'rate' => $booked > 0 ? round(($paid    / $booked)* 100, 1) : 0],
                ],
                'revenue' => $revenue,
            ]);
            break;

        // ── Record attribution event ─────────────────────────────────────
        case 'record-event':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');

            $campaignId = (int)($input['campaign_id'] ?? 0);
            $contactId  = (int)($input['contact_id'] ?? 0);
            $eventType  = $input['attribution_type'] ?? '';
            $revenue    = (float)($input['revenue'] ?? 0);
            $entityType = $input['entity_type'] ?? '';
            $entityId   = (int)($input['entity_id'] ?? 0);

            $allowed = ['click', 'quote_created', 'job_booked', 'invoice_paid'];
            if (!in_array($eventType, $allowed)) {
                echo json_encode(['success' => false, 'error' => 'Invalid event type']); break;
            }

            $db->prepare("
                INSERT INTO campaign_attribution (campaign_id, contact_id, attribution_type, entity_type, entity_id, revenue, attributed_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$campaignId, $contactId ?: null, $eventType, $entityType ?: null, $entityId ?: null, $revenue]);

            if ($revenue > 0) {
                $db->prepare("
                    INSERT INTO campaign_revenue_log (campaign_id, contact_id, event_type, revenue, entity_type, entity_id, recorded_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$campaignId, $contactId ?: null, $eventType, $revenue, $entityType ?: null, $entityId ?: null]);
            }

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Throwable $e) {
    error_log('analytics API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
