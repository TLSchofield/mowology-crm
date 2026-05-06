<?php
/**
 * Global Search API — Spotlight/Command Palette
 *
 * GET ?q=<term>  (min 2 chars)
 * Returns results grouped by entity type for the global search bar.
 *
 * Single UNION ALL query = one MySQL round-trip instead of 6 sequential queries.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
session_write_close();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$db   = getDB();
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$like = '%' . $q . '%';

// Single UNION ALL — one round-trip for all entity types.
// ORDER BY inside each parenthesised SELECT is preserved when paired with LIMIT (MySQL 5.7+).
$sql = "
    (SELECT 'Contacts'   AS category, 'user'        AS icon,
            CONCAT(ct.first_name, ' ', ct.last_name) AS label,
            COALESCE(ct.email, ct.phone, '')          AS sublabel,
            CONCAT('/crm/clients_appstack.php?action=view_contact&id=', ct.id) AS url
     FROM contacts ct
     WHERE ct.is_active = 1
       AND (ct.first_name LIKE ? OR ct.last_name LIKE ?
            OR ct.email LIKE ? OR ct.phone LIKE ?)
     ORDER BY ct.first_name, ct.last_name LIMIT 5)

    UNION ALL

    (SELECT 'Properties' AS category, 'map-pin'     AS icon,
            p.address                                AS label,
            COALESCE(p.city, '')                     AS sublabel,
            COALESCE(
                CONCAT('/crm/clients_appstack.php?action=view_contact&id=', p.site_contact_id),
                '/crm/clients_appstack.php'
            )                                        AS url
     FROM properties p
     WHERE p.status = 'active'
       AND (p.address LIKE ? OR p.city LIKE ?)
     ORDER BY p.address LIMIT 5)

    UNION ALL

    (SELECT 'Quotes'     AS category, 'file-text'   AS icon,
            CONCAT(q.quote_number,
                IF(q.title IS NOT NULL AND q.title <> '', CONCAT(' — ', q.title), '')) AS label,
            CONCAT(q.status,
                IF(q.total_amount, CONCAT(' · \$', FORMAT(q.total_amount, 2)), ''))    AS sublabel,
            CONCAT('/crm/quotes/view.php?id=', q.id)                                  AS url
     FROM quotes q
     WHERE q.quote_number LIKE ? OR q.title LIKE ?
     ORDER BY q.created_at DESC LIMIT 4)

    UNION ALL

    (SELECT 'Jobs'       AS category, 'briefcase'   AS icon,
            CONCAT(j.plan_number,
                IF(j.title IS NOT NULL AND j.title <> '', CONCAT(' — ', j.title), ''))  AS label,
            CONCAT(j.status,
                IF(j.service_type IS NOT NULL, CONCAT(' · ', j.service_type), ''))      AS sublabel,
            CONCAT('/crm/jobs/view.php?id=', j.id)                                      AS url
     FROM job_plans j
     WHERE j.plan_number LIKE ? OR j.title LIKE ?
     ORDER BY j.created_at DESC LIMIT 4)

    UNION ALL

    (SELECT 'Invoices'   AS category, 'credit-card' AS icon,
            i.invoice_number                         AS label,
            CONCAT(i.status,
                IF(i.total, CONCAT(' · \$', FORMAT(i.total, 2)), ''))  AS sublabel,
            CONCAT('/crm/invoices/view.php?id=', i.id)                 AS url
     FROM invoices i
     WHERE i.invoice_number LIKE ?
     ORDER BY i.created_at DESC LIMIT 3)

    UNION ALL

    (SELECT 'Team'       AS category, 'users'       AS icon,
            u.full_name                              AS label,
            CONCAT(u.role,
                IF(u.email IS NOT NULL AND u.email <> '', CONCAT(' · ', u.email), ''))  AS sublabel,
            '/crm/team/index.php'                                                        AS url
     FROM users u
     WHERE u.is_active = 1
       AND (u.full_name LIKE ? OR u.email LIKE ?)
     ORDER BY u.full_name LIMIT 3)
";

// Param order mirrors the ? placeholders above:
// contacts(4) + properties(2) + quotes(2) + jobs(2) + invoices(1) + team(2) = 13
$params = [
    $like, $like, $like, $like,  // contacts
    $like, $like,                // properties
    $like, $like,                // quotes
    $like, $like,                // jobs
    $like,                       // invoices
    $like, $like,                // team
];

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('global-search: ' . $e->getMessage());
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

echo json_encode(['success' => true, 'results' => $rows]);
