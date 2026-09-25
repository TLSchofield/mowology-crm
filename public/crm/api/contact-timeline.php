<?php
/**
 * Contact Timeline API
 *
 * Aggregates events from activity_log, quotes, job_visits, invoices,
 * tasks, field_changes into a unified chronological timeline.
 *
 * GET ?contact_id=5&type=all&page=1&per_page=20
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
session_write_close();

$contactId  = (int)($_GET['contact_id'] ?? 0);
$typeFilter = $_GET['type'] ?? 'all';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = min((int)($_GET['per_page'] ?? 20), 50);
$offset     = ($page - 1) * $perPage;

if (!$contactId) {
    http_response_code(400);
    echo json_encode(['error' => 'contact_id required']);
    exit;
}

$db = getDB();

// Get property IDs, quote IDs, plan IDs, invoice IDs for this contact
$propStmt = $db->prepare("SELECT id FROM properties WHERE site_contact_id = ?");
$propStmt->execute([$contactId]);
$propIds = array_column($propStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

$quoteIds   = [];
$planIds    = [];
$invoiceIds = [];

if (!empty($propIds)) {
    $ph = implode(',', array_fill(0, count($propIds), '?'));

    $qs = $db->prepare("SELECT id FROM quotes WHERE property_id IN ({$ph})");
    $qs->execute($propIds);
    $quoteIds = array_column($qs->fetchAll(PDO::FETCH_ASSOC), 'id');

    $ps = $db->prepare("SELECT id FROM job_plans WHERE property_id IN ({$ph})");
    $ps->execute($propIds);
    $planIds = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'id');

    $is = $db->prepare("SELECT id FROM invoices WHERE property_id IN ({$ph})");
    $is->execute($propIds);
    $invoiceIds = array_column($is->fetchAll(PDO::FETCH_ASSOC), 'id');
}

// Build UNION ALL queries for each event type
$unions = [];
$allParams = [];

// Helper to build IN clause safely
function inClause(array $ids): string {
    return $ids ? implode(',', array_map('intval', $ids)) : '0';
}

// 1. Activity log
if ($typeFilter === 'all' || $typeFilter === 'activity') {
    $actWhere = ["al.contact_id = ?"];
    $actParams = [$contactId];
    if ($propIds) {
        $actWhere[] = "al.property_id IN (" . inClause($propIds) . ")";
    }
    if ($quoteIds) {
        $actWhere[] = "al.quote_id IN (" . inClause($quoteIds) . ")";
    }
    if ($planIds) {
        $actWhere[] = "al.plan_id IN (" . inClause($planIds) . ")";
    }
    if ($invoiceIds) {
        $actWhere[] = "al.invoice_id IN (" . inClause($invoiceIds) . ")";
    }
    $unions[] = "(SELECT 'activity' AS event_type, al.action AS title, al.details AS detail,
                  al.created_at AS event_time, u.full_name AS user_name, NULL AS entity_id, NULL AS entity_number
                  FROM activity_log al LEFT JOIN users u ON al.user_id = u.id
                  WHERE " . implode(' OR ', $actWhere) . ")";
    $allParams = array_merge($allParams, $actParams);
}

// 2. Quote events
if (($typeFilter === 'all' || $typeFilter === 'quotes') && $quoteIds) {
    $qIn = inClause($quoteIds);
    $unions[] = "(SELECT 'quote_created' AS event_type, CONCAT('Quote ', q.quote_number, ' created') AS title,
                  CONCAT(q.service_type, ' - ', FORMAT(q.total_amount, 2)) AS detail,
                  q.created_at AS event_time, u.full_name AS user_name, q.id AS entity_id, q.quote_number AS entity_number
                  FROM quotes q LEFT JOIN users u ON q.created_by = u.id WHERE q.id IN ({$qIn}))";

    $unions[] = "(SELECT 'quote_sent' AS event_type, CONCAT('Quote ', q.quote_number, ' sent') AS title,
                  NULL AS detail, q.sent_at AS event_time, NULL AS user_name, q.id AS entity_id, q.quote_number AS entity_number
                  FROM quotes q WHERE q.id IN ({$qIn}) AND q.sent_at IS NOT NULL)";

    $unions[] = "(SELECT 'quote_accepted' AS event_type, CONCAT('Quote ', q.quote_number, ' accepted') AS title,
                  q.accepted_by_name AS detail, q.accepted_at AS event_time, NULL AS user_name, q.id AS entity_id, q.quote_number AS entity_number
                  FROM quotes q WHERE q.id IN ({$qIn}) AND q.accepted_at IS NOT NULL)";
}

// 3. Visit events
if (($typeFilter === 'all' || $typeFilter === 'jobs') && $planIds) {
    $pIn = inClause($planIds);
    $unions[] = "(SELECT 'visit_completed' AS event_type,
                  CONCAT('Visit ', jv.visit_number, ' completed') AS title,
                  CONCAT(jp.service_type, ' at ', COALESCE(p.address, '')) AS detail,
                  jv.completed_at AS event_time, NULL AS user_name, jv.id AS entity_id, jv.visit_number AS entity_number
                  FROM job_visits jv
                  JOIN job_plans jp ON jv.plan_id = jp.id
                  LEFT JOIN properties p ON jp.property_id = p.id
                  WHERE jv.plan_id IN ({$pIn}) AND jv.status = 'completed' AND jv.completed_at IS NOT NULL)";
}

// 4. Invoice events
if (($typeFilter === 'all' || $typeFilter === 'invoices') && $invoiceIds) {
    $iIn = inClause($invoiceIds);
    $unions[] = "(SELECT 'invoice_created' AS event_type, CONCAT('Invoice ', i.invoice_number, ' created') AS title,
                  CONCAT(FORMAT(i.total, 2)) AS detail, i.created_at AS event_time, NULL AS user_name, i.id AS entity_id, i.invoice_number AS entity_number
                  FROM invoices i WHERE i.id IN ({$iIn}))";

    $unions[] = "(SELECT 'invoice_paid' AS event_type, CONCAT('Invoice ', i.invoice_number, ' paid') AS title,
                  CONCAT(FORMAT(i.amount_paid, 2), ' received') AS detail, i.paid_at AS event_time, NULL AS user_name, i.id AS entity_id, i.invoice_number AS entity_number
                  FROM invoices i WHERE i.id IN ({$iIn}) AND i.paid_at IS NOT NULL)";
}

// 5. Tasks
if ($typeFilter === 'all' || $typeFilter === 'tasks') {
    $unions[] = "(SELECT CASE WHEN t.status = 'completed' THEN 'task_completed' ELSE 'task_created' END AS event_type,
                  t.title AS title, t.description AS detail,
                  CASE WHEN t.status = 'completed' THEN t.completed_at ELSE t.created_at END AS event_time,
                  CASE WHEN t.status = 'completed' THEN uc.full_name ELSE ucr.full_name END AS user_name,
                  t.id AS entity_id, NULL AS entity_number
                  FROM tasks t
                  LEFT JOIN users uc ON t.completed_by = uc.id
                  LEFT JOIN users ucr ON t.created_by = ucr.id
                  WHERE t.contact_id = ?)";
    $allParams[] = $contactId;
}

// 6. Field changes
if ($typeFilter === 'all' || $typeFilter === 'changes') {
    $unions[] = "(SELECT 'field_changed' AS event_type,
                  CONCAT(UPPER(LEFT(fc.field_name,1)), SUBSTRING(fc.field_name,2), ' changed') AS title,
                  CONCAT(COALESCE(fc.old_value,'(empty)'), ' → ', COALESCE(fc.new_value,'(empty)')) AS detail,
                  fc.changed_at AS event_time, u.full_name AS user_name, fc.entity_id AS entity_id, NULL AS entity_number
                  FROM field_changes fc LEFT JOIN users u ON fc.changed_by = u.id
                  WHERE fc.entity_type = 'contact' AND fc.entity_id = ?)";
    $allParams[] = $contactId;
}

// 7. Consent log events (CASL audit trail)
if ($typeFilter === 'all' || $typeFilter === 'consent') {
    $channelLabels = "CASE cl.consent_type WHEN 'marketing_email' THEN 'Email marketing' WHEN 'sms' THEN 'SMS' WHEN 'quote_followup' THEN 'Quote follow-up' ELSE cl.consent_type END";
    $sourceLabels  = "CASE cl.consent_source WHEN 'admin_manual' THEN 'CRM admin' WHEN 'website_form' THEN 'website form' WHEN 'unsubscribe_link' THEN 'unsubscribe link' ELSE COALESCE(cl.consent_source,'unknown') END";
    $unions[] = "(SELECT 'consent_changed' AS event_type,
                  CONCAT($channelLabels, ' consent ', IF(cl.consent_given=1,'given','revoked')) AS title,
                  CONCAT('Via ', $sourceLabels, IF(u.full_name IS NOT NULL, CONCAT(' by ', u.full_name), '')) AS detail,
                  cl.created_at AS event_time,
                  u.full_name AS user_name,
                  cl.id AS entity_id, NULL AS entity_number
                  FROM consent_log cl
                  LEFT JOIN users u ON u.id = cl.updated_by_user_id
                  WHERE cl.contact_id = ?)";
    $allParams[] = $contactId;
}

if (empty($unions)) {
    echo json_encode(['success' => true, 'events' => [], 'total' => 0, 'page' => $page]);
    exit;
}

try {
    $sql = implode(" UNION ALL ", $unions) . " ORDER BY event_time DESC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($allParams);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'events' => $events, 'page' => $page]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load timeline']);
    error_log("contact-timeline error: " . $e->getMessage());
}
