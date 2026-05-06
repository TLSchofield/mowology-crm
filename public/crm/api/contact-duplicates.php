<?php
/**
 * API: Contact Duplicate Detection & Merge
 *
 * Actions:
 *   pair   (GET)  — returns full details of a contact and its potential duplicates
 *   merge  (POST) — merges two contacts (keep one, absorb the other)
 *
 * Returns JSON. Requires CRM login.
 */

// Bootstrap
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
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

// Parse JSON body once (for POST requests)
$input = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Determine action from query string, form data, or JSON body
$action = $_GET['action'] ?? ($_POST['action'] ?? ($input['action'] ?? null));

$db = getDB();

try {

    if ($action === 'pair') {
        // GET: Return a contact and its potential duplicates with full details
        $contactId = intval($_GET['id'] ?? 0);
        if (!$contactId) throw new Exception('Contact ID is required');

        $duplicateMap = findDuplicateContacts();
        $duplicateIds = $duplicateMap[$contactId] ?? [];

        if (empty($duplicateIds)) {
            echo json_encode(['success' => true, 'contact' => null, 'duplicates' => []]);
            exit;
        }

        // Fetch full details for the contact and its duplicates
        $allIds = array_merge([$contactId], $duplicateIds);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));

        $stmt = $db->prepare("
            SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.mobile,
                   c.preferred_contact_method, c.notes, c.created_at,
                   c.receive_sms, c.receive_marketing, c.consent_quote_followup,
                   GROUP_CONCAT(DISTINCT p.address SEPARATOR ', ') as property_addresses,
                   COUNT(DISTINCT qr.id) as quote_request_count
            FROM contacts c
            LEFT JOIN properties p ON c.id = p.site_contact_id
            LEFT JOIN quote_requests qr ON c.id = qr.contact_id
            WHERE c.id IN ({$placeholders})
            GROUP BY c.id
        ");
        $stmt->execute($allIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $contact = null;
        $dupes = [];
        foreach ($rows as $row) {
            if ((int)$row['id'] === $contactId) {
                $contact = $row;
            } else {
                $dupes[] = $row;
            }
        }

        echo json_encode(['success' => true, 'contact' => $contact, 'duplicates' => $dupes]);

    } elseif ($action === 'merge') {
        // POST: Merge two contacts
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        if (!$input) throw new Exception('Invalid request body');

        // CSRF check
        if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
            throw new Exception('Invalid security token. Please reload the page.');
        }
        session_write_close();

        $keepId = intval($input['keep_id'] ?? 0);
        $mergeId = intval($input['merge_id'] ?? 0);
        $fields = $input['fields'] ?? [];

        if (!$keepId || !$mergeId) {
            throw new Exception('Both contact IDs are required');
        }

        $result = mergeContacts($keepId, $mergeId, $fields, $user['id']);

        echo json_encode($result);

    } else {
        throw new Exception('Unknown action: ' . ($action ?? 'none'));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
