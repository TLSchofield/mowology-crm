<?php
/**
 * Campaign Opportunities API
 *
 * Returns JSON with five opportunity panels:
 *   uncampaigned_visits  — completed visits in last 14 days with no automation trigger
 *   unposted_photos      — before/after photos uploaded last 7 days with no social post
 *   unprompted_referrals — invoices paid last 30 days with no referral link created after
 *   inactive_contacts    — marketable contacts inactive 90+ days with no recent send
 *   stale_leads          — quote requests stuck in new/reviewing for 7+ days
 *
 * GET /crm/api/campaign-opportunities.php[?panel=<name>]
 *   panel — optional, returns only that section
 *
 * Returns: { panels: { <name>: { count: int, rows: array } } }
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requirePermission('marketing.view');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$db = getDB();
$requestedPanel = trim($_GET['panel'] ?? '');

$panels = [];

// ── 1. Uncampaigned visit completions ─────────────────────────────────────
if (!$requestedPanel || $requestedPanel === 'uncampaigned_visits') {
    try {
        $stmt = $db->query("
            SELECT
                jv.id            AS visit_id,
                jv.completed_at,
                jp.contact_id,
                COALESCE(c.first_name, '') AS first_name,
                COALESCE(c.last_name,  '') AS last_name,
                COALESCE(c.email, '')      AS email,
                COALESCE(p.address, '')    AS address,
                COALESCE(p.city, '')       AS city,
                jp.service_type
            FROM job_visits jv
            INNER JOIN job_plans jp ON jp.id = jv.plan_id
            INNER JOIN contacts  c  ON c.id  = jp.contact_id
            LEFT  JOIN properties p ON p.id  = jp.property_id
            WHERE jv.status      = 'completed'
              AND jv.completed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
              AND jp.contact_id NOT IN (
                    SELECT contact_id
                    FROM automation_logs
                    WHERE trigger_type IN ('job_completed', 'invoice_paid', 'end_visit')
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
              )
            ORDER BY jv.completed_at DESC
            LIMIT 20
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panels['uncampaigned_visits'] = ['count' => count($rows), 'rows' => $rows];
    } catch (Throwable $e) {
        $panels['uncampaigned_visits'] = ['count' => 0, 'rows' => [], 'error' => 'Query failed'];
        error_log('[campaign-opportunities] uncampaigned_visits: ' . $e->getMessage());
    }
}

// ── 2. Unposted before/after photos ───────────────────────────────────────
if (!$requestedPanel || $requestedPanel === 'unposted_photos') {
    try {
        $stmt = $db->query("
            SELECT
                vp.id            AS photo_id,
                vp.visit_id,
                vp.photo_type,
                vp.filename,
                vp.created_at    AS uploaded_at,
                jp.contact_id,
                COALESCE(c.first_name, '') AS first_name,
                COALESCE(c.last_name,  '') AS last_name,
                COALESCE(p.city, '')       AS city,
                jp.service_type
            FROM visit_photos vp
            INNER JOIN job_visits jv ON jv.id = vp.visit_id
            INNER JOIN job_plans  jp ON jp.id = jv.plan_id
            INNER JOIN contacts   c  ON c.id  = jp.contact_id
            LEFT  JOIN properties p  ON p.id  = jp.property_id
            WHERE vp.photo_type IN ('before', 'after', 'during')
              AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND vp.visit_id NOT IN (
                    SELECT visit_id FROM social_posts
                    WHERE visit_id IS NOT NULL
              )
            ORDER BY vp.created_at DESC
            LIMIT 20
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panels['unposted_photos'] = ['count' => count($rows), 'rows' => $rows];
    } catch (Throwable $e) {
        $panels['unposted_photos'] = ['count' => 0, 'rows' => [], 'error' => 'Query failed'];
        error_log('[campaign-opportunities] unposted_photos: ' . $e->getMessage());
    }
}

// ── 3. Paid invoices with no referral ask ─────────────────────────────────
if (!$requestedPanel || $requestedPanel === 'unprompted_referrals') {
    try {
        $stmt = $db->query("
            SELECT
                i.id             AS invoice_id,
                i.invoice_number,
                i.total,
                i.paid_at,
                i.contact_id,
                COALESCE(c.first_name, '') AS first_name,
                COALESCE(c.last_name,  '') AS last_name,
                COALESCE(c.email, '')      AS email
            FROM invoices i
            INNER JOIN contacts c ON c.id = i.contact_id
            WHERE i.status  = 'paid'
              AND i.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND i.contact_id NOT IN (
                    SELECT contact_id FROM referral_links
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              )
            ORDER BY i.paid_at DESC
            LIMIT 20
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panels['unprompted_referrals'] = ['count' => count($rows), 'rows' => $rows];
    } catch (Throwable $e) {
        $panels['unprompted_referrals'] = ['count' => 0, 'rows' => [], 'error' => 'Query failed'];
        error_log('[campaign-opportunities] unprompted_referrals: ' . $e->getMessage());
    }
}

// ── 4. Inactive marketable contacts ───────────────────────────────────────
if (!$requestedPanel || $requestedPanel === 'inactive_contacts') {
    try {
        $stmt = $db->query("
            SELECT
                c.id,
                c.first_name,
                c.last_name,
                c.email,
                c.last_service_date,
                DATEDIFF(NOW(), c.last_service_date) AS days_inactive
            FROM contacts c
            WHERE c.receive_marketing = 1
              AND c.is_active         = 1
              AND c.email IS NOT NULL AND c.email != ''
              AND (
                    c.last_service_date IS NULL
                    OR c.last_service_date < DATE_SUB(NOW(), INTERVAL 90 DAY)
              )
              AND c.id NOT IN (
                    SELECT contact_id FROM campaign_sends
                    WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
              )
            ORDER BY c.last_service_date ASC
            LIMIT 30
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panels['inactive_contacts'] = ['count' => count($rows), 'rows' => $rows];
    } catch (Throwable $e) {
        $panels['inactive_contacts'] = ['count' => 0, 'rows' => [], 'error' => 'Query failed'];
        error_log('[campaign-opportunities] inactive_contacts: ' . $e->getMessage());
    }
}

// ── 5. Stale quote requests ────────────────────────────────────────────────
if (!$requestedPanel || $requestedPanel === 'stale_leads') {
    try {
        $stmt = $db->query("
            SELECT
                qr.id,
                qr.created_at,
                qr.service_types,
                qr.status,
                qr.urgency,
                DATEDIFF(NOW(), qr.created_at) AS days_old,
                COALESCE(c.first_name, '') AS first_name,
                COALESCE(c.last_name,  '') AS last_name,
                COALESCE(c.email, '')      AS email
            FROM quote_requests qr
            LEFT JOIN contacts c ON c.id = qr.contact_id
            WHERE qr.status IN ('new', 'reviewing')
              AND qr.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY qr.created_at ASC
            LIMIT 20
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panels['stale_leads'] = ['count' => count($rows), 'rows' => $rows];
    } catch (Throwable $e) {
        $panels['stale_leads'] = ['count' => 0, 'rows' => [], 'error' => 'Query failed'];
        error_log('[campaign-opportunities] stale_leads: ' . $e->getMessage());
    }
}

echo json_encode([
    'success'    => true,
    'panels'     => $panels,
    'generated'  => date('Y-m-d H:i:s'),
]);
