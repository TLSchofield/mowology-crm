<?php
/**
 * Client Search API
 *
 * GET ?action=search&q=<term>&type=<contact|company>
 *   Returns matching contacts or companies for autocomplete
 *
 * GET ?action=properties&contact_id=<id>
 *   Returns properties linked to a specific contact
 *
 * GET ?action=properties&company_id=<id>
 *   Returns properties linked to a specific company
 */
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
session_write_close(); // release session lock — autocomplete fires on every keypress

$db = getDB();
$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'search':
        $q = trim($_GET['q'] ?? '');
        $type = $_GET['type'] ?? 'contact';

        if (strlen($q) < 1) {
            echo json_encode(['success' => true, 'results' => []]);
            exit;
        }

        $searchTerm = '%' . $q . '%';

        if ($type === 'company') {
            $stmt = $db->prepare("
                SELECT c.id, c.company_name, c.company_type, c.account_status,
                       pc.first_name as contact_first, pc.last_name as contact_last,
                       (SELECT COUNT(*) FROM company_properties cp WHERE cp.company_id = c.id) as property_count
                FROM companies c
                LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
                WHERE c.company_name LIKE ?
                   OR pc.first_name LIKE ?
                   OR pc.last_name LIKE ?
                ORDER BY c.company_name ASC
                LIMIT 20
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($results as $r) {
                $contactName = trim(($r['contact_first'] ?? '') . ' ' . ($r['contact_last'] ?? ''));
                $formatted[] = [
                    'id' => (int)$r['id'],
                    'label' => $r['company_name'],
                    'sublabel' => $contactName ? "Contact: {$contactName}" : '',
                    'type' => $r['company_type'],
                    'property_count' => (int)$r['property_count'],
                ];
            }

            echo json_encode(['success' => true, 'results' => $formatted]);

        } else {
            // Search contacts
            $stmt = $db->prepare("
                SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
                       (SELECT COUNT(*) FROM properties p WHERE p.site_contact_id = c.id) as property_count
                FROM contacts c
                WHERE c.is_active = 1
                  AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?
                       OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)
                ORDER BY c.first_name, c.last_name
                LIMIT 20
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($results as $r) {
                $name = trim($r['first_name'] . ' ' . $r['last_name']);
                $formatted[] = [
                    'id' => (int)$r['id'],
                    'label' => $name,
                    'sublabel' => $r['email'] ?: ($r['phone'] ?: ''),
                    'property_count' => (int)$r['property_count'],
                ];
            }

            echo json_encode(['success' => true, 'results' => $formatted]);
        }
        break;

    case 'properties':
        $contactId = isset($_GET['contact_id']) ? intval($_GET['contact_id']) : 0;
        $companyId = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

        if ($contactId) {
            $stmt = $db->prepare("
                SELECT p.id, p.address, p.city, p.province, p.postal_code,
                       p.property_type, p.status, p.latitude, p.longitude
                FROM properties p
                WHERE p.site_contact_id = ?
                  AND p.status = 'active'
                ORDER BY p.address ASC
            ");
            $stmt->execute([$contactId]);
        } elseif ($companyId) {
            $stmt = $db->prepare("
                SELECT p.id, p.address, p.city, p.province, p.postal_code,
                       p.property_type, p.status, p.latitude, p.longitude
                FROM properties p
                INNER JOIN company_properties cp ON p.id = cp.property_id
                WHERE cp.company_id = ?
                  AND p.status = 'active'
                ORDER BY p.address ASC
            ");
            $stmt->execute([$companyId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing contact_id or company_id']);
            exit;
        }

        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'properties' => $properties]);
        break;

    case 'all-contacts':
        // Return all active contacts (for initial load / small datasets)
        $stmt = $db->prepare("
            SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
                   (SELECT COUNT(*) FROM properties p WHERE p.site_contact_id = c.id) as property_count
            FROM contacts c
            WHERE c.is_active = 1
            ORDER BY c.first_name, c.last_name
            LIMIT 100
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($results as $r) {
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $formatted[] = [
                'id' => (int)$r['id'],
                'label' => $name,
                'sublabel' => $r['email'] ?: ($r['phone'] ?: ''),
                'property_count' => (int)$r['property_count'],
            ];
        }

        echo json_encode(['success' => true, 'results' => $formatted]);
        break;

    case 'create-property':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST required']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $contactId    = intval($input['contact_id'] ?? 0);
        $address      = trim($input['address'] ?? '');
        $city         = trim($input['city'] ?? '');
        $province     = strtoupper(trim($input['province'] ?? 'BC')) ?: 'BC';
        $postalCode   = trim($input['postal_code'] ?? '');
        $propertyType = trim($input['property_type'] ?? '');

        if (!$contactId || !$address || !$city) {
            echo json_encode(['success' => false, 'error' => 'Contact, address, and city are required']);
            exit;
        }

        // Verify contact exists
        $chk = $db->prepare("SELECT first_name, last_name FROM contacts WHERE id = ? AND is_active = 1");
        $chk->execute([$contactId]);
        $ct = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$ct) {
            echo json_encode(['success' => false, 'error' => 'Contact not found']);
            exit;
        }

        $propertyName = trim($ct['first_name'] . ' ' . $ct['last_name']) . ' Property';

        $stmt = $db->prepare("
            INSERT INTO properties (property_name, address, city, province, postal_code, property_type, site_contact_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$propertyName, $address, $city, $province, $postalCode, $propertyType ?: null, $contactId]);
        $newId = (int)$db->lastInsertId();

        echo json_encode([
            'success'  => true,
            'property' => [
                'id'            => $newId,
                'address'       => $address,
                'city'          => $city,
                'province'      => $province,
                'postal_code'   => $postalCode,
                'property_type' => $propertyType,
                'status'        => 'active',
            ],
        ]);
        break;

    case 'contact-details':
        $contactId = intval($_GET['contact_id'] ?? 0);
        if (!$contactId) {
            echo json_encode(['success' => false, 'error' => 'Missing contact_id']);
            exit;
        }
        $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, mobile FROM contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) {
            echo json_encode(['success' => false, 'error' => 'Contact not found']);
            exit;
        }
        echo json_encode(['success' => true, 'contact' => $contact]);
        break;

    case 'update-contact':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST required']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        $contactId = intval($input['contact_id'] ?? 0);
        if (!$contactId) {
            echo json_encode(['success' => false, 'error' => 'Missing contact_id']);
            exit;
        }
        // Only allow updating these specific fields
        $updates = [];
        $params = [];
        foreach (['email', 'phone', 'mobile'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "`{$field}` = ?";
                $params[] = trim($input[$field]) ?: null;
            }
        }
        if (empty($updates)) {
            echo json_encode(['success' => false, 'error' => 'No fields to update']);
            exit;
        }
        $params[] = $contactId;
        $stmt = $db->prepare("UPDATE contacts SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
