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
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

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
                SELECT p.id, p.address, p.city, p.property_type, p.status
                FROM properties p
                WHERE p.site_contact_id = ?
                  AND p.status = 'active'
                ORDER BY p.address ASC
            ");
            $stmt->execute([$contactId]);
        } elseif ($companyId) {
            $stmt = $db->prepare("
                SELECT p.id, p.address, p.city, p.property_type, p.status
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

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
