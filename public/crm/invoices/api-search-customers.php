<?php
/**
 * Customer Search API — returns contacts + companies for invoice typeahead
 * GET /crm/invoices/api-search-customers.php?q=searchterm
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

$db = getDB();
$like = '%' . $q . '%';
$results = [];

// Search contacts first (primary entity in production)
$stmt = $db->prepare("
    SELECT
        'contact'                                   AS type,
        c.id                                        AS id,
        CONCAT(c.first_name, ' ', c.last_name)      AS label,
        c.email                                     AS email,
        c.mobile                                    AS phone,
        c.receive_sms                               AS receive_sms,
        COALESCE(p.address, '')                     AS property_address,
        COALESCE(p.city, '')                        AS property_city,
        COALESCE(p.id, 0)                           AS property_id
    FROM contacts c
    LEFT JOIN properties p ON p.site_contact_id = c.id
    WHERE
        c.first_name LIKE ?
        OR c.last_name LIKE ?
        OR c.email LIKE ?
        OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
    ORDER BY c.first_name, c.last_name
    LIMIT 20
");
$stmt->execute([$like, $like, $like, $like]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($contacts as $row) {
    $results[] = [
        'type'             => 'contact',
        'id'               => (int)$row['id'],
        'label'            => $row['label'],
        'sublabel'         => $row['email'] ?: '',
        'email'            => $row['email'],
        'phone'            => $row['phone'],
        'receive_sms'      => (bool)$row['receive_sms'],
        'property_address' => $row['property_address'],
        'property_city'    => $row['property_city'],
        'property_id'      => (int)$row['property_id'],
    ];
}

// Also search companies — match name, email, billing address, notes,
// and linked primary/billing contact names so stratas are findable by
// any identifier the user remembers (e.g. plan number "VR15/40" stored
// in notes or address).
$stmt2 = $db->prepare("
    SELECT DISTINCT co.id, co.company_name, co.company_type, co.billing_email,
           co.billing_address, co.billing_city
    FROM companies co
    LEFT JOIN contacts pc ON pc.id = co.primary_contact_id
    LEFT JOIN contacts bc ON bc.id = co.billing_contact_id
    WHERE co.company_name    LIKE ?
       OR co.billing_email   LIKE ?
       OR co.billing_address LIKE ?
       OR co.notes           LIKE ?
       OR CONCAT(COALESCE(pc.first_name,''), ' ', COALESCE(pc.last_name,'')) LIKE ?
       OR CONCAT(COALESCE(bc.first_name,''), ' ', COALESCE(bc.last_name,'')) LIKE ?
    ORDER BY co.company_name
    LIMIT 20
");
$stmt2->execute([$like, $like, $like, $like, $like, $like]);
$companies = $stmt2->fetchAll(PDO::FETCH_ASSOC);

foreach ($companies as $row) {
    $typeLabel = ucwords(str_replace('_', ' ', (string)($row['company_type'] ?? '')));
    $addr = trim(($row['billing_address'] ?? '') . (!empty($row['billing_city']) ? ', ' . $row['billing_city'] : ''));
    $sub = $row['billing_email'] ?: $addr;
    if ($typeLabel) {
        $sub = $sub ? ($typeLabel . ' · ' . $sub) : $typeLabel;
    }
    $results[] = [
        'type'    => 'company',
        'id'      => (int)$row['id'],
        'label'   => $row['company_name'],
        'sublabel' => $sub,
        'email'   => $row['billing_email'],
    ];
}

echo json_encode(['results' => $results]);
