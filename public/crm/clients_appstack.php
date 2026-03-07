<?php
/**
 * Client Management - List, Create, Edit, Delete
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$db = getDB();
$pageTitle = 'Clients';
$activePage = 'clients';

// Initialize error handler
$errorHandler = new CRMErrorHandler('Clients', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// Handle form submissions
$action = $_GET['action'] ?? null;
if ($action === 'create') $action = 'new'; // alias from job create page
$clientId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$messageType = '';

// Handle JSON requests (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $requestAction = $_GET['action'] ?? null;

    if ($requestAction === 'move_company') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $companyId = intval($jsonData['company_id'] ?? 0);
        $newStage = trim($jsonData['new_stage'] ?? '');

        if (!$companyId || !$newStage) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid company or stage']);
            exit;
        }

        try {
            if (updateCompanyLifecycleStage($companyId, $newStage, $user['id'])) {
                http_response_code(200);
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Failed to update stage']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => htmlspecialchars($e->getMessage())]);
        }
        exit;
    } elseif ($requestAction === 'move_contact') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $contactId = intval($jsonData['contact_id'] ?? 0);
        $newStage = trim($jsonData['new_stage'] ?? '');

        if (!$contactId || !$newStage) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid contact or stage']);
            exit;
        }

        try {
            if (updateContactLifecycleStage($contactId, $newStage, $user['id'])) {
                http_response_code(200);
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Failed to update stage']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => htmlspecialchars($e->getMessage())]);
        }
        exit;
    } elseif ($requestAction === 'manage_stages') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $stageAction = $jsonData['stage_action'] ?? null;
        $stageId = intval($jsonData['stage_id'] ?? 0);

        try {
            if ($stageAction === 'add') {
                $newStageId = addLifecycleStage([
                    'stage_key' => $jsonData['stage_key'] ?? '',
                    'stage_label' => $jsonData['stage_label'] ?? '',
                    'stage_order' => $jsonData['stage_order'] ?? 0,
                    'stage_color' => $jsonData['stage_color'] ?? '#6B7280',
                    'description' => $jsonData['description'] ?? null
                ]);

                if ($newStageId) {
                    http_response_code(200);
                    echo json_encode(['success' => true, 'stage_id' => $newStageId]);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Failed to add stage']);
                }
            } elseif ($stageAction === 'update') {
                if (updateLifecycleStage($stageId, $jsonData)) {
                    http_response_code(200);
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Failed to update stage']);
                }
            } elseif ($stageAction === 'delete') {
                if (deleteLifecycleStage($stageId)) {
                    http_response_code(200);
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Stage is in use or cannot be deleted']);
                }
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => htmlspecialchars($e->getMessage())]);
        }
        exit;
    } elseif ($requestAction === 'bulk_delete') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $ids = array_filter(array_map('intval', $jsonData['ids'] ?? []), function($id) { return $id > 0; });
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid IDs provided']);
            exit;
        }

        if (count($ids) > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Maximum 100 clients can be deleted at once']);
            exit;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Count related records for the response
            $relatedJobs = 0;
            $relatedInvoices = 0;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM job_plans WHERE company_id IN ({$placeholders})");
                $stmt->execute($ids);
                $relatedJobs = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE company_id IN ({$placeholders})");
                $stmt->execute($ids);
                $relatedInvoices = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}

            $db->beginTransaction();

            // Clean up activity_log (no FK constraint)
            try {
                $db->prepare("DELETE FROM activity_log WHERE company_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {}

            // Delete companies (cascades: company_properties, invoices, jobs)
            $stmt = $db->prepare("DELETE FROM companies WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'related_jobs' => $relatedJobs,
                'related_invoices' => $relatedInvoices
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Delete failed']);
        }
        exit;

    } elseif ($requestAction === 'convert_to_prospect') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $requestId = intval($jsonData['request_id'] ?? 0);
        if (!$requestId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request ID']);
            exit;
        }

        try {
            // Get quote request details
            $stmt = $db->prepare("
                SELECT qr.*, c.first_name, c.last_name, c.email, p.address, p.city
                FROM quote_requests qr
                LEFT JOIN contacts c ON qr.contact_id = c.id
                LEFT JOIN properties p ON qr.property_id = p.id
                WHERE qr.id = ?
            ");
            $stmt->execute([$requestId]);
            $qr = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$qr) {
                throw new Exception('Quote request not found');
            }

            // Create company for prospect
            $companyName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? '')) ?: 'Prospect from ' . formatDate($qr['created_at']);
            $billingEmail = $qr['email'] ?? null;
            $billingAddress = $qr['address'] ?? null;
            $billingCity = $qr['city'] ?? 'Vancouver';

            $stmt = $db->prepare("
                INSERT INTO companies (
                    company_name, company_type, billing_email, billing_address, billing_city,
                    billing_province, account_status, pref_attach_pdf
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyName,
                'individual',
                $billingEmail,
                $billingAddress,
                $billingCity,
                'BC',
                'active',
                1  // Default to attach PDF
            ]);

            $companyId = $db->lastInsertId();

            // Link quote request to new company
            $stmt = $db->prepare("UPDATE quote_requests SET company_id = ? WHERE id = ?");
            $stmt->execute([$companyId, $requestId]);

            http_response_code(200);
            echo json_encode(['success' => true, 'company_id' => $companyId]);
            exit;

        } catch (Exception $e) {
            $errorHandler->logError('Failed to convert quote request to prospect', $e, ['request_id' => $requestId]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to convert prospect']);
            exit;
        }

    } elseif ($requestAction === 'add_property_to_contact') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $contactId = intval($jsonData['contact_id'] ?? 0);
        $address = trim($jsonData['address'] ?? '');
        $city = trim($jsonData['city'] ?? 'Vancouver');
        $postalCode = trim($jsonData['postal_code'] ?? '');
        $propertyName = trim($jsonData['property_name'] ?? '');

        if (!$contactId || empty($address)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Contact ID and address are required']);
            exit;
        }

        try {
            // Check if property with this address already exists
            $checkStmt = $db->prepare("SELECT id, site_contact_id FROM properties WHERE address = ? LIMIT 1");
            $checkStmt->execute([$address]);
            $existingProp = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingProp) {
                // Link existing property to this contact if not already linked
                if (empty($existingProp['site_contact_id'])) {
                    $db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
                       ->execute([$contactId, $existingProp['id']]);
                }
                echo json_encode(['success' => true, 'property_id' => (int)$existingProp['id'], 'linked_existing' => true]);
            } else {
                // Auto-generate property_name if empty
                if (empty($propertyName)) {
                    $contactStmt = $db->prepare("SELECT first_name, last_name FROM contacts WHERE id = ?");
                    $contactStmt->execute([$contactId]);
                    $ct = $contactStmt->fetch(PDO::FETCH_ASSOC);
                    $propertyName = trim(($ct['first_name'] ?? '') . ' ' . ($ct['last_name'] ?? '')) . ' Property';
                }

                $stmt = $db->prepare("
                    INSERT INTO properties (property_name, address, city, province, postal_code, site_contact_id, status)
                    VALUES (?, ?, ?, 'BC', ?, ?, 'active')
                ");
                $stmt->execute([$propertyName, $address, $city, $postalCode, $contactId]);
                $newPropId = (int)$db->lastInsertId();
                echo json_encode(['success' => true, 'property_id' => $newPropId, 'linked_existing' => false]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to add property']);
        }
        exit;

    } elseif ($requestAction === 'add_property_to_company') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $companyId = intval($jsonData['company_id'] ?? 0);
        $address = trim($jsonData['address'] ?? '');
        $city = trim($jsonData['city'] ?? 'Vancouver');
        $postalCode = trim($jsonData['postal_code'] ?? '');
        $propertyName = trim($jsonData['property_name'] ?? '');
        $lat = isset($jsonData['latitude']) && $jsonData['latitude'] !== '' ? floatval($jsonData['latitude']) : null;
        $lng = isset($jsonData['longitude']) && $jsonData['longitude'] !== '' ? floatval($jsonData['longitude']) : null;

        if (!$companyId || empty($address)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Company ID and address are required']);
            exit;
        }

        try {
            // Get the company's primary contact to link the property through
            $compStmt = $db->prepare("SELECT primary_contact_id, billing_contact_id, company_name FROM companies WHERE id = ?");
            $compStmt->execute([$companyId]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
            if (!$comp) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Company not found']);
                exit;
            }

            $contactId = (int)($comp['primary_contact_id'] ?? 0) ?: (int)($comp['billing_contact_id'] ?? 0);
            if (!$contactId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Company has no linked contact. Add a contact first.']);
                exit;
            }

            // Check if property with this address already exists
            $checkStmt = $db->prepare("SELECT id, site_contact_id FROM properties WHERE address = ? LIMIT 1");
            $checkStmt->execute([$address]);
            $existingProp = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingProp) {
                if (empty($existingProp['site_contact_id'])) {
                    $db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
                       ->execute([$contactId, $existingProp['id']]);
                }
                echo json_encode(['success' => true, 'property_id' => (int)$existingProp['id'], 'linked_existing' => true]);
            } else {
                if (empty($propertyName)) {
                    $propertyName = trim($comp['company_name']) . ' Property';
                }

                $stmt = $db->prepare("
                    INSERT INTO properties (property_name, address, city, province, postal_code, latitude, longitude, site_contact_id, status)
                    VALUES (?, ?, ?, 'BC', ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$propertyName, $address, $city, $postalCode, $lat, $lng, $contactId]);
                $newPropId = (int)$db->lastInsertId();
                echo json_encode(['success' => true, 'property_id' => $newPropId, 'linked_existing' => false]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to add property']);
        }
        exit;

    } elseif ($requestAction === 'link_company_to_contact') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $contactId = intval($jsonData['contact_id'] ?? 0);
        $companyId = intval($jsonData['company_id'] ?? 0);

        if (!$contactId || !$companyId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid contact or company']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE companies SET primary_contact_id = ?, billing_contact_id = ? WHERE id = ?");
            $stmt->execute([$contactId, $contactId, $companyId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to link company']);
        }
        exit;

    } elseif ($requestAction === 'save_property_coords') {
        // Save lat/lng from client-side geocoding
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $propertyId = intval($jsonData['property_id'] ?? 0);
        $lat = floatval($jsonData['lat'] ?? 0);
        $lng = floatval($jsonData['lng'] ?? 0);

        if (!$propertyId || !$lat || !$lng) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid property or coordinates']);
            exit;
        }

        try {
            $db->prepare("UPDATE properties SET latitude = ?, longitude = ? WHERE id = ?")
               ->execute([$lat, $lng, $propertyId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save coordinates']);
        }
        exit;

    } elseif ($requestAction === 'unlink_property') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $propertyId = intval($jsonData['property_id'] ?? 0);
        $currentContactId = intval($jsonData['current_contact_id'] ?? 0);
        $newContactId = intval($jsonData['new_contact_id'] ?? 0);

        if (!$propertyId || !$currentContactId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Property ID and current contact ID are required']);
            exit;
        }

        try {
            // Verify property belongs to this contact
            $checkStmt = $db->prepare("SELECT id FROM properties WHERE id = ? AND site_contact_id = ?");
            $checkStmt->execute([$propertyId, $currentContactId]);
            if (!$checkStmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Property not found or not linked to this contact']);
                exit;
            }

            if ($newContactId > 0) {
                // Reassign to different contact
                $db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
                   ->execute([$newContactId, $propertyId]);
                echo json_encode(['success' => true, 'action' => 'reassigned']);
            } else {
                // Unlink (set to NULL)
                $db->prepare("UPDATE properties SET site_contact_id = NULL WHERE id = ?")
                   ->execute([$propertyId]);
                echo json_encode(['success' => true, 'action' => 'unlinked']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update property']);
        }
        exit;

    } elseif ($requestAction === 'add_client_note') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $noteContactId = intval($jsonData['contact_id'] ?? 0);
        $noteType = trim($jsonData['note_type'] ?? 'general');
        $noteContent = trim($jsonData['content'] ?? '');

        if (!$noteContactId || !$noteContent) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Contact ID and content are required']);
            exit;
        }

        $validTypes = ['general', 'customer_request', 'issue', 'follow_up', 'internal'];
        if (!in_array($noteType, $validTypes)) $noteType = 'general';

        try {
            if (function_exists('addClientNote')) {
                $result = addClientNote($noteContactId, $noteContent, $noteType, $user['id']);
                echo json_encode(['success' => true]);
            } else {
                // Fallback: direct insert
                $stmt = $db->prepare("INSERT INTO client_notes (contact_id, note_type, content, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$noteContactId, $noteType, $noteContent, $user['id']]);
                echo json_encode(['success' => true]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save note']);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? null;

        if ($action === 'save_client') {
            // ── Contact fields ──
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');
            $preferredContact = $_POST['preferred_contact_method'] ?? 'phone';
            $receiveSms = isset($_POST['receive_sms']) ? 1 : 0;
            $receiveMarketing = isset($_POST['receive_marketing']) ? 1 : 0;
            $consentQuoteFollowup = isset($_POST['consent_quote_followup']) ? 1 : 0;
            $notes = trim($_POST['notes'] ?? '');

            // ── Property address fields ──
            $propertyAddress = trim($_POST['property_address'] ?? '');
            $propertyCity = trim($_POST['property_city'] ?? 'Vancouver');
            $propertyPostalCode = trim($_POST['property_postal_code'] ?? '');
            $propertyLatitude = floatval($_POST['property_latitude'] ?? 0);
            $propertyLongitude = floatval($_POST['property_longitude'] ?? 0);

            // ── Optional company fields ──
            $linkCompany = isset($_POST['link_company']) ? true : false;
            $companyMode = $_POST['company_mode'] ?? 'new';
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : 0;
            $companyName = trim($_POST['company_name'] ?? '');
            $companyType = $_POST['company_type'] ?? 'individual';
            $billingAddress = trim($_POST['billing_address'] ?? '');
            $billingCity = trim($_POST['billing_city'] ?? 'Vancouver');
            $billingProvince = trim($_POST['billing_province'] ?? 'BC');
            $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');
            $accountStatus = $_POST['account_status'] ?? 'active';
            $paymentTerms = $_POST['payment_terms'] ?? 'Net 30';
            $prefAttachPdf = isset($_POST['pref_attach_pdf']) ? 1 : 0;

            // Validate
            if (empty($firstName)) {
                $message = 'Please enter a first name.';
                $messageType = 'error';
            } elseif (empty($lastName)) {
                $message = 'Please enter a last name.';
                $messageType = 'error';
            } elseif ($linkCompany && $companyMode === 'new' && empty($companyName)) {
                $message = 'Please enter a company name.';
                $messageType = 'error';
            } elseif ($linkCompany && $companyMode === 'existing' && !$companyId) {
                $message = 'Please select a company.';
                $messageType = 'error';
            } else {
                try {
                    // Ensure missing columns exist (safe migration — must run outside transaction)
                    try {
                        $existingCols = $db->query("SHOW COLUMNS FROM contacts")->fetchAll(PDO::FETCH_ASSOC);
                        $colNames = array_column($existingCols, 'Field');
                        if (!in_array('mobile', $colNames)) {
                            $db->exec("ALTER TABLE contacts ADD COLUMN mobile VARCHAR(50) AFTER phone");
                        }
                        if (!in_array('prospect_status', $colNames)) {
                            $db->exec("ALTER TABLE contacts ADD COLUMN prospect_status ENUM('prospect','client','inactive') DEFAULT 'prospect' AFTER is_active");
                        }
                    } catch (Exception $ignore) {}

                    $db->beginTransaction();

                    // Create contact with all fields
                    $stmt = $db->prepare("
                        INSERT INTO contacts (
                            first_name, last_name, email, phone, mobile,
                            preferred_contact_method, receive_sms, receive_marketing,
                            consent_quote_followup, consent_marketing_email, consent_sms,
                            consent_source, prospect_status, notes, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'crm_manual', 'prospect', ?, 1)
                    ");
                    $stmt->execute([
                        $firstName, $lastName, $email, $phone, $mobile,
                        $preferredContact, $receiveSms, $receiveMarketing,
                        $consentQuoteFollowup, $receiveMarketing, $receiveSms,
                        $notes
                    ]);
                    $contactId = $db->lastInsertId();

                    // Create property if address provided
                    if (!empty($propertyAddress)) {
                        $checkProp = $db->prepare("SELECT id FROM properties WHERE address = ? LIMIT 1");
                        $checkProp->execute([$propertyAddress]);
                        $existingProp = $checkProp->fetch(PDO::FETCH_ASSOC);

                        if ($existingProp) {
                            // Link existing property to this contact if not already linked
                            $db->prepare("UPDATE properties SET site_contact_id = COALESCE(site_contact_id, ?) WHERE id = ?")
                               ->execute([$contactId, $existingProp['id']]);
                        } else {
                            $db->prepare("
                                INSERT INTO properties (property_name, address, city, province, postal_code, latitude, longitude, site_contact_id, status)
                                VALUES (?, ?, ?, 'BC', ?, ?, ?, ?, 'active')
                            ")->execute([
                                $firstName . ' ' . $lastName . ' Property',
                                $propertyAddress,
                                $propertyCity,
                                $propertyPostalCode,
                                $propertyLatitude ?: null,
                                $propertyLongitude ?: null,
                                $contactId
                            ]);
                        }
                    }

                    if ($linkCompany) {
                        if ($companyMode === 'new') {
                            $stmt = $db->prepare("
                                INSERT INTO companies (
                                    company_name, company_type, primary_contact_id, billing_contact_id,
                                    billing_email, billing_phone, billing_address, billing_city,
                                    billing_province, billing_postal_code, account_status, payment_terms,
                                    pref_attach_pdf
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $companyName, $companyType, $contactId, $contactId,
                                $email, $phone, $billingAddress, $billingCity,
                                $billingProvince, $billingPostalCode, $accountStatus, $paymentTerms,
                                $prefAttachPdf
                            ]);
                            $message = 'Contact and company created successfully!';
                        } else {
                            $stmt = $db->prepare("
                                UPDATE companies SET primary_contact_id = ?, billing_contact_id = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$contactId, $contactId, $companyId]);
                            $message = 'Contact created and linked to company!';
                        }
                        $messageType = 'success';
                    } else {
                        $message = 'Contact created successfully!';
                        $messageType = 'success';
                    }

                    $db->commit();
                    $action = null; // Return to list view
                } catch (PDOException $e) {
                    $db->rollBack();
                    $errorHandler->logDatabaseError($e, '', [], 'Failed to save contact. Please try again.');
                    $message = 'Failed to save contact. Please try again.';
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'delete_client') {
            if ($clientId) {
                try {
                    $db->prepare("DELETE FROM companies WHERE id = ?")->execute([$clientId]);
                    $message = 'Client deleted successfully!';
                    $messageType = 'success';
                    $action = null;
                    $clientId = 0;
                } catch (PDOException $e) {
                    $errorHandler->logDatabaseError($e, '', [], 'Failed to delete client. Please try again.');
                    $message = 'Failed to delete client. Please try again.';
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'update_contact') {
            $contactId = intval($_POST['contact_id'] ?? 0);
            if ($contactId) {
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $preferredContact = $_POST['preferred_contact_method'] ?? 'phone';
                $receiveSms = isset($_POST['receive_sms']) ? 1 : 0;
                $receiveMarketing = isset($_POST['receive_marketing']) ? 1 : 0;
                $consentQuoteFollowup = isset($_POST['consent_quote_followup']) ? 1 : 0;
                $notes = trim($_POST['notes'] ?? '');

                if (empty($firstName)) {
                    $message = 'Please enter a first name.';
                    $messageType = 'error';
                    $action = 'edit_contact';
                    $clientId = $contactId;
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE contacts SET
                                first_name = ?, last_name = ?, email = ?, phone = ?, mobile = ?,
                                preferred_contact_method = ?, receive_sms = ?, receive_marketing = ?,
                                consent_quote_followup = ?, notes = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $firstName, $lastName, $email, $phone, $mobile,
                            $preferredContact, $receiveSms, $receiveMarketing,
                            $consentQuoteFollowup, $notes, $contactId
                        ]);
                        $message = 'Contact updated successfully!';
                        $messageType = 'success';
                        $action = null;
                    } catch (PDOException $e) {
                        $errorHandler->logDatabaseError($e, '', [], 'Failed to update contact.');
                        $message = 'Failed to update contact. Please try again.';
                        $messageType = 'error';
                        $action = 'edit_contact';
                        $clientId = $contactId;
                    }
                }
            }
        } elseif ($action === 'update_company') {
            $companyId = intval($_POST['company_id'] ?? 0);
            if ($companyId) {
                $companyName = trim($_POST['company_name'] ?? '');
                $companyType = $_POST['company_type'] ?? 'individual';
                $billingEmail = trim($_POST['billing_email'] ?? '');
                $billingPhone = trim($_POST['billing_phone'] ?? '');
                $billingAddress = trim($_POST['billing_address'] ?? '');
                $billingCity = trim($_POST['billing_city'] ?? 'Vancouver');
                $billingProvince = trim($_POST['billing_province'] ?? 'BC');
                $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');
                $accountStatus = $_POST['account_status'] ?? 'active';
                $paymentTerms = $_POST['payment_terms'] ?? 'Net 30';
                $paymentMethod = $_POST['payment_method'] ?? 'invoice';
                $companyNotes = trim($_POST['notes'] ?? '');

                if (empty($companyName)) {
                    $message = 'Please enter a company name.';
                    $messageType = 'error';
                    $action = 'edit_company';
                    $clientId = $companyId;
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE companies SET
                                company_name = ?, company_type = ?, billing_email = ?,
                                billing_phone = ?, billing_address = ?, billing_city = ?,
                                billing_province = ?, billing_postal_code = ?,
                                account_status = ?, payment_terms = ?, payment_method = ?,
                                notes = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $companyName, $companyType, $billingEmail,
                            $billingPhone, $billingAddress, $billingCity,
                            $billingProvince, $billingPostalCode,
                            $accountStatus, $paymentTerms, $paymentMethod,
                            $companyNotes, $companyId
                        ]);
                        $message = 'Company updated successfully!';
                        $messageType = 'success';
                        // Redirect to view
                        $action = 'view_company';
                        $clientId = $companyId;
                    } catch (PDOException $e) {
                        $errorHandler->logDatabaseError($e, '', [], 'Failed to update company.');
                        $message = 'Failed to update company. Please try again.';
                        $messageType = 'error';
                        $action = 'edit_company';
                        $clientId = $companyId;
                    }
                }
            }
        }
    }
}

// Get client data if editing
$client = null;
if ($action === 'edit' && $clientId) {
    // Legacy action — redirect to view_company
    $action = 'view_company';
}

// Get company data if editing a company
$editCompany = null;
if ($action === 'edit_company' && $clientId) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$clientId]);
    $editCompany = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editCompany) {
        $message = 'Company not found.';
        $messageType = 'error';
        $action = null;
    }
}

// Get contact data if editing a contact
$contact = null;
if ($action === 'edit_contact' && $clientId) {
    $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
    $stmt->execute([$clientId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) {
        $message = 'Contact not found.';
        $messageType = 'error';
        $action = null;
    }
}

// Get contact data if viewing a contact (read-only profile page)
$viewContact = null;
$contactProperties = [];
$contactCompany = null;
if ($action === 'view_contact' && $clientId) {
    $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
    $stmt->execute([$clientId]);
    $viewContact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$viewContact) {
        $message = 'Contact not found.';
        $messageType = 'error';
        $action = null;
    } else {
        // Fetch properties linked via site_contact_id
        $stmt = $db->prepare("
            SELECT p.id, p.property_name, p.address, p.city, p.province,
                   p.postal_code, p.latitude, p.longitude, p.property_type,
                   p.lot_size_sqft, p.status, p.notes,
                   COALESCE(p.total_lawn_sqft, 0) AS total_lawn_sqft,
                   COALESCE(p.total_hard_surface_sqft, 0) AS total_hard_surface_sqft,
                   COALESCE(p.total_hedge_linear_ft, 0) AS total_hedge_linear_ft,
                   COALESCE(p.total_other_sqft, 0) AS total_other_sqft,
                   p.measurements_updated_at,
                   (SELECT COUNT(*) FROM property_measurements pm WHERE pm.property_id = p.id) AS measurement_count,
                   (SELECT COUNT(*) FROM job_geofences jg WHERE jg.property_id = p.id AND jg.zone_type = 'arrival_border') AS has_arrival_border
            FROM properties p
            WHERE p.site_contact_id = ?
            ORDER BY p.address ASC
        ");
        $stmt->execute([$clientId]);
        $contactProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch tags for all properties (property_access + property_warning groups)
        $propertyTagMap = [];
        $availableTags = [];
        if (!empty($contactProperties)) {
            $propIds = array_column($contactProperties, 'id');
            $tPlaceholders = implode(',', array_fill(0, count($propIds), '?'));
            try {
                $tagStmt = $db->prepare("
                    SELECT et.entity_id AS property_id, et.id AS entity_tag_id,
                           t.id AS tag_id, t.tag_key, t.tag_label, t.tag_group,
                           t.tag_color, t.icon, t.has_value, et.tag_value
                    FROM entity_tags et
                    JOIN tags t ON t.id = et.tag_id
                    WHERE et.entity_type = 'property'
                      AND et.entity_id IN ({$tPlaceholders})
                      AND t.is_active = 1
                    ORDER BY t.sort_order ASC, t.tag_label ASC
                ");
                $tagStmt->execute(array_values($propIds));
                $allTags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allTags as $tRow) {
                    $propertyTagMap[(int)$tRow['property_id']][] = $tRow;
                }

                // Fetch available tags for property groups
                $availStmt = $db->query("
                    SELECT id AS tag_id, tag_key, tag_label, tag_group,
                           tag_color, icon, has_value, sort_order
                    FROM tags
                    WHERE is_active = 1
                      AND tag_group IN ('property_access', 'property_warning')
                    ORDER BY sort_order ASC, tag_label ASC
                ");
                $availableTags = $availStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tags tables may not exist yet
            }

            // Fetch individual measurement areas per property
            $propertyMeasurements = [];
            try {
                $mStmt = $db->prepare("
                    SELECT property_id, measurement_name, measurement_type,
                           measurement_group_key, area_sqft, linear_ft, perimeter_ft
                    FROM property_measurements
                    WHERE property_id IN ({$tPlaceholders})
                    ORDER BY measurement_group_key ASC, measurement_name ASC
                ");
                $mStmt->execute(array_values($propIds));
                $allMeasurements = $mStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allMeasurements as $mRow) {
                    $propertyMeasurements[(int)$mRow['property_id']][] = $mRow;
                }
            } catch (Exception $e) {
                // property_measurements table may not exist yet
            }
        }

        // Check if contact is linked to a company (as primary or billing contact)
        try {
            $stmt = $db->prepare("
                SELECT c.id, c.company_name, c.company_type, c.billing_email,
                       c.billing_phone, c.account_status
                FROM companies c
                WHERE c.primary_contact_id = ? OR c.billing_contact_id = ?
                LIMIT 1
            ");
            $stmt->execute([$clientId, $clientId]);
            $contactCompany = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // billing_contact_id may not exist yet
            $contactCompany = null;
        }

        // Fetch other contacts for property reassignment dropdown
        $otherContacts = [];
        try {
            $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE id != ? AND is_active = 1 ORDER BY first_name, last_name");
            $stmt->execute([$clientId]);
            $otherContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* ok */ }

        // ── Quotes for this contact's properties ──
        $contactQuotes = [];
        try {
            $stmt = $db->prepare("
                SELECT q.id, q.quote_number, q.title, q.status,
                       COALESCE(NULLIF(q.total_amount, 0), q.amount, 0) AS total_amount,
                       q.created_at, q.sent_at, q.accepted_at, p.address AS property_address
                FROM quotes q
                JOIN properties p ON q.property_id = p.id
                WHERE p.site_contact_id = ?
                ORDER BY q.created_at DESC
            ");
            $stmt->execute([$clientId]);
            $contactQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $contactQuotes = []; }

        // ── Job Plans for this contact's properties ──
        $contactPlans = [];
        try {
            $stmt = $db->prepare("
                SELECT jp.id, jp.plan_number, jp.title, jp.service_type, jp.status,
                       jp.is_recurring, jp.recurrence_pattern, jp.price_per_visit,
                       jp.property_id,
                       p.address AS property_address,
                       (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id) AS visit_count,
                       (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS completed_count,
                       (SELECT MAX(jv.scheduled_date) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS last_visit_date
                FROM job_plans jp
                JOIN properties p ON jp.property_id = p.id
                WHERE p.site_contact_id = ?
                ORDER BY FIELD(jp.status, 'active', 'paused', 'completed', 'cancelled'), jp.created_at DESC
            ");
            $stmt->execute([$clientId]);
            $contactPlans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $contactPlans = []; }

        // ── Recent Visits (last 10) ──
        $contactVisits = [];
        try {
            $stmt = $db->prepare("
                SELECT jv.id, jv.visit_number, jv.scheduled_date, jv.status,
                       jv.actual_amount, jv.completed_at,
                       jp.id AS plan_id, jp.plan_number, jp.title AS plan_title,
                       jp.service_type, p.address AS property_address
                FROM job_visits jv
                JOIN job_plans jp ON jv.plan_id = jp.id
                JOIN properties p ON jp.property_id = p.id
                WHERE p.site_contact_id = ?
                ORDER BY jv.scheduled_date DESC
                LIMIT 10
            ");
            $stmt->execute([$clientId]);
            $contactVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $contactVisits = []; }

        // ── Invoices linked to this contact's properties ──
        $contactInvoices = [];
        try {
            $propIds = array_column($contactProperties, 'id');
            if (!empty($propIds)) {
                $planIds = array_column($contactPlans, 'id');
                // Build WHERE: invoice.property_id in contact's properties OR invoice.plan_id in contact's plans
                $conditions = [];
                $params = [];
                $pPlaceholders = implode(',', array_fill(0, count($propIds), '?'));
                $conditions[] = "i.property_id IN ({$pPlaceholders})";
                $params = array_merge($params, $propIds);
                if (!empty($planIds)) {
                    $jpPlaceholders = implode(',', array_fill(0, count($planIds), '?'));
                    $conditions[] = "i.plan_id IN ({$jpPlaceholders})";
                    $params = array_merge($params, $planIds);
                }
                $whereClause = implode(' OR ', $conditions);
                $stmt = $db->prepare("
                    SELECT i.id, i.invoice_number, i.status, i.total, i.balance_due,
                           i.issue_date, i.due_date, i.amount_paid
                    FROM invoices i
                    WHERE {$whereClause}
                    ORDER BY i.created_at DESC
                ");
                $stmt->execute($params);
                $contactInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) { $contactInvoices = []; }

        // ── Billing Statement: Stripe payments per invoice ──
        $contactStripePayments = [];
        $statementLedger       = [];
        $statementTotalCharged = 0.0;
        $statementTotalPaid    = 0.0;
        $statementBalance      = 0.0;
        try {
            if (!empty($contactInvoices)) {
                $invIds = array_column($contactInvoices, 'id');
                $spPlaceholders = implode(',', array_fill(0, count($invIds), '?'));
                $stmt = $db->prepare("
                    SELECT invoice_id, amount_cents, webhook_received_at,
                           payment_method_type, stripe_receipt_url
                    FROM stripe_payments
                    WHERE invoice_id IN ($spPlaceholders) AND status = 'succeeded'
                    ORDER BY webhook_received_at ASC
                ");
                $stmt->execute($invIds);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                    $contactStripePayments[$sp['invoice_id']][] = $sp;
                }
            }

            // Build chronological ledger (invoices sorted by issue_date)
            $sortedInvoices = $contactInvoices;
            usort($sortedInvoices, function($a, $b) {
                $da = $a['issue_date'] ?? '2000-01-01';
                $db2 = $b['issue_date'] ?? '2000-01-01';
                return strcmp($da, $db2) ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
            });

            $runningBal = 0.0;
            foreach ($sortedInvoices as $inv) {
                // Charge row
                $charge = floatval($inv['total'] ?? 0);
                $runningBal += $charge;
                $statementTotalCharged += $charge;
                $statementLedger[] = [
                    'type'    => 'charge',
                    'date'    => $inv['issue_date'] ?? '',
                    'desc'    => $inv['invoice_number'],
                    'charge'  => $charge,
                    'credit'  => 0.0,
                    'balance' => $runningBal,
                    'link'    => 'invoices/view.php?id=' . (int)$inv['id'],
                    'status'  => $inv['status'] ?? '',
                    'receipt' => '',
                ];

                // Stripe payment rows
                $stripeTotal = 0.0;
                foreach ($contactStripePayments[$inv['id']] ?? [] as $sp) {
                    $credit = round($sp['amount_cents'] / 100, 2);
                    $stripeTotal  += $credit;
                    $runningBal   -= $credit;
                    $statementTotalPaid += $credit;
                    $pmType = $sp['payment_method_type'] ?? 'card';
                    $pmLabels = ['card' => 'Card (Stripe)', 'us_bank_account' => 'Bank Transfer (Stripe)'];
                    $pmLabel = $pmLabels[$pmType] ?? (ucwords(str_replace('_', ' ', $pmType)) . ' (Stripe)');
                    $statementLedger[] = [
                        'type'    => 'payment',
                        'date'    => $sp['webhook_received_at'] ?? '',
                        'desc'    => $pmLabel,
                        'charge'  => 0.0,
                        'credit'  => $credit,
                        'balance' => $runningBal,
                        'link'    => '',
                        'status'  => '',
                        'receipt' => $sp['stripe_receipt_url'] ?? '',
                    ];
                }

                // Manual payment (amount not covered by Stripe)
                $manualCredit = round(floatval($inv['amount_paid'] ?? 0) - $stripeTotal, 2);
                if ($manualCredit > 0.005 && ($inv['status'] ?? '') !== 'draft') {
                    $pmMethod = $inv['payment_method'] ?? '';
                    $pmLabel  = $pmMethod && $pmMethod !== 'stripe'
                        ? ucwords(str_replace('_', ' ', $pmMethod))
                        : 'Payment recorded';
                    $runningBal -= $manualCredit;
                    $statementTotalPaid += $manualCredit;
                    $statementLedger[] = [
                        'type'    => 'payment',
                        'date'    => $inv['paid_at'] ?? ($inv['due_date'] ?? ''),
                        'desc'    => $pmLabel,
                        'charge'  => 0.0,
                        'credit'  => $manualCredit,
                        'balance' => $runningBal,
                        'link'    => '',
                        'status'  => '',
                        'receipt' => '',
                    ];
                }
            }
            $statementBalance = $runningBal;
        } catch (Exception $e) {
            $statementLedger  = [];
            $statementBalance = 0.0;
        }

        // ── Financial Summary ──
        $totalRevenue = 0;
        $totalOutstanding = 0;
        $totalInvoiced = 0;
        $totalPaid = 0;
        foreach ($contactInvoices as $inv) {
            $totalInvoiced += floatval($inv['total'] ?? 0);
            $totalPaid += floatval($inv['amount_paid'] ?? 0);
            if (in_array($inv['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
                $totalOutstanding += floatval($inv['balance_due'] ?? 0);
            }
        }
        $totalRevenue = $totalPaid;
        $activePlanCount = 0;
        $completedVisitCount = 0;
        foreach ($contactPlans as $pl) {
            if ($pl['status'] === 'active') $activePlanCount++;
            $completedVisitCount += intval($pl['completed_count'] ?? 0);
        }
        $totalQuoted = 0;
        foreach ($contactQuotes as $q) {
            if ($q['status'] === 'accepted') $totalQuoted += floatval($q['total_amount'] ?? 0);
        }

        // ── Expenses against this contact's jobs/properties ──
        $totalExpenses = 0;
        try {
            $expParams = [];
            $expConditions = [];
            if (!empty($propIds)) {
                $expConditions[] = "e.property_id IN (" . implode(',', array_fill(0, count($propIds), '?')) . ")";
                $expParams = array_merge($expParams, $propIds);
            }
            $planIdsForExp = array_column($contactPlans, 'id');
            if (!empty($planIdsForExp)) {
                $expConditions[] = "e.job_id IN (" . implode(',', array_fill(0, count($planIdsForExp), '?')) . ")";
                $expParams = array_merge($expParams, $planIdsForExp);
            }
            if (!empty($expConditions)) {
                $stmt = $db->prepare("SELECT COALESCE(SUM(e.total), 0) FROM expenses e WHERE " . implode(' OR ', $expConditions));
                $stmt->execute($expParams);
                $totalExpenses = floatval($stmt->fetchColumn());
            }
        } catch (Exception $e) { $totalExpenses = 0; }

        // ── Activity Log (last 20) ──
        $contactActivity = [];
        try {
            $actParams = [$clientId];
            $actConditions = ['al.contact_id = ?'];
            if (!empty($propIds)) {
                $actConditions[] = "al.property_id IN (" . implode(',', array_fill(0, count($propIds), '?')) . ")";
                $actParams = array_merge($actParams, $propIds);
            }
            $quoteIds = array_column($contactQuotes, 'id');
            if (!empty($quoteIds)) {
                $actConditions[] = "al.quote_id IN (" . implode(',', array_fill(0, count($quoteIds), '?')) . ")";
                $actParams = array_merge($actParams, $quoteIds);
            }
            $allPlanIds = array_column($contactPlans, 'id');
            if (!empty($allPlanIds)) {
                $actConditions[] = "al.plan_id IN (" . implode(',', array_fill(0, count($allPlanIds), '?')) . ")";
                $actParams = array_merge($actParams, $allPlanIds);
            }
            $stmt = $db->prepare("
                SELECT al.action, al.details, al.created_at, u.full_name
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE " . implode(' OR ', $actConditions) . "
                ORDER BY al.created_at DESC
                LIMIT 20
            ");
            $stmt->execute($actParams);
            $contactActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $contactActivity = []; }

        // ── Recent Photos (last 6 across all properties) ──
        $recentPhotos = [];
        try {
            if (!empty($propIds)) {
                $phPlaceholders = implode(',', array_fill(0, count($propIds), '?'));
                $stmt = $db->prepare("
                    SELECT vp.id, vp.filename, vp.photo_type, vp.thumb_path, vp.grid_path,
                           vp.view_path, vp.uploaded_at, vp.property_id
                    FROM visit_photos vp
                    WHERE vp.property_id IN ({$phPlaceholders})
                      AND vp.deleted_at IS NULL
                    ORDER BY vp.uploaded_at DESC
                    LIMIT 6
                ");
                $stmt->execute($propIds);
                $recentPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) { $recentPhotos = []; }

        // ── Client Notes ──
        $contactNotes = [];
        try {
            if (function_exists('getClientNotes')) {
                $contactNotes = getClientNotes($clientId);
            }
        } catch (Exception $e) { $contactNotes = []; }

        // ── Data Health Checks ──
        $dataHealth = [];
        if (empty($viewContact['email'])) $dataHealth[] = ['level' => 'warn', 'icon' => 'mail', 'text' => 'No email address'];
        if (empty($viewContact['phone']) && empty($viewContact['mobile'])) $dataHealth[] = ['level' => 'critical', 'icon' => 'phone', 'text' => 'No phone number'];
        if (empty($contactProperties)) $dataHealth[] = ['level' => 'critical', 'icon' => 'map-pin', 'text' => 'No properties linked'];
        if (empty($contactQuotes)) $dataHealth[] = ['level' => 'info', 'icon' => 'file-text', 'text' => 'No quotes created yet'];
        if ($activePlanCount === 0) $dataHealth[] = ['level' => 'info', 'icon' => 'clipboard', 'text' => 'No active job plans'];
        if ($ungeocodedCount > 0) $dataHealth[] = ['level' => 'warn', 'icon' => 'crosshair', 'text' => $ungeocodedCount . ' propert' . ($ungeocodedCount === 1 ? 'y' : 'ies') . ' not geocoded'];
        $missingBorderCount = 0;
        foreach ($contactProperties as $cp) {
            if (floatval($cp['latitude'] ?? 0) != 0 && (int)($cp['has_arrival_border'] ?? 0) === 0) $missingBorderCount++;
        }
        if ($missingBorderCount > 0) $dataHealth[] = ['level' => 'warn', 'icon' => 'shield-off', 'text' => $missingBorderCount . ' propert' . ($missingBorderCount === 1 ? 'y' : 'ies') . ' missing arrival border'];
        if (empty($contactNotes)) $dataHealth[] = ['level' => 'info', 'icon' => 'message-circle', 'text' => 'No client notes'];
        if ($totalOutstanding > 0) $dataHealth[] = ['level' => 'warn', 'icon' => 'alert-circle', 'text' => formatCurrency($totalOutstanding) . ' outstanding'];

        // ── Nearby Clients (properties with active job plans, within ~10 km) ──
        $nearbyClients = [];
        try {
            // Get centroid of this contact's geocoded properties
            $centroidLat = 0; $centroidLng = 0; $geoCount = 0;
            foreach ($contactProperties as $cp) {
                $lat = floatval($cp['latitude'] ?? 0);
                $lng = floatval($cp['longitude'] ?? 0);
                if ($lat != 0 && $lng != 0) { $centroidLat += $lat; $centroidLng += $lng; $geoCount++; }
            }
            if ($geoCount > 0) {
                $centroidLat /= $geoCount;
                $centroidLng /= $geoCount;
                // Bounding box ~10 km (approx 0.09 degrees lat, adjusted for longitude)
                $latDelta = 0.09;
                $lngDelta = 0.09 / cos(deg2rad($centroidLat));

                $stmt = $db->prepare("
                    SELECT p.id AS property_id, p.address, p.city, p.latitude, p.longitude,
                           c.id AS contact_id, c.first_name, c.last_name,
                           c.prospect_status,
                           GROUP_CONCAT(DISTINCT jp.service_type SEPARATOR ', ') AS service_types,
                           COUNT(DISTINCT jp.id) AS active_plan_count,
                           SUM(jp.price_per_visit) AS total_per_visit,
                           MAX(jv.scheduled_date) AS last_visit_date,
                           (SELECT COUNT(*) FROM job_visits jv2 WHERE jv2.plan_id IN (
                               SELECT jp2.id FROM job_plans jp2 WHERE jp2.property_id = p.id AND jp2.status = 'active'
                           ) AND jv2.status = 'completed') AS completed_visits
                    FROM properties p
                    JOIN contacts c ON p.site_contact_id = c.id
                    JOIN job_plans jp ON jp.property_id = p.id AND jp.status = 'active'
                    LEFT JOIN job_visits jv ON jv.plan_id = jp.id AND jv.status = 'completed'
                    WHERE p.latitude BETWEEN ? AND ?
                      AND p.longitude BETWEEN ? AND ?
                      AND p.latitude != 0 AND p.longitude != 0
                      AND c.id != ?
                    GROUP BY p.id
                    ORDER BY p.latitude ASC
                    LIMIT 50
                ");
                $stmt->execute([
                    $centroidLat - $latDelta, $centroidLat + $latDelta,
                    $centroidLng - $lngDelta, $centroidLng + $lngDelta,
                    $clientId
                ]);
                $rawNearby = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Calculate haversine distance and sort by distance
                foreach ($rawNearby as &$nb) {
                    $dLat = deg2rad(floatval($nb['latitude']) - $centroidLat);
                    $dLng = deg2rad(floatval($nb['longitude']) - $centroidLng);
                    $a = sin($dLat/2)**2 + cos(deg2rad($centroidLat)) * cos(deg2rad(floatval($nb['latitude']))) * sin($dLng/2)**2;
                    $nb['distance_km'] = round(6371.0 * 2 * atan2(sqrt($a), sqrt(1-$a)), 2);
                }
                unset($nb);
                usort($rawNearby, function($a, $b) { return $a['distance_km'] <=> $b['distance_km']; });
                $nearbyClients = array_slice($rawNearby, 0, 15);
            }
        } catch (Exception $e) { $nearbyClients = []; }

        // ── Smartest Service Day — which day has the most nearby visits scheduled ──
        $smartestDay = null;
        $dayBreakdown = [];
        try {
            $nearbyPropIds = array_column($nearbyClients, 'property_id');
            if (!empty($nearbyPropIds)) {
                $npPlaceholders = implode(',', array_fill(0, count($nearbyPropIds), '?'));

                // Query 1: Future scheduled visits grouped by day of week (next 6 weeks)
                $stmt = $db->prepare("
                    SELECT
                        DAYOFWEEK(jv.scheduled_date) AS dow,
                        DAYNAME(jv.scheduled_date) AS day_name,
                        COUNT(DISTINCT jv.id) AS visit_count,
                        COUNT(DISTINCT jp.property_id) AS property_count,
                        GROUP_CONCAT(DISTINCT jv.scheduled_date ORDER BY jv.scheduled_date SEPARATOR ',') AS upcoming_dates
                    FROM job_visits jv
                    JOIN job_plans jp ON jv.plan_id = jp.id
                    WHERE jp.property_id IN ({$npPlaceholders})
                      AND jv.scheduled_date >= CURDATE()
                      AND jv.scheduled_date < DATE_ADD(CURDATE(), INTERVAL 42 DAY)
                      AND jp.status = 'active'
                      AND jv.status NOT IN ('cancelled', 'skipped', 'completed')
                    GROUP BY DAYOFWEEK(jv.scheduled_date)
                    ORDER BY visit_count DESC
                ");
                $stmt->execute(array_values($nearbyPropIds));
                $dayBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Also check recurrence_day_of_week from active plans (covers future beyond generated visits)
                $stmt2 = $db->prepare("
                    SELECT jp.recurrence_day_of_week, COUNT(*) AS plan_count
                    FROM job_plans jp
                    WHERE jp.property_id IN ({$npPlaceholders})
                      AND jp.status = 'active'
                      AND jp.is_recurring = 1
                      AND jp.recurrence_day_of_week IS NOT NULL
                    GROUP BY jp.recurrence_day_of_week
                ");
                $stmt2->execute(array_values($nearbyPropIds));
                $recurDays = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                // Merge recurrence data into day breakdown
                $dayNames = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
                $dayScores = [];
                foreach ($dayBreakdown as $d) {
                    $dayScores[(int)$d['dow']] = [
                        'day_name' => $d['day_name'],
                        'visit_count' => (int)$d['visit_count'],
                        'property_count' => (int)$d['property_count'],
                        'recur_plans' => 0,
                        'upcoming_dates' => $d['upcoming_dates'] ?? '',
                    ];
                }
                // Add recurrence data (recurrence_day_of_week can be CSV like "1,3")
                foreach ($recurDays as $rd) {
                    $rdDays = array_map('intval', explode(',', $rd['recurrence_day_of_week']));
                    foreach ($rdDays as $rdDay) {
                        $dow = $rdDay + 1; // Convert 0-6 to 1-7 (DAYOFWEEK format)
                        if ($dow > 7) $dow = 1;
                        if (!isset($dayScores[$dow])) {
                            $dayScores[$dow] = [
                                'day_name' => $dayNames[$dow] ?? 'Unknown',
                                'visit_count' => 0,
                                'property_count' => 0,
                                'recur_plans' => 0,
                                'upcoming_dates' => '',
                            ];
                        }
                        $dayScores[$dow]['recur_plans'] += (int)$rd['plan_count'];
                    }
                }

                // Score each day: visits + recurrence weight
                foreach ($dayScores as $dow => &$ds) {
                    $ds['score'] = $ds['visit_count'] * 2 + $ds['recur_plans'] * 3 + $ds['property_count'];
                    $ds['dow'] = $dow;
                }
                unset($ds);
                usort($dayScores, function($a, $b) { return $b['score'] <=> $a['score']; });
                $dayBreakdown = $dayScores;

                if (!empty($dayBreakdown)) {
                    $best = $dayBreakdown[0];
                    // Find next occurrence of this day
                    $todayDow = (int)date('w') + 1; // 1=Sunday
                    $bestDow = $best['dow'];
                    $daysUntil = ($bestDow - $todayDow + 7) % 7;
                    if ($daysUntil === 0) $daysUntil = 7; // Next week if today
                    $nextDate = date('Y-m-d', strtotime("+{$daysUntil} days"));

                    $smartestDay = [
                        'day_name' => $best['day_name'],
                        'next_date' => $nextDate,
                        'visit_count' => $best['visit_count'],
                        'property_count' => $best['property_count'],
                        'recur_plans' => $best['recur_plans'],
                        'score' => $best['score'],
                    ];
                }
            }
        } catch (Exception $e) { $smartestDay = null; $dayBreakdown = []; }

        // Load Google Maps API (geometry for map display, places for address autocomplete)
        $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        if ($apiKey) {
            $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=geometry,places" defer></script>';
        }
        // Leaflet — needed for the Work Zone (geofence) polygon editor (hosted locally, CDN blocked by CSP)
        $extraHead .= '<link rel="stylesheet" href="/crm/js/leaflet/leaflet.min.css">';
        $extraHead .= '<script src="/crm/js/leaflet/leaflet.min.js"></script>';

        // Build property → plans map for the geofence modal (keyed by property_id)
        $plansByProperty = [];
        $propertyCoords  = [];
        foreach ($contactProperties as $cp) {
            $propertyCoords[(int)$cp['id']] = [
                'lat'  => floatval($cp['latitude']  ?? 49.2827),
                'lng'  => floatval($cp['longitude'] ?? -123.1207),
                'addr' => $cp['address'] ?? '',
            ];
        }
        foreach ($contactPlans as $pl) {
            $propId = (int)($pl['property_id'] ?? 0);
            if (!$propId) continue;
            if (!isset($plansByProperty[$propId])) $plansByProperty[$propId] = [];
            $plansByProperty[$propId][] = [
                'id'           => (int)$pl['id'],
                'plan_number'  => $pl['plan_number'],
                'title'        => $pl['title'] ?? '',
                'service_type' => $pl['service_type'] ?? '',
                'status'       => $pl['status'],
            ];
        }
    }
}

// Get company data if viewing a company (profile page)
$viewCompany = null;
$companyContacts = [];
$companyProperties = [];
if ($action === 'view_company' && $clientId) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$clientId]);
    $viewCompany = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$viewCompany) {
        $message = 'Company not found.';
        $messageType = 'error';
        $action = null;
    } else {
        // Fetch contacts linked to this company (primary or billing)
        $contactIds = array_unique(array_filter([
            (int)($viewCompany['primary_contact_id'] ?? 0),
            (int)($viewCompany['billing_contact_id'] ?? 0)
        ]));
        if (!empty($contactIds)) {
            $cPlaceholders = implode(',', array_fill(0, count($contactIds), '?'));
            $stmt = $db->prepare("
                SELECT id, first_name, last_name, email, phone, mobile
                FROM contacts
                WHERE id IN ({$cPlaceholders})
                ORDER BY first_name, last_name
            ");
            $stmt->execute(array_values($contactIds));
            $companyContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch properties linked to those contacts
        if (!empty($contactIds)) {
            $stmt = $db->prepare("
                SELECT p.id, p.property_name, p.address, p.city, p.province,
                       p.postal_code, p.latitude, p.longitude, p.property_type,
                       p.lot_size_sqft, p.status, p.site_contact_id,
                       (SELECT COUNT(*) FROM job_geofences jg WHERE jg.property_id = p.id AND jg.zone_type = 'arrival_border') AS has_arrival_border
                FROM properties p
                WHERE p.site_contact_id IN ({$cPlaceholders})
                ORDER BY p.address ASC
            ");
            $stmt->execute(array_values($contactIds));
            $companyProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch tags for company properties (same pattern as contact view)
        $companyPropertyTagMap = [];
        $companyAvailableTags = [];
        if (!empty($companyProperties)) {
            $compPropIds = array_column($companyProperties, 'id');
            $ctPlaceholders = implode(',', array_fill(0, count($compPropIds), '?'));
            try {
                $tagStmt = $db->prepare("
                    SELECT et.entity_id AS property_id, et.id AS entity_tag_id,
                           t.id AS tag_id, t.tag_key, t.tag_label, t.tag_group,
                           t.tag_color, t.icon, t.has_value, et.tag_value
                    FROM entity_tags et
                    JOIN tags t ON t.id = et.tag_id
                    WHERE et.entity_type = 'property'
                      AND et.entity_id IN ({$ctPlaceholders})
                      AND t.is_active = 1
                    ORDER BY t.sort_order ASC, t.tag_label ASC
                ");
                $tagStmt->execute(array_values($compPropIds));
                $allCompTags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allCompTags as $tRow) {
                    $companyPropertyTagMap[(int)$tRow['property_id']][] = $tRow;
                }

                $availStmt = $db->query("
                    SELECT id AS tag_id, tag_key, tag_label, tag_group,
                           tag_color, icon, has_value, sort_order
                    FROM tags
                    WHERE is_active = 1
                      AND tag_group IN ('property_access', 'property_warning')
                    ORDER BY sort_order ASC, tag_label ASC
                ");
                $companyAvailableTags = $availStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tags tables may not exist yet
            }
        }

        // Geocoded/ungeocoded counts for map display
        $compGeocodedCount = 0;
        $compUngeocodedCount = 0;
        foreach ($companyProperties as $cp) {
            if (floatval($cp['latitude'] ?? 0) != 0 && floatval($cp['longitude'] ?? 0) != 0) {
                $compGeocodedCount++;
            } else {
                $compUngeocodedCount++;
            }
        }

        // Fetch all active contacts for unlink/reassign modal
        $companyOtherContacts = [];
        try {
            $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE is_active = 1 ORDER BY first_name, last_name");
            $stmt->execute();
            $companyOtherContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* ok */ }

        // Build a contact name lookup for showing property owners
        $companyContactNameMap = [];
        foreach ($companyContacts as $cc) {
            $companyContactNameMap[(int)$cc['id']] = trim(($cc['first_name'] ?? '') . ' ' . ($cc['last_name'] ?? ''));
        }

        // Load Google Maps API for address autocomplete + map display
        $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        if ($apiKey && empty($extraHead)) {
            $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=geometry,places" defer></script>';
        }

        // ── Measurement data for company properties ──
        if (!empty($companyProperties)) {
            $compPropIds = array_column($companyProperties, 'id');
            $cmPlaceholders = implode(',', array_fill(0, count($compPropIds), '?'));

            // Re-fetch properties with measurement summary columns
            try {
                $stmt = $db->prepare("
                    SELECT p.id,
                           COALESCE(p.total_lawn_sqft, 0) AS total_lawn_sqft,
                           COALESCE(p.total_hard_surface_sqft, 0) AS total_hard_surface_sqft,
                           COALESCE(p.total_hedge_linear_ft, 0) AS total_hedge_linear_ft,
                           COALESCE(p.total_other_sqft, 0) AS total_other_sqft,
                           p.measurements_updated_at,
                           (SELECT COUNT(*) FROM property_measurements pm WHERE pm.property_id = p.id) AS measurement_count
                    FROM properties p
                    WHERE p.id IN ({$cmPlaceholders})
                ");
                $stmt->execute(array_values($compPropIds));
                $compMeasurementMap = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $compMeasurementMap[(int)$row['id']] = $row;
                }
            } catch (Exception $e) { $compMeasurementMap = []; }
        }

        // ── Quotes for company's properties ──
        $companyQuotes = [];
        if (!empty($contactIds)) {
            try {
                $stmt = $db->prepare("
                    SELECT q.id, q.quote_number, q.title, q.status,
                           COALESCE(NULLIF(q.total_amount, 0), q.amount, 0) AS total_amount,
                           q.created_at, q.sent_at, q.accepted_at,
                           p.address AS property_address, p.id AS property_id
                    FROM quotes q
                    JOIN properties p ON q.property_id = p.id
                    WHERE p.site_contact_id IN ({$cPlaceholders})
                    ORDER BY q.created_at DESC
                ");
                $stmt->execute(array_values($contactIds));
                $companyQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $companyQuotes = []; }
        }

        // ── Job Plans for company's properties ──
        $companyPlans = [];
        if (!empty($contactIds)) {
            try {
                $stmt = $db->prepare("
                    SELECT jp.id, jp.plan_number, jp.title, jp.service_type, jp.status,
                           jp.is_recurring, jp.recurrence_pattern, jp.price_per_visit,
                           p.address AS property_address, p.id AS property_id,
                           (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id) AS visit_count,
                           (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS completed_count,
                           (SELECT MAX(jv.scheduled_date) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS last_visit_date
                    FROM job_plans jp
                    JOIN properties p ON jp.property_id = p.id
                    WHERE p.site_contact_id IN ({$cPlaceholders})
                    ORDER BY FIELD(jp.status, 'active', 'paused', 'completed', 'cancelled'), jp.created_at DESC
                ");
                $stmt->execute(array_values($contactIds));
                $companyPlans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $companyPlans = []; }
        }
    }
}

// Load Google Maps API for address autocomplete on create form too
if ($action === 'new' && empty($extraHead)) {
    $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
    if ($apiKey) {
        $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=places" defer></script>';
    }
}

// Fetch companies list for link-company dropdown (used in view_contact and new action)
$existingCompaniesForLink = [];
if ($action === 'view_contact' || $action === 'new') {
    $existingCompaniesForLink = $db->query("SELECT id, company_name, company_type FROM companies ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get all clients and prospects
// Prospects are created from quote_requests
$clients = $db->query("
    SELECT
        c.id,
        c.company_name,
        c.company_type,
        c.billing_email,
        c.account_status,
        c.created_at,
        IF(qr.id IS NOT NULL, 'prospect', 'client') as source_type,
        qr.urgency,
        qr.status as qr_status,
        TRIM(CONCAT(COALESCE(pc.first_name, ''), ' ', COALESCE(pc.last_name, ''))) AS primary_contact_name,
        pc.phone as primary_contact_phone
    FROM companies c
    LEFT JOIN quote_requests qr ON c.id = qr.company_id AND qr.status IN ('new', 'reviewing')
    LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get standalone contacts (not linked to any company as primary or billing contact)
try {
    // Check if billing_contact_id column exists
    $cols = $db->query("SHOW COLUMNS FROM companies LIKE 'billing_contact_id'")->fetchAll();
    $hasBillingContact = count($cols) > 0;

    // Check if prospect_status column exists on contacts
    $cols2 = $db->query("SHOW COLUMNS FROM contacts LIKE 'prospect_status'")->fetchAll();
    $hasProspectStatus = count($cols2) > 0;

    $excludeSubquery = "SELECT COALESCE(primary_contact_id, 0) FROM companies";
    if ($hasBillingContact) {
        $excludeSubquery .= " UNION SELECT COALESCE(billing_contact_id, 0) FROM companies";
    }

    $prospectCol = $hasProspectStatus ? "ct.prospect_status" : "'prospect' as prospect_status";

    // Check if stripe_card_last4 column exists
    $stripeCardCols = $db->query("SHOW COLUMNS FROM contacts LIKE 'stripe_card_last4'")->fetchAll();
    $hasStripeCard = count($stripeCardCols) > 0;
    $stripeCardCol = $hasStripeCard ? "ct.stripe_card_last4, ct.stripe_card_brand" : "NULL as stripe_card_last4, NULL as stripe_card_brand";

    $standaloneContacts = $db->query("
        SELECT
            ct.id,
            ct.first_name,
            ct.last_name,
            ct.email,
            ct.phone,
            ct.is_active,
            {$prospectCol},
            ct.created_at,
            ct.notes,
            {$stripeCardCol}
        FROM contacts ct
        WHERE ct.id NOT IN ({$excludeSubquery})
        AND ct.is_active = 1
        ORDER BY ct.last_name, ct.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $standaloneContacts = [];
}

// Detect duplicate contacts
$duplicateMap = [];
try {
    $duplicateMap = findDuplicateContacts();
} catch (Exception $e) {
    // Non-blocking — duplicate detection is a nice-to-have
}

// Prepare grouped duplicate data for the duplicates review view
$duplicateGroups = [];
$dupeContactDetails = [];
$dupeMatchReasons = [];
if (($_GET['view'] ?? '') === 'duplicates' && !empty($duplicateMap)) {
    // Group duplicates into clusters using BFS
    $visited = [];
    foreach ($duplicateMap as $id => $dupeIds) {
        if (isset($visited[$id])) continue;
        $group = [];
        $queue = [(int)$id];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            $group[] = $current;
            foreach ($duplicateMap[$current] ?? [] as $dupeId) {
                if (!isset($visited[(int)$dupeId])) $queue[] = (int)$dupeId;
            }
        }
        if (count($group) > 1) {
            sort($group);
            $duplicateGroups[] = $group;
        }
    }

    // Fetch full details for all contacts in groups
    if (!empty($duplicateGroups)) {
        $allGroupIds = array_unique(array_merge(...$duplicateGroups));
        $ph = implode(',', array_fill(0, count($allGroupIds), '?'));
        $stmt = $db->prepare("
            SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.mobile,
                   c.preferred_contact_method, c.notes, c.created_at,
                   GROUP_CONCAT(DISTINCT p.address SEPARATOR ', ') as property_addresses,
                   COUNT(DISTINCT qr.id) as quote_request_count
            FROM contacts c
            LEFT JOIN properties p ON c.id = p.site_contact_id
            LEFT JOIN quote_requests qr ON c.id = qr.contact_id
            WHERE c.id IN ({$ph})
            GROUP BY c.id
        ");
        $stmt->execute(array_values($allGroupIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dupeContactDetails[(int)$row['id']] = $row;
        }

        // Build address map for match reason detection
        $dupeAddressMap = [];
        $stmt2 = $db->prepare("
            SELECT site_contact_id, LOWER(TRIM(address)) as addr
            FROM properties
            WHERE site_contact_id IN ({$ph})
            AND address IS NOT NULL AND address != ''
        ");
        $stmt2->execute(array_values($allGroupIds));
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $dupeAddressMap[(int)$p['site_contact_id']][] = $p['addr'];
        }

        // Compute match reasons for each group
        foreach ($duplicateGroups as $gi => $groupIds) {
            $reasons = [];
            for ($ii = 0; $ii < count($groupIds); $ii++) {
                for ($jj = $ii + 1; $jj < count($groupIds); $jj++) {
                    $a = $dupeContactDetails[$groupIds[$ii]] ?? [];
                    $b = $dupeContactDetails[$groupIds[$jj]] ?? [];
                    if (empty($a) || empty($b)) continue;
                    if (!empty($a['email']) && !empty($b['email'])
                        && strtolower(trim($a['email'])) === strtolower(trim($b['email']))) {
                        $reasons['email'] = h($a['email']);
                    }
                    $phonesA = array_filter([preg_replace('/\D/', '', $a['phone'] ?? ''), preg_replace('/\D/', '', $a['mobile'] ?? '')], function($v) { return $v !== ''; });
                    $phonesB = array_filter([preg_replace('/\D/', '', $b['phone'] ?? ''), preg_replace('/\D/', '', $b['mobile'] ?? '')], function($v) { return $v !== ''; });
                    foreach ($phonesA as $pa) {
                        foreach ($phonesB as $pb) {
                            if ($pa === $pb) { $reasons['phone'] = h($a['phone'] ?: $a['mobile']); break 2; }
                        }
                    }
                    $addrsA = $dupeAddressMap[$groupIds[$ii]] ?? [];
                    $addrsB = $dupeAddressMap[$groupIds[$jj]] ?? [];
                    foreach ($addrsA as $addrA) {
                        if (in_array($addrA, $addrsB)) { $reasons['address'] = h($addrA); break; }
                    }
                    if (trim($a['last_name'] ?? '') !== '' && trim($b['last_name'] ?? '') !== ''
                        && strtolower(trim($a['first_name'])) === strtolower(trim($b['first_name']))
                        && strtolower(trim($a['last_name'])) === strtolower(trim($b['last_name']))) {
                        $reasons['name'] = h(trim($a['first_name'] . ' ' . $a['last_name']));
                    }
                }
            }
            $dupeMatchReasons[$gi] = $reasons;
        }
    }
}

// Also get quote_requests without companies (not yet converted to prospects)
$unconvertedRequests = $db->query("
    SELECT
        qr.id,
        CONCAT(c.first_name, ' ', c.last_name) as contact_name,
        p.address as property_address,
        p.city,
        qr.urgency,
        qr.status,
        qr.service_types,
        qr.created_at,
        qr.company_id
    FROM quote_requests qr
    LEFT JOIN contacts c ON qr.contact_id = c.id
    LEFT JOIN properties p ON qr.property_id = p.id
    WHERE qr.company_id IS NULL AND qr.status IN ('new', 'reviewing')
    ORDER BY
        CASE qr.urgency WHEN 'asap' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END,
        qr.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- Session Alert Display -->
          <?php if (isset($_SESSION['alert'])):
              $alert = $_SESSION['alert'];
              $alertClass = [
                  'error' => 'alert-danger',
                  'warning' => 'alert-warning',
                  'success' => 'alert-success',
                  'info' => 'alert-info'
              ][$alert['type']] ?? 'alert-info';
          ?>
              <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                  <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['alert']); ?>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0"><?php echo (($_GET['view'] ?? '') === 'duplicates') ? 'Review Duplicate Contacts' : 'Client Management'; ?></h1>
            <?php if (!in_array($action, ['edit', 'new', 'view_contact', 'edit_contact', 'view_company', 'edit_company']) && ($_GET['view'] ?? '') !== 'duplicates'): ?>
              <button class="btn btn-primary" onclick="location.href='?action=new'">
                <i data-feather="plus"></i> Add New Client
              </button>
            <?php endif; ?>
          </div>

          <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
              <?php echo htmlspecialchars($message); ?>
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          <?php endif; ?>

          <?php if ($action === 'edit' || $action === 'new'): ?>
            <!-- New Contact Form -->
            <form method="POST" id="contactForm">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="save_client">

              <div class="row">
                <!-- Left Column: Contact Info + Property -->
                <div class="col-lg-7">
                  <!-- Card 1: Contact Information -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="user"></i> Contact Information</h5>
                    </div>
                    <div class="card-body">
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required
                              value="<?php echo h($_POST['first_name'] ?? ''); ?>"
                              placeholder="e.g. John">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" required
                              value="<?php echo h($_POST['last_name'] ?? ''); ?>"
                              placeholder="e.g. Smith">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email"
                              value="<?php echo h($_POST['email'] ?? ''); ?>"
                              placeholder="john@example.com">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" class="form-control" name="phone"
                              value="<?php echo h($_POST['phone'] ?? ''); ?>"
                              placeholder="604-555-1234">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group mb-0">
                            <label>Cell / Mobile</label>
                            <input type="tel" class="form-control" name="mobile"
                              value="<?php echo h($_POST['mobile'] ?? ''); ?>"
                              placeholder="604-555-5678">
                            <small class="form-text text-muted">Used for SMS notifications</small>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Card 2: Property Address -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="map-pin"></i> Property Address</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Street Address</label>
                        <input type="text" class="form-control" name="property_address" id="propertyAddress"
                          value="<?php echo h($_POST['property_address'] ?? ''); ?>"
                          placeholder="Start typing an address..." autocomplete="off">
                        <input type="hidden" name="property_latitude" id="propertyLatitude" value="<?php echo h($_POST['property_latitude'] ?? ''); ?>">
                        <input type="hidden" name="property_longitude" id="propertyLongitude" value="<?php echo h($_POST['property_longitude'] ?? ''); ?>">
                        <div id="propertyAddressDupeWarning" class="alert alert-warning mt-2" style="display:none; font-size: 0.85rem;"></div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" name="property_city" id="propertyCity"
                              value="<?php echo h($_POST['property_city'] ?? 'Vancouver'); ?>"
                              placeholder="Vancouver">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group mb-0">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" name="property_postal_code" id="propertyPostalCode"
                              value="<?php echo h($_POST['property_postal_code'] ?? ''); ?>"
                              placeholder="V5K 1A1">
                          </div>
                        </div>
                      </div>
                      <small class="form-text text-muted">Links this contact to a service property for scheduling and quoting.</small>
                    </div>
                  </div>
                </div>

                <!-- Right Column: Preferences + Notes + Company -->
                <div class="col-lg-5">
                  <!-- Communication Preferences -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Communication Preferences</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select class="form-control" name="preferred_contact_method">
                          <option value="phone" <?php echo ($_POST['preferred_contact_method'] ?? 'phone') === 'phone' ? 'selected' : ''; ?>>Phone</option>
                          <option value="email" <?php echo ($_POST['preferred_contact_method'] ?? '') === 'email' ? 'selected' : ''; ?>>Email</option>
                          <option value="text" <?php echo ($_POST['preferred_contact_method'] ?? '') === 'text' ? 'selected' : ''; ?>>Text / SMS</option>
                        </select>
                      </div>
                      <div class="mw-comm-prefs" style="grid-template-columns: 1fr;">
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveSms" name="receive_sms"
                            <?php echo isset($_POST['receive_sms']) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveSms">
                            OK to send SMS notifications
                            <small class="d-block text-muted">Job reminders, schedule changes, etc.</small>
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveMarketing" name="receive_marketing"
                            <?php echo isset($_POST['receive_marketing']) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveMarketing">
                            OK to send marketing emails
                            <small class="d-block text-muted">Seasonal offers, newsletters</small>
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="consentQuoteFollowup" name="consent_quote_followup"
                            <?php echo isset($_POST['consent_quote_followup']) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="consentQuoteFollowup">
                            Consent to quote follow-up
                            <small class="d-block text-muted">Allow follow-up after sending a quote</small>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Notes -->
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="file-text"></i> Notes</h5>
                    </div>
                    <div class="card-body">
                      <textarea class="form-control" name="notes" rows="3" placeholder="Any additional info..."><?php echo h($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                  </div>

                  <!-- Company (Optional) -->
                  <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Company</h5>
                      <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="linkCompanyToggle" name="link_company"
                          <?php echo isset($_POST['link_company']) ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="linkCompanyToggle">Link to a company</label>
                      </div>
                    </div>
                    <div class="card-body mw-company-section" id="companySection" style="display: none;">
                      <!-- Company mode: existing or new -->
                      <div class="row mb-3">
                        <div class="col-md-12">
                          <div class="btn-group btn-block" role="group">
                            <input type="radio" class="btn-check" name="company_mode" id="company_mode_existing" value="existing">
                            <label class="btn btn-outline-secondary" for="company_mode_existing">
                              Link to Existing Company
                            </label>
                            <input type="radio" class="btn-check" name="company_mode" id="company_mode_new" value="new" checked>
                            <label class="btn btn-outline-secondary" for="company_mode_new">
                              Create New Company
                            </label>
                          </div>
                        </div>
                      </div>

                      <!-- Existing Company Selection -->
                      <div id="existing-company-section" style="display: none;">
                        <div class="form-group">
                          <label>Select Company</label>
                          <select class="form-control" name="company_id" id="company_id">
                            <option value="">-- Select a company --</option>
                            <?php
                              $existingCompanies = $db->query("SELECT id, company_name, company_type FROM companies ORDER BY company_name")->fetchAll();
                              foreach ($existingCompanies as $comp): ?>
                              <option value="<?php echo (int)$comp['id']; ?>"
                                <?php echo (intval($_POST['company_id'] ?? 0) === (int)$comp['id']) ? 'selected' : ''; ?>>
                                <?php echo h($comp['company_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $comp['company_type'])); ?>)
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>

                      <!-- New Company Information -->
                      <div id="new-company-section">
                        <div class="form-group">
                          <label>Company Name</label>
                          <input type="text" class="form-control" name="company_name"
                            value="<?php echo h($_POST['company_name'] ?? ''); ?>"
                            placeholder="e.g. Smith Landscaping Ltd.">
                        </div>
                        <div class="form-group">
                          <label>Type</label>
                          <select class="form-control" name="company_type">
                            <option value="individual" <?php echo ($_POST['company_type'] ?? 'individual') === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="business" <?php echo ($_POST['company_type'] ?? '') === 'business' ? 'selected' : ''; ?>>Business</option>
                            <option value="strata" <?php echo ($_POST['company_type'] ?? '') === 'strata' ? 'selected' : ''; ?>>Strata</option>
                            <option value="property_manager" <?php echo ($_POST['company_type'] ?? '') === 'property_manager' ? 'selected' : ''; ?>>Property Manager</option>
                          </select>
                        </div>
                      </div>

                      <hr>

                      <!-- Billing / Account info -->
                      <h6 class="mb-3"><strong>Billing &amp; Account</strong></h6>
                      <div class="form-group">
                        <label>Billing Address</label>
                        <input type="text" class="form-control" name="billing_address" id="billingAddress"
                          value="<?php echo h($_POST['billing_address'] ?? ''); ?>"
                          placeholder="Start typing an address..." autocomplete="off">
                      </div>
                      <div class="row">
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" name="billing_city" id="billingCity"
                              value="<?php echo h($_POST['billing_city'] ?? 'Vancouver'); ?>">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Province</label>
                            <input type="text" class="form-control" name="billing_province" id="billingProvince" maxlength="2"
                              value="<?php echo h($_POST['billing_province'] ?? 'BC'); ?>">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" name="billing_postal_code" id="billingPostalCode"
                              value="<?php echo h($_POST['billing_postal_code'] ?? ''); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Account Status</label>
                            <select class="form-control" name="account_status">
                              <option value="active">Active</option>
                              <option value="inactive">Inactive</option>
                              <option value="suspended">Suspended</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Payment Terms</label>
                            <input type="text" class="form-control" name="payment_terms"
                              value="<?php echo h($_POST['payment_terms'] ?? 'Net 30'); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="prefAttachPdf" name="pref_attach_pdf" checked>
                        <label class="custom-control-label" for="prefAttachPdf">Attach PDF to quote emails</label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Submit -->
              <div class="form-group mt-1">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i data-feather="save"></i> Save Contact
                </button>
                <a href="clients_appstack.php" class="btn btn-secondary btn-lg ml-2">
                  <i data-feather="x"></i> Cancel
                </a>
              </div>
            </form>

          <?php elseif ($action === 'edit_contact' && $contact): ?>
            <!-- Edit Contact Form -->
            <form method="POST" id="editContactForm">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="update_contact">
              <input type="hidden" name="contact_id" value="<?php echo (int)$contact['id']; ?>">

              <div class="row">
                <!-- Left Column: Contact Info -->
                <div class="col-lg-7">
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="user"></i> Edit Contact</h5>
                    </div>
                    <div class="card-body">
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required
                              value="<?php echo h($_POST['first_name'] ?? $contact['first_name'] ?? ''); ?>">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" class="form-control" name="last_name"
                              value="<?php echo h($_POST['last_name'] ?? $contact['last_name'] ?? ''); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email"
                              value="<?php echo h($_POST['email'] ?? $contact['email'] ?? ''); ?>">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" class="form-control" name="phone"
                              value="<?php echo h($_POST['phone'] ?? $contact['phone'] ?? ''); ?>">
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group mb-0">
                            <label>Cell / Mobile</label>
                            <input type="tel" class="form-control" name="mobile"
                              value="<?php echo h($_POST['mobile'] ?? $contact['mobile'] ?? ''); ?>">
                            <small class="form-text text-muted">Used for SMS notifications</small>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Right Column: Preferences & Notes -->
                <div class="col-lg-5">
                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Communication Preferences</h5>
                    </div>
                    <div class="card-body">
                      <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select class="form-control" name="preferred_contact_method">
                          <?php $pref = $_POST['preferred_contact_method'] ?? $contact['preferred_contact_method'] ?? 'phone'; ?>
                          <option value="phone" <?php echo $pref === 'phone' ? 'selected' : ''; ?>>Phone</option>
                          <option value="email" <?php echo $pref === 'email' ? 'selected' : ''; ?>>Email</option>
                          <option value="text" <?php echo $pref === 'text' ? 'selected' : ''; ?>>Text / SMS</option>
                        </select>
                      </div>
                      <div class="mw-comm-prefs" style="grid-template-columns: 1fr;">
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveSms" name="receive_sms"
                            <?php echo (!empty($_POST['receive_sms']) || (!isset($_POST['action']) && !empty($contact['receive_sms']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveSms">
                            OK to send SMS notifications
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="receiveMarketing" name="receive_marketing"
                            <?php echo (!empty($_POST['receive_marketing']) || (!isset($_POST['action']) && !empty($contact['receive_marketing']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="receiveMarketing">
                            OK to send marketing emails
                          </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="consentQuoteFollowup" name="consent_quote_followup"
                            <?php echo (!empty($_POST['consent_quote_followup']) || (!isset($_POST['action']) && !empty($contact['consent_quote_followup']))) ? 'checked' : ''; ?>>
                          <label class="custom-control-label" for="consentQuoteFollowup">
                            Consent to quote follow-up
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="card mb-3">
                    <div class="card-header">
                      <h5 class="card-title mb-0"><i data-feather="file-text"></i> Notes</h5>
                    </div>
                    <div class="card-body">
                      <textarea class="form-control" name="notes" rows="3"><?php echo h($_POST['notes'] ?? $contact['notes'] ?? ''); ?></textarea>
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-group mt-1">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i data-feather="save"></i> Update Contact
                </button>
                <a href="clients_appstack.php" class="btn btn-secondary btn-lg ml-2">
                  <i data-feather="x"></i> Cancel
                </a>
              </div>
            </form>

          <?php elseif ($action === 'view_contact' && $viewContact): ?>
            <!-- View Contact Profile -->
            <?php
              $contactName = trim(h($viewContact['first_name'] . ' ' . ($viewContact['last_name'] ?? '')));
              $geocodedCount = 0;
              $ungeocodedCount = 0;
              foreach ($contactProperties as $p) {
                  $lat = floatval($p['latitude'] ?? 0);
                  $lng = floatval($p['longitude'] ?? 0);
                  if ($lat != 0 && $lng != 0) $geocodedCount++;
                  else $ungeocodedCount++;
              }
            ?>

            <!-- Header -->
            <div class="mw-contact-header">
              <div>
                <h3 class="mw-contact-name">
                  <i data-feather="user" style="width: 24px; height: 24px;"></i>
                  <?php echo $contactName; ?>
                </h3>
                <?php
                  $stage = $viewContact['prospect_status'] ?? 'prospect';
                  $stageColors = ['prospect' => '#3B82F6', 'client' => '#2D8659', 'inactive' => '#6B7280'];
                  $stageColor = $stageColors[$stage] ?? '#6B7280';
                ?>
                <span class="badge ml-2" style="background: <?php echo $stageColor; ?>; color: #fff; font-size: 0.75rem;">
                  <?php echo ucfirst(h($stage)); ?>
                </span>
              </div>
              <div class="mw-contact-actions">
                <a href="/customer/portal.php?contact_id=<?php echo (int)$viewContact['id']; ?>" class="btn btn-outline-primary" target="_blank">
                  <i data-feather="external-link"></i> Client Portal
                </a>
                <a href="?action=edit_contact&id=<?php echo (int)$viewContact['id']; ?>" class="btn btn-primary">
                  <i data-feather="edit-2"></i> Edit
                </a>
                <a href="clients_appstack.php" class="btn btn-secondary">
                  <i data-feather="arrow-left"></i> Back
                </a>
              </div>
            </div>

            <div class="row">
              <!-- Left Column -->
              <div class="col-lg-7">

                <!-- Contact Details Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="info"></i> Contact Details</h5>
                  </div>
                  <div class="card-body">
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Name</div>
                      <div class="col-sm-9"><strong><?php echo $contactName; ?></strong></div>
                    </div>
                    <?php if (!empty($viewContact['email'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Email</div>
                      <div class="col-sm-9"><a href="mailto:<?php echo h($viewContact['email']); ?>"><?php echo h($viewContact['email']); ?></a></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($viewContact['phone'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Phone</div>
                      <div class="col-sm-9"><a href="tel:<?php echo h($viewContact['phone']); ?>"><?php echo h($viewContact['phone']); ?></a></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($viewContact['mobile'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Mobile</div>
                      <div class="col-sm-9"><a href="tel:<?php echo h($viewContact['mobile']); ?>"><?php echo h($viewContact['mobile']); ?></a></div>
                    </div>
                    <?php endif; ?>
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Preferred</div>
                      <div class="col-sm-9"><?php echo ucfirst(h($viewContact['preferred_contact_method'] ?? 'phone')); ?></div>
                    </div>

                    <!-- Communication Preferences -->
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Preferences</div>
                      <div class="col-sm-9">
                        <span class="mw-contact-pref-badge <?php echo !empty($viewContact['receive_sms']) ? 'active' : 'inactive'; ?>">
                          <i data-feather="message-square" style="width: 12px; height: 12px;"></i> SMS
                        </span>
                        <span class="mw-contact-pref-badge <?php echo !empty($viewContact['receive_marketing']) ? 'active' : 'inactive'; ?>">
                          <i data-feather="mail" style="width: 12px; height: 12px;"></i> Marketing
                        </span>
                        <span class="mw-contact-pref-badge <?php echo !empty($viewContact['consent_quote_followup']) ? 'active' : 'inactive'; ?>">
                          <i data-feather="check-circle" style="width: 12px; height: 12px;"></i> Follow-up
                        </span>
                      </div>
                    </div>

                    <?php if (!empty($viewContact['stripe_card_last4'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-3 text-muted">Card on File</div>
                      <div class="col-sm-9">
                        <span class="mw-card-on-file-badge">
                          <i data-feather="credit-card"></i>
                          <?php echo h(ucfirst($viewContact['stripe_card_brand'] ?? 'Card')); ?> ••••<?php echo h($viewContact['stripe_card_last4']); ?>
                          <?php if (!empty($viewContact['stripe_card_exp'])): ?>
                            &middot; exp <?php echo h($viewContact['stripe_card_exp']); ?>
                          <?php endif; ?>
                        </span>
                      </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($viewContact['notes'])): ?>
                    <div class="row mb-0">
                      <div class="col-sm-3 text-muted">Notes</div>
                      <div class="col-sm-9"><span class="text-muted"><?php echo nl2br(h($viewContact['notes'])); ?></span></div>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Financial Summary -->
                <div class="mw-client-stats-grid mb-3">
                  <div class="mw-stat-card paid">
                    <h4>Revenue</h4>
                    <div class="value"><?php echo formatCurrency($totalRevenue); ?></div>
                  </div>
                  <div class="mw-stat-card <?php echo $totalOutstanding > 0 ? 'outstanding' : 'sent'; ?>">
                    <h4>Outstanding</h4>
                    <div class="value"><?php echo formatCurrency($totalOutstanding); ?></div>
                  </div>
                  <div class="mw-stat-card sent">
                    <h4>Active Plans</h4>
                    <div class="value"><?php echo $activePlanCount; ?></div>
                  </div>
                  <div class="mw-stat-card paid">
                    <h4>Visits Done</h4>
                    <div class="value"><?php echo $completedVisitCount; ?></div>
                  </div>
                </div>

                <!-- Properties Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="map-pin"></i> Properties
                      <span class="badge badge-primary ml-1"><?php echo count($contactProperties); ?></span>
                    </h5>
                    <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#addPropertyModal">
                      <i data-feather="plus"></i> Add Property
                    </button>
                  </div>
                  <div class="card-body">
                    <?php if (empty($contactProperties)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="map" style="width: 36px; height: 36px;"></i>
                        <p class="mt-2 mb-0">No properties linked yet.</p>
                        <small>Add a property address to enable scheduling and quoting.</small>
                      </div>
                    <?php else: ?>
                      <?php foreach ($contactProperties as $prop):
                          $propTags = $propertyTagMap[(int)$prop['id']] ?? [];
                      ?>
                        <div class="mw-contact-property-item" onclick="focusProperty(<?php echo (int)$prop['id']; ?>)">
                          <div style="flex: 1; min-width: 0;">
                            <div class="mw-contact-property-addr">
                              <i data-feather="home" style="width: 14px; height: 14px;"></i>
                              <?php echo h($prop['address']); ?>
                            </div>
                            <div class="mw-contact-property-meta">
                              <?php echo h($prop['city'] ?? ''); ?><?php echo !empty($prop['province']) ? ', ' . h($prop['province']) : ''; ?> <?php echo h($prop['postal_code'] ?? ''); ?>
                              <?php if (!empty($prop['property_type'])): ?>
                                &middot; <?php echo ucwords(str_replace('_', ' ', $prop['property_type'])); ?>
                              <?php endif; ?>
                            </div>
                            <!-- Property Tags -->
                            <div class="mw-property-tags-row" id="propTags_<?php echo (int)$prop['id']; ?>" onclick="event.stopPropagation();">
                              <?php foreach ($propTags as $pTag): ?>
                                <span class="mw-property-tag" style="--tag-color: <?php echo h($pTag['tag_color']); ?>">
                                  <?php echo h($pTag['has_value'] && !empty($pTag['tag_value']) ? $pTag['tag_label'] . ': ' . $pTag['tag_value'] : $pTag['tag_label']); ?>
                                  <button type="button" class="mw-property-tag-remove" onclick="removePropertyTag(<?php echo (int)$prop['id']; ?>, <?php echo (int)$pTag['entity_tag_id']; ?>, this)" title="Remove tag">&times;</button>
                                </span>
                              <?php endforeach; ?>
                              <button type="button" class="mw-property-tag-add-btn" onclick="showTagPicker(<?php echo (int)$prop['id']; ?>, this)" title="Add tag">
                                <i data-feather="plus" style="width: 10px; height: 10px;"></i>
                              </button>
                            </div>
                            <!-- Measurement Status -->
                            <?php
                              $mCount = (int)($prop['measurement_count'] ?? 0);
                              $lawnSqft = floatval($prop['total_lawn_sqft'] ?? 0);
                              $hardSqft = floatval($prop['total_hard_surface_sqft'] ?? 0);
                              $hedgeFt = floatval($prop['total_hedge_linear_ft'] ?? 0);
                              $otherSqft = floatval($prop['total_other_sqft'] ?? 0);
                              $measuredAt = $prop['measurements_updated_at'] ?? null;
                            ?>
                            <div class="mw-property-measurement-status" onclick="event.stopPropagation();">
                              <?php if ($mCount > 0): ?>
                                <span class="mw-measurement-badge mw-measurement-done" title="<?php echo $mCount; ?> area(s) measured">
                                  <i data-feather="check-circle" style="width: 11px; height: 11px;"></i> Measured
                                </span>
                                <span class="mw-measurement-summary">
                                  <?php
                                    $parts = [];
                                    if ($lawnSqft > 0) $parts[] = number_format($lawnSqft) . ' sq ft lawn';
                                    if ($hardSqft > 0) $parts[] = number_format($hardSqft) . ' sq ft hard';
                                    if ($hedgeFt > 0) $parts[] = number_format($hedgeFt) . ' lin ft hedge';
                                    if ($otherSqft > 0) $parts[] = number_format($otherSqft) . ' sq ft other';
                                    echo h(implode(' · ', $parts) ?: $mCount . ' area(s)');
                                  ?>
                                </span>
                                <?php if ($measuredAt): ?>
                                  <span class="mw-measurement-date"><?php echo h(timeAgo($measuredAt)); ?></span>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="mw-measurement-badge mw-measurement-pending">
                                  <i data-feather="alert-circle" style="width: 11px; height: 11px;"></i> Not measured
                                </span>
                              <?php endif; ?>
                            </div>
                            <!-- Arrival Border Status -->
                            <?php if (floatval($prop['latitude'] ?? 0) != 0 && floatval($prop['longitude'] ?? 0) != 0): ?>
                            <div class="mw-property-geofence-status" onclick="event.stopPropagation();">
                              <?php if ((int)($prop['has_arrival_border'] ?? 0) > 0): ?>
                                <span class="mw-geofence-badge mw-geofence-set">
                                  <i data-feather="shield" style="width: 11px; height: 11px;"></i> Arrival Border Set
                                </span>
                              <?php else: ?>
                                <a href="jobs/zone-editor.php?property_id=<?php echo (int)$prop['id']; ?>&return_to=<?php echo urlencode('clients_appstack.php?action=view_contact&id=' . (int)$clientId); ?>"
                                   class="mw-geofence-badge mw-geofence-missing" title="Draw arrival border for accurate auto-clock-in">
                                  <i data-feather="alert-triangle" style="width: 11px; height: 11px;"></i> No Arrival Border
                                </a>
                              <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <!-- Quick Actions -->
                            <div class="mw-property-quick-actions" onclick="event.stopPropagation();">
                              <a href="quote-workflow.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo (int)$prop['id']; ?>" class="mw-prop-action-btn mw-prop-action-primary" title="Measure, quote & auto-fill pricing">
                                <i data-feather="file-text" style="width: 11px; height: 11px;"></i> Quote &amp; Measure
                              </a>
                              <a href="jobs/create.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo (int)$prop['id']; ?>" class="mw-prop-action-btn" title="Create job plan for this property">
                                <i data-feather="clipboard" style="width: 11px; height: 11px;"></i> Create Plan
                              </a>
                              <button type="button" class="mw-prop-action-btn mw-prop-action-geofence" title="Draw work zones for time tracking" onclick="openWorkZoneModal(<?php echo (int)$prop['id']; ?>, <?php echo floatval($prop['latitude'] ?? 0); ?>, <?php echo floatval($prop['longitude'] ?? 0); ?>)">
                                <i data-feather="map-pin" style="width: 11px; height: 11px;"></i> Work Zone
                              </button>
                            </div>
                          </div>
                          <div class="d-flex align-items-center" onclick="event.stopPropagation();">
                            <?php if (floatval($prop['latitude'] ?? 0) == 0 || floatval($prop['longitude'] ?? 0) == 0): ?>
                              <button type="button" class="btn btn-sm btn-outline-secondary mr-1" onclick="geocodeProperty(<?php echo (int)$prop['id']; ?>, this)" title="Geocode this address">
                                <i data-feather="crosshair" style="width: 12px; height: 12px;"></i>
                              </button>
                            <?php else: ?>
                              <span class="text-success mr-1" title="Geocoded"><i data-feather="check-circle" style="width: 14px; height: 14px;"></i></span>
                            <?php endif; ?>
                            <button type="button" class="mw-property-unlink-btn" onclick="showUnlinkProperty(<?php echo (int)$prop['id']; ?>, '<?php echo addslashes(h($prop['address'])); ?>')" title="Remove or reassign this property">
                              <i data-feather="x-circle" style="width: 14px; height: 14px;"></i>
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Quotes Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="file-text"></i> Quotes
                      <?php if (!empty($contactQuotes)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($contactQuotes); ?></span>
                      <?php endif; ?>
                    </h5>
                    <?php if (!empty($contactProperties)): ?>
                      <a href="quote-workflow.php?contact_id=<?php echo (int)$clientId; ?>&property_id=<?php echo (int)$contactProperties[0]['id']; ?>" class="btn btn-sm btn-success">
                        <i data-feather="plus"></i> New Quote
                      </a>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($contactQuotes)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="file-text" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No quotes yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Quote</th><th>Status</th><th class="text-right">Amount</th><th>Date</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($contactQuotes as $q): ?>
                              <tr onclick="window.location='quotes/view.php?id=<?php echo (int)$q['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($q['quote_number']); ?></strong>
                                  <?php if (!empty($q['title'])): ?>
                                    <br><small class="text-muted"><?php echo h($q['title']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($q['status'], 'quote'); ?></td>
                                <td class="text-right"><?php echo formatCurrency($q['total_amount'] ?? 0); ?></td>
                                <td class="text-muted small"><?php echo formatDate($q['created_at']); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Job Plans Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="clipboard"></i> Job Plans
                      <?php if (!empty($contactPlans)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($contactPlans); ?></span>
                      <?php endif; ?>
                    </h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($contactPlans)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="clipboard" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No job plans yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Plan</th><th>Status</th><th>Visits</th><th class="text-right">$/Visit</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($contactPlans as $pl): ?>
                              <tr onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($pl['plan_number']); ?></strong>
                                  <br><small class="text-muted">
                                    <?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?>
                                    <?php if ($pl['is_recurring']): ?>
                                      &middot; <?php echo ucfirst($pl['recurrence_pattern'] ?? 'recurring'); ?>
                                    <?php endif; ?>
                                  </small>
                                </td>
                                <td><?php echo getStatusBadge($pl['status'], 'plan'); ?></td>
                                <td>
                                  <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                    <?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?>
                                  </span>
                                  <?php if (!empty($pl['last_visit_date'])): ?>
                                    <br><small class="text-muted">Last: <?php echo formatDate($pl['last_visit_date']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td class="text-right"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Recent Visits Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="calendar"></i> Recent Visits</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($contactVisits)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="calendar" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No visits scheduled yet</p>
                      </div>
                    <?php else: ?>
                      <div class="mw-client-visit-list">
                        <?php foreach ($contactVisits as $v): ?>
                          <a href="jobs/view.php?id=<?php echo (int)$v['plan_id']; ?>" class="mw-client-visit-item">
                            <div class="mw-client-visit-date">
                              <div class="mw-client-visit-day"><?php echo date('j', strtotime($v['scheduled_date'])); ?></div>
                              <div class="mw-client-visit-month"><?php echo date('M', strtotime($v['scheduled_date'])); ?></div>
                            </div>
                            <div class="mw-client-visit-info">
                              <div><?php echo h(ucwords(str_replace('_', ' ', $v['service_type'] ?? $v['plan_title'] ?? ''))); ?></div>
                              <small class="text-muted"><?php echo h($v['property_address'] ?? ''); ?></small>
                            </div>
                            <div class="mw-client-visit-status">
                              <?php echo getStatusBadge($v['status'], 'visit'); ?>
                              <?php if (floatval($v['actual_amount'] ?? 0) > 0): ?>
                                <div class="small text-muted mt-1"><?php echo formatCurrency($v['actual_amount']); ?></div>
                              <?php endif; ?>
                            </div>
                          </a>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Invoices Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="dollar-sign"></i> Invoices
                      <?php if (!empty($contactInvoices)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($contactInvoices); ?></span>
                      <?php endif; ?>
                    </h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($contactInvoices)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="dollar-sign" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No invoices yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Invoice</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Balance</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($contactInvoices as $inv): ?>
                              <tr onclick="window.location='invoices/view.php?id=<?php echo (int)$inv['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($inv['invoice_number']); ?></strong>
                                  <?php if (!empty($inv['issue_date'])): ?>
                                    <br><small class="text-muted"><?php echo formatDate($inv['issue_date']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($inv['status'], 'invoice'); ?></td>
                                <td class="text-right"><?php echo formatCurrency($inv['total'] ?? 0); ?></td>
                                <td class="text-right"><?php echo formatCurrency($inv['amount_paid'] ?? 0); ?></td>
                                <td class="text-right <?php echo floatval($inv['balance_due'] ?? 0) > 0 ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                  <?php echo formatCurrency($inv['balance_due'] ?? 0); ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Billing Statement Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="book-open"></i> Billing Statement
                      <?php if ($statementBalance > 0.005): ?>
                        <span class="badge badge-danger ml-1"><?php echo formatCurrency($statementBalance); ?> due</span>
                      <?php elseif (!empty($statementLedger)): ?>
                        <span class="badge badge-success ml-1">Paid in full</span>
                      <?php endif; ?>
                    </h5>
                    <?php if (!empty($statementLedger)): ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printStatement(<?php echo (int)$clientId; ?>)">
                        <i data-feather="printer"></i> Print
                      </button>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($statementLedger)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="book-open" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No billing history yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm mb-0 mw-statement-table">
                          <thead>
                            <tr>
                              <th style="width:110px">Date</th>
                              <th>Description</th>
                              <th class="text-right" style="width:100px">Charge</th>
                              <th class="text-right" style="width:100px">Payment</th>
                              <th class="text-right" style="width:100px">Balance</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($statementLedger as $row): ?>
                              <tr class="mw-statement-row mw-statement-<?php echo $row['type']; ?>">
                                <td class="text-muted small text-nowrap">
                                  <?php echo $row['date'] ? date('M j, Y', strtotime($row['date'])) : '—'; ?>
                                </td>
                                <td>
                                  <?php if ($row['type'] === 'charge'): ?>
                                    <a href="<?php echo h($row['link']); ?>">
                                      <strong><?php echo h($row['desc']); ?></strong>
                                    </a>
                                    <?php if (!empty($row['status'])): ?>
                                      &nbsp;<?php echo getStatusBadge($row['status'], 'invoice'); ?>
                                    <?php endif; ?>
                                  <?php else: ?>
                                    <span class="mw-statement-payment-label">
                                      <i data-feather="check-circle" style="width:12px;height:12px;color:var(--mw-green);"></i>
                                      <?php echo h($row['desc']); ?>
                                    </span>
                                    <?php if (!empty($row['receipt'])): ?>
                                      <a href="<?php echo h($row['receipt']); ?>" target="_blank" class="ml-1 small text-muted" title="Stripe receipt">
                                        <i data-feather="external-link" style="width:11px;height:11px;"></i>
                                      </a>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                </td>
                                <td class="text-right">
                                  <?php echo $row['charge'] > 0 ? formatCurrency($row['charge']) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td class="text-right text-success">
                                  <?php echo $row['credit'] > 0 ? formatCurrency($row['credit']) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td class="text-right <?php echo $row['balance'] > 0.005 ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                  <?php echo formatCurrency($row['balance']); ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                          <tfoot class="mw-statement-totals">
                            <tr>
                              <td colspan="2"><strong>Totals</strong></td>
                              <td class="text-right"><strong><?php echo formatCurrency($statementTotalCharged); ?></strong></td>
                              <td class="text-right text-success"><strong><?php echo formatCurrency($statementTotalPaid); ?></strong></td>
                              <td class="text-right <?php echo $statementBalance > 0.005 ? 'text-danger' : 'text-success'; ?>">
                                <strong><?php echo formatCurrency($statementBalance); ?></strong>
                              </td>
                            </tr>
                          </tfoot>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Client Notes Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Notes</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('addNoteForm').style.display = document.getElementById('addNoteForm').style.display === 'none' ? 'block' : 'none'">
                      <i data-feather="plus"></i> Add Note
                    </button>
                  </div>
                  <div class="card-body">
                    <!-- Add Note Form (hidden by default) -->
                    <div id="addNoteForm" style="display: none;" class="mb-3 p-3 bg-light rounded">
                      <div class="form-group mb-2">
                        <select class="form-control form-control-sm" id="noteType">
                          <option value="general">General</option>
                          <option value="customer_request">Customer Request</option>
                          <option value="issue">Issue</option>
                          <option value="follow_up">Follow-up</option>
                          <option value="internal">Internal</option>
                        </select>
                      </div>
                      <div class="form-group mb-2">
                        <textarea class="form-control form-control-sm" id="noteContent" rows="3" placeholder="Write a note..."></textarea>
                      </div>
                      <button type="button" class="btn btn-sm btn-primary" onclick="addClientNote()">Save Note</button>
                    </div>
                    <?php if (empty($contactNotes)): ?>
                      <p class="text-muted small mb-0">No notes yet.</p>
                    <?php else: ?>
                      <?php foreach ($contactNotes as $note): ?>
                        <div class="mw-note-item mb-2">
                          <div class="mw-note-header">
                            <span class="mw-note-type mw-note-type-<?php echo h(str_replace('_', '-', $note['note_type'] ?? 'general')); ?>">
                              <?php echo ucfirst(str_replace('_', ' ', $note['note_type'] ?? 'general')); ?>
                            </span>
                            <span class="text-muted small">
                              <?php echo h($note['created_by_name'] ?? $note['full_name'] ?? 'System'); ?>
                              &mdash; <?php echo timeAgo($note['created_at']); ?>
                            </span>
                          </div>
                          <div class="mw-note-content"><?php echo nl2br(h($note['content'])); ?></div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Company Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Company</h5>
                  </div>
                  <div class="card-body">
                    <?php if ($contactCompany): ?>
                      <div class="mw-contact-company-link">
                        <div>
                          <div class="mw-contact-company-name">
                            <a href="?action=view_company&id=<?php echo (int)$contactCompany['id']; ?>">
                              <?php echo h($contactCompany['company_name']); ?>
                            </a>
                          </div>
                          <small class="text-muted">
                            <?php echo ucwords(str_replace('_', ' ', $contactCompany['company_type'] ?? '')); ?>
                            <?php if (!empty($contactCompany['billing_email'])): ?>
                              &middot; <?php echo h($contactCompany['billing_email']); ?>
                            <?php endif; ?>
                            &middot; <?php echo ucfirst($contactCompany['account_status'] ?? 'active'); ?>
                          </small>
                        </div>
                      </div>
                    <?php else: ?>
                      <div class="d-flex align-items-center gap-2">
                        <select class="form-control form-control-sm mr-2" id="linkCompanySelect" style="max-width: 300px;">
                          <option value="">-- Select a company --</option>
                          <?php foreach ($existingCompaniesForLink as $comp): ?>
                            <option value="<?php echo (int)$comp['id']; ?>">
                              <?php echo h($comp['company_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $comp['company_type'])); ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary" onclick="linkCompanyToContact()">
                          <i data-feather="link"></i> Link
                        </button>
                      </div>
                      <?php if (empty($existingCompaniesForLink)): ?>
                        <small class="text-muted mt-2 d-block">No companies exist yet. Create one from the client list first.</small>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Recent Photos Card -->
                <?php if (!empty($recentPhotos)): ?>
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="camera"></i> Recent Photos
                      <span class="badge badge-primary ml-1"><?php echo count($recentPhotos); ?></span>
                    </h5>
                    <?php
                    $timelineLink = '/crm/photos_appstack.php?contact_id=' . (int)$clientId;
                    if (count($propIds) === 1) {
                        $timelineLink = '/crm/photos_appstack.php?property_id=' . (int)$propIds[0];
                    }
                    ?>
                    <a href="<?php echo $timelineLink; ?>" class="btn btn-sm btn-outline-success">
                      View All <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
                    </a>
                  </div>
                  <div class="card-body p-2">
                    <div class="mw-recent-photos-grid">
                      <?php foreach ($recentPhotos as $rp):
                          $origUrl = '/uploads/photos/' . $rp['filename'];
                          $thumbUrl = $rp['thumb_path'] ?? $rp['grid_path'] ?? $origUrl;
                          $viewUrl = $rp['view_path'] ?? $origUrl;
                      ?>
                        <a href="<?php echo htmlspecialchars($viewUrl); ?>" target="_blank" class="mw-recent-photo" title="<?php echo htmlspecialchars(ucfirst($rp['photo_type']) . ' — ' . date('M j, Y', strtotime($rp['uploaded_at']))); ?>">
                          <img src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="<?php echo htmlspecialchars($rp['photo_type']); ?> photo" loading="lazy">
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

              </div><!-- end left col -->

              <!-- Right Column: Map + Route Intelligence -->
              <div class="col-lg-5">
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i data-feather="map"></i> Neighbourhood Map</h5>
                    <?php if (!empty($nearbyClients)): ?>
                      <span class="badge badge-pill" style="background: var(--mw-light); color: var(--mw-green); font-weight: 600;"><?php echo count($nearbyClients); ?> nearby</span>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if (!empty($contactProperties) && $geocodedCount > 0): ?>
                      <div id="contactMapContainer" class="mw-contact-map-container" style="min-height: 340px;"></div>
                      <?php if (!empty($nearbyClients)): ?>
                        <div class="mw-map-legend">
                          <span class="mw-map-legend-item"><span class="mw-map-legend-dot" style="background: #E53E3E;"></span>This client</span>
                          <span class="mw-map-legend-item"><span class="mw-map-legend-dot" style="background: var(--mw-green);"></span>Active client</span>
                        </div>
                      <?php endif; ?>
                      <?php if ($ungeocodedCount > 0): ?>
                        <div class="p-2 text-center">
                          <small class="text-muted"><?php echo $ungeocodedCount; ?> propert<?php echo $ungeocodedCount === 1 ? 'y needs' : 'ies need'; ?> geocoding</small>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="mw-contact-map-empty">
                        <i data-feather="map-pin" style="width: 36px; height: 36px;"></i>
                        <?php if (empty($contactProperties)): ?>
                          <p class="mb-0">Add a property to see it on the map</p>
                        <?php else: ?>
                          <p class="mb-0">Properties need geocoding to show on the map</p>
                          <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="geocodeAllProperties()">
                            <i data-feather="crosshair"></i> Geocode All
                          </button>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Route Intelligence Card -->
                <?php if (!empty($nearbyClients)): ?>
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="navigation"></i> Route Intelligence</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($smartestDay): ?>
                    <div class="mw-smartest-day">
                      <div class="mw-smartest-day-icon">
                        <i data-feather="calendar"></i>
                      </div>
                      <div class="mw-smartest-day-info">
                        <div class="mw-smartest-day-label">Best day to service</div>
                        <div class="mw-smartest-day-value"><?php echo h($smartestDay['day_name']); ?></div>
                        <div class="mw-smartest-day-detail">
                          Next: <?php echo date('M j', strtotime($smartestDay['next_date'])); ?>
                          &middot; <?php echo $smartestDay['visit_count']; ?> nearby visit<?php echo $smartestDay['visit_count'] !== 1 ? 's' : ''; ?> scheduled
                          <?php if ($smartestDay['recur_plans'] > 0): ?>
                            &middot; <?php echo $smartestDay['recur_plans']; ?> recurring plan<?php echo $smartestDay['recur_plans'] !== 1 ? 's' : ''; ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                    <?php if (count($dayBreakdown) > 1): ?>
                    <div class="mw-day-breakdown">
                      <?php foreach (array_slice($dayBreakdown, 0, 5) as $di => $day):
                        $barWidth = $dayBreakdown[0]['score'] > 0 ? round(($day['score'] / $dayBreakdown[0]['score']) * 100) : 0;
                        $isTop = ($di === 0);
                      ?>
                        <div class="mw-day-breakdown-row<?php echo $isTop ? ' mw-day-top' : ''; ?>">
                          <span class="mw-day-breakdown-name"><?php echo substr($day['day_name'], 0, 3); ?></span>
                          <div class="mw-day-breakdown-bar-bg">
                            <div class="mw-day-breakdown-bar" style="width: <?php echo $barWidth; ?>%;<?php echo $isTop ? ' background: var(--mw-green);' : ''; ?>"></div>
                          </div>
                          <span class="mw-day-breakdown-count"><?php echo $day['visit_count']; ?> visit<?php echo $day['visit_count'] !== 1 ? 's' : ''; ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="mw-route-intel-summary">
                      <?php
                        $closestClient = $nearbyClients[0] ?? null;
                        $within2km = count(array_filter($nearbyClients, function($n) { return $n['distance_km'] <= 2; }));
                        $within5km = count(array_filter($nearbyClients, function($n) { return $n['distance_km'] <= 5; }));
                        $avgDistance = count($nearbyClients) > 0 ? round(array_sum(array_column($nearbyClients, 'distance_km')) / count($nearbyClients), 1) : 0;
                        $densityScore = $within2km >= 5 ? 'High' : ($within2km >= 2 ? 'Medium' : 'Low');
                        $densityColor = $within2km >= 5 ? 'var(--mw-green)' : ($within2km >= 2 ? '#F59E0B' : '#DC2626');
                      ?>
                      <div class="mw-route-intel-stats">
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value"><?php echo $within2km; ?></span>
                          <span class="mw-route-intel-stat-label">Within 2 km</span>
                        </div>
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value"><?php echo $within5km; ?></span>
                          <span class="mw-route-intel-stat-label">Within 5 km</span>
                        </div>
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value"><?php echo $avgDistance; ?><small>km</small></span>
                          <span class="mw-route-intel-stat-label">Avg distance</span>
                        </div>
                        <div class="mw-route-intel-stat">
                          <span class="mw-route-intel-stat-value" style="color: <?php echo $densityColor; ?>;"><?php echo $densityScore; ?></span>
                          <span class="mw-route-intel-stat-label">Route density</span>
                        </div>
                      </div>
                    </div>
                    <div class="mw-route-intel-list">
                      <?php foreach ($nearbyClients as $i => $nc):
                        $distColor = $nc['distance_km'] <= 1 ? 'var(--mw-green)' : ($nc['distance_km'] <= 3 ? '#F59E0B' : '#9CA3AF');
                        $serviceIcons = [];
                        $svcTypes = array_map('trim', explode(',', $nc['service_types'] ?? ''));
                        foreach ($svcTypes as $svc) {
                            switch ($svc) {
                                case 'lawn_care': $serviceIcons[] = ['icon' => 'scissors', 'label' => 'Lawn']; break;
                                case 'hedge_trimming': $serviceIcons[] = ['icon' => 'git-branch', 'label' => 'Hedge']; break;
                                case 'garden_care': $serviceIcons[] = ['icon' => 'sun', 'label' => 'Garden']; break;
                                case 'snow_removal': $serviceIcons[] = ['icon' => 'cloud-snow', 'label' => 'Snow']; break;
                                case 'pressure_washing': $serviceIcons[] = ['icon' => 'droplet', 'label' => 'Pressure']; break;
                                case 'junk_removal': $serviceIcons[] = ['icon' => 'trash-2', 'label' => 'Junk']; break;
                                default: if ($svc) $serviceIcons[] = ['icon' => 'tool', 'label' => ucfirst(str_replace('_', ' ', $svc))]; break;
                            }
                        }
                      ?>
                        <a href="?action=view_contact&id=<?php echo (int)$nc['contact_id']; ?>" class="mw-route-intel-item" data-nearby-idx="<?php echo $i; ?>">
                          <div class="mw-route-intel-distance" style="color: <?php echo $distColor; ?>;">
                            <?php echo $nc['distance_km']; ?><small>km</small>
                          </div>
                          <div class="mw-route-intel-info">
                            <div class="mw-route-intel-name">
                              <?php echo h($nc['first_name'] . ' ' . $nc['last_name']); ?>
                              <span class="mw-route-intel-plans"><?php echo (int)$nc['active_plan_count']; ?> plan<?php echo (int)$nc['active_plan_count'] !== 1 ? 's' : ''; ?></span>
                            </div>
                            <div class="mw-route-intel-address"><?php echo h($nc['address'] . ', ' . $nc['city']); ?></div>
                            <div class="mw-route-intel-services">
                              <?php foreach ($serviceIcons as $si): ?>
                                <span class="mw-route-intel-svc" title="<?php echo h($si['label']); ?>"><i data-feather="<?php echo h($si['icon']); ?>"></i></span>
                              <?php endforeach; ?>
                              <?php if ($nc['completed_visits'] > 0): ?>
                                <span class="mw-route-intel-visits"><?php echo (int)$nc['completed_visits']; ?> visits done</span>
                              <?php endif; ?>
                            </div>
                          </div>
                          <div class="mw-route-intel-revenue">
                            <?php echo formatCurrency(floatval($nc['total_per_visit'] ?? 0)); ?><small>/visit</small>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Measurements Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="maximize-2"></i> Measurements</h5>
                  </div>
                  <div class="card-body py-2">
                    <?php
                      $hasAnyMeasurements = false;
                      foreach ($contactProperties as $mp) {
                          if ((int)($mp['measurement_count'] ?? 0) > 0) { $hasAnyMeasurements = true; break; }
                      }
                    ?>
                    <?php if (!$hasAnyMeasurements): ?>
                      <div class="text-center py-3">
                        <i data-feather="maximize-2" style="width: 28px; height: 28px; color: #ccc;"></i>
                        <p class="mb-0 mt-2 small text-muted">No properties measured yet.</p>
                        <p class="mb-0 small text-muted">Use "Quote &amp; Measure" on a property to start.</p>
                      </div>
                    <?php else: ?>
                      <?php foreach ($contactProperties as $mp):
                        $mAreas = $propertyMeasurements[(int)$mp['id']] ?? [];
                        $mCount = (int)($mp['measurement_count'] ?? 0);
                        if ($mCount === 0) continue;
                        $mLawn = floatval($mp['total_lawn_sqft'] ?? 0);
                        $mHard = floatval($mp['total_hard_surface_sqft'] ?? 0);
                        $mHedge = floatval($mp['total_hedge_linear_ft'] ?? 0);
                        $mOther = floatval($mp['total_other_sqft'] ?? 0);
                        $mDate = $mp['measurements_updated_at'] ?? null;
                      ?>
                        <div class="mw-measurements-property">
                          <div class="mw-measurements-property-header">
                            <span class="mw-measurements-property-addr"><?php echo h($mp['address']); ?></span>
                            <?php if ($mDate): ?>
                              <span class="mw-measurements-date"><?php echo h(timeAgo($mDate)); ?></span>
                            <?php endif; ?>
                          </div>
                          <?php
                            // Group areas by type
                            $grouped = [];
                            foreach ($mAreas as $area) {
                                $gKey = $area['measurement_group_key'] ?: 'other';
                                $grouped[$gKey][] = $area;
                            }
                            $groupLabels = ['lawn_area' => 'Lawn', 'hard_surface' => 'Hard Surface', 'hedge_linear' => 'Hedge', 'other_area' => 'Other'];
                          ?>
                          <?php foreach ($grouped as $gKey => $areas): ?>
                            <div class="mw-measurements-group">
                              <div class="mw-measurements-group-label"><?php echo h($groupLabels[$gKey] ?? ucfirst($gKey)); ?></div>
                              <?php foreach ($areas as $area): ?>
                                <div class="mw-measurements-area">
                                  <span class="mw-measurements-area-name"><?php echo h($area['measurement_name']); ?></span>
                                  <span class="mw-measurements-area-value">
                                    <?php if ($area['area_sqft'] > 0): ?>
                                      <?php echo number_format($area['area_sqft']); ?> sq ft
                                    <?php elseif ($area['linear_ft'] > 0): ?>
                                      <?php echo number_format($area['linear_ft']); ?> lin ft
                                    <?php endif; ?>
                                  </span>
                                </div>
                              <?php endforeach; ?>
                              <div class="mw-measurements-group-total">
                                Total:
                                <?php
                                  $gTotal = 0;
                                  $gUnit = 'sq ft';
                                  foreach ($areas as $a) {
                                      if ($a['linear_ft'] > 0) { $gTotal += $a['linear_ft']; $gUnit = 'lin ft'; }
                                      else { $gTotal += $a['area_sqft']; }
                                  }
                                  echo number_format($gTotal) . ' ' . $gUnit;
                                ?>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Data Health Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="activity"></i> Data Health</h5>
                  </div>
                  <div class="card-body py-2">
                    <?php if (empty($dataHealth)): ?>
                      <div class="text-center py-3">
                        <i data-feather="check-circle" style="width: 28px; height: 28px; color: var(--mw-green);"></i>
                        <p class="mb-0 mt-2 small text-success font-weight-bold">All good! Profile is complete.</p>
                      </div>
                    <?php else: ?>
                      <?php foreach ($dataHealth as $dh): ?>
                        <div class="mw-data-health-item mw-data-health-<?php echo $dh['level']; ?>">
                          <i data-feather="<?php echo h($dh['icon']); ?>"></i>
                          <span><?php echo h($dh['text']); ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Profitability Card -->
                <?php if ($totalRevenue > 0 || $totalExpenses > 0):
                  $cProfit  = $totalRevenue - $totalExpenses;
                  $cMargin  = $totalRevenue > 0 ? ($cProfit / $totalRevenue) * 100 : 0;
                  $cProfColor = $cProfit >= 0 ? '#2D8659' : '#DC2626';
                  $cSectors = [['label'=>'Expenses','amount'=>max(0,$totalExpenses),'color'=>'#F59E0B']];
                  if ($cProfit > 0) $cSectors[] = ['label'=>'Profit','amount'=>$cProfit,'color'=>'#2D8659'];
                  $cTotal = array_sum(array_column($cSectors,'amount')); if($cTotal<=0)$cTotal=1;
                  $cAngle = -90.0; $cCx=75; $cCy=75; $cIn=33; $cOut=58;
                ?>
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="trending-up"></i> Profitability</h5>
                  </div>
                  <div class="card-body py-2">
                    <!-- Mini donut chart -->
                    <svg viewBox="0 0 150 150" class="mw-radial-svg" style="max-width:150px;">
                      <circle cx="75" cy="75" r="58" fill="none" stroke="#F3F4F6" stroke-width="25"/>
                      <?php foreach ($cSectors as $ci => $cSec):
                        $cPct=$cSec['amount']/$cTotal; $cSwp=$cPct*360; $cEnd=$cAngle+$cSwp;
                        $cA1=deg2rad($cAngle); $cA2=deg2rad($cEnd); $cLg=$cSwp>180?1:0;
                        $cx1=$cCx+$cIn*cos($cA1);  $cy1=$cCy+$cIn*sin($cA1);
                        $cx2=$cCx+$cOut*cos($cA1); $cy2=$cCy+$cOut*sin($cA1);
                        $cx3=$cCx+$cOut*cos($cA2); $cy3=$cCy+$cOut*sin($cA2);
                        $cx4=$cCx+$cIn*cos($cA2);  $cy4=$cCy+$cIn*sin($cA2);
                        $cPath=sprintf("M%.2f,%.2f L%.2f,%.2f A%d,%d 0 %d,1 %.2f,%.2f L%.2f,%.2f A%d,%d 0 %d,0 %.2f,%.2f Z",
                          $cx1,$cy1,$cx2,$cy2,$cOut,$cOut,$cLg,$cx3,$cy3,$cx4,$cy4,$cIn,$cIn,$cLg,$cx1,$cy1);
                        $cAngle=$cEnd;
                      ?>
                      <path d="<?php echo $cPath; ?>" fill="<?php echo $cSec['color']; ?>" stroke="#fff" stroke-width="2"
                            class="mw-radial-sector" style="animation-delay:<?php echo number_format($ci*0.15,2); ?>s"/>
                      <?php endforeach; ?>
                      <circle cx="75" cy="75" r="31" fill="white"/>
                      <text x="75" y="68" font-size="5.5" fill="#9CA3AF" text-anchor="middle" letter-spacing="0.5">NET PROFIT</text>
                      <text x="75" y="81" font-size="11" font-weight="800" fill="<?php echo $cProfColor; ?>" text-anchor="middle"><?php echo formatCurrency($cProfit); ?></text>
                      <text x="75" y="93" font-size="8" font-weight="700" fill="<?php echo $cProfColor; ?>" text-anchor="middle"><?php echo number_format($cMargin,0); ?>%</text>
                    </svg>
                    <!-- Legend + rows -->
                    <div class="mw-radial-legend mb-2">
                      <div class="mw-radial-legend-item"><span class="mw-radial-dot" style="background:#F59E0B"></span><span>Expenses</span></div>
                      <div class="mw-radial-legend-item"><span class="mw-radial-dot" style="background:#2D8659"></span><span>Profit</span></div>
                    </div>
                    <div class="mw-detail-row">
                      <span class="mw-detail-label">Revenue</span>
                      <span class="font-weight-bold text-success"><?php echo formatCurrency($totalRevenue); ?></span>
                    </div>
                    <div class="mw-detail-row">
                      <span class="mw-detail-label">Expenses</span>
                      <span class="font-weight-bold text-danger"><?php echo formatCurrency($totalExpenses); ?></span>
                    </div>
                    <div class="mw-detail-row" style="border-top: 2px solid #dee2e6;">
                      <span class="mw-detail-label font-weight-bold">Profit</span>
                      <span class="font-weight-bold <?php echo $cProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($cProfit); ?>
                        <small class="text-muted">(<?php echo number_format($cMargin, 0); ?>%)</small>
                      </span>
                    </div>
                    <?php if ($totalQuoted > 0 && $totalQuoted != $totalRevenue): ?>
                    <div class="mw-detail-row">
                      <span class="mw-detail-label">Quoted (Accepted)</span>
                      <span class="text-muted"><?php echo formatCurrency($totalQuoted); ?></span>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Activity Timeline Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="clock"></i> Activity</h5>
                  </div>
                  <div class="card-body py-2">
                    <?php if (empty($contactActivity)): ?>
                      <p class="text-muted small mb-0 text-center py-3">No activity recorded yet.</p>
                    <?php else: ?>
                      <ul class="mw-activity-list">
                        <?php foreach ($contactActivity as $act): ?>
                          <li class="mw-activity-item">
                            <div><?php echo h($act['action']); ?></div>
                            <?php if (!empty($act['details'])): ?>
                              <div class="small text-muted"><?php echo h($act['details']); ?></div>
                            <?php endif; ?>
                            <div class="mw-activity-time">
                              <?php echo h($act['full_name'] ?? 'System'); ?> &mdash; <?php echo timeAgo($act['created_at']); ?>
                            </div>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                </div>

              </div><!-- end right col -->
            </div><!-- end row -->

            <!-- Add Property Modal -->
            <div class="modal fade" id="addPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: var(--mw-green); color: #fff;">
                    <h5 class="modal-title"><i data-feather="plus-circle" style="width: 18px; height: 18px;"></i> Add Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <form onsubmit="addPropertyToContact(event)">
                    <div class="modal-body">
                      <div class="form-group">
                        <label>Street Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="propAddress" required placeholder="Start typing an address..." autocomplete="off">
                        <div id="propAddressDupeWarning" class="alert alert-warning mt-2" style="display:none; font-size: 0.85rem;"></div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" id="propCity" value="Vancouver">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" id="propPostalCode" placeholder="V5K 1A1">
                          </div>
                        </div>
                      </div>
                      <div class="form-group mb-0">
                        <label>Property Name <small class="text-muted">(optional, auto-generated if blank)</small></label>
                        <input type="text" class="form-control" id="propName" placeholder="e.g. Main Residence">
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-success" id="addPropBtn">
                        <i data-feather="plus"></i> Add Property
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Arrival Border Prompt Modal -->
            <div class="modal fade" id="arrivalBorderPromptModal" tabindex="-1" role="dialog">
              <div class="modal-dialog modal-sm" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: #f59e0b; color: #fff;">
                    <h5 class="modal-title"><i data-feather="shield" style="width: 18px; height: 18px;"></i> Draw Arrival Border?</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                  </div>
                  <div class="modal-body">
                    <p class="mb-2">Property added and geocoded successfully.</p>
                    <p class="mb-0 small text-muted">Drawing an arrival border prevents wrong auto-clock-ins on dense routes. You can always draw one later from the property card.</p>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="skipArrivalBorder()">Skip for Now</button>
                    <a href="#" id="drawBorderNowBtn" class="btn btn-warning btn-sm"><i data-feather="edit-3" style="width:14px;height:14px;"></i> Draw Border Now</a>
                  </div>
                </div>
              </div>
            </div>

            <!-- Unlink/Reassign Property Modal -->
            <div class="modal fade" id="unlinkPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: #dc3545; color: #fff;">
                    <h5 class="modal-title"><i data-feather="x-circle" style="width: 18px; height: 18px;"></i> Remove Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body">
                    <p>Remove <strong id="unlinkPropAddress"></strong> from this contact?</p>
                    <div class="form-group">
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkAction" value="unlink" checked> Unlink only <small class="text-muted">(property keeps its data but has no contact)</small>
                      </label>
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkAction" value="reassign"> Reassign to another contact
                      </label>
                    </div>
                    <div id="reassignContactRow" style="display: none;">
                      <label>Select contact:</label>
                      <select class="form-control" id="reassignContactSelect">
                        <option value="">-- Choose a contact --</option>
                        <?php foreach ($otherContacts as $oc): ?>
                          <option value="<?php echo (int)$oc['id']; ?>">
                            <?php echo h($oc['first_name'] . ' ' . $oc['last_name']); ?><?php echo !empty($oc['email']) ? ' (' . h($oc['email']) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <input type="hidden" id="unlinkPropertyId" value="">
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmUnlinkBtn" onclick="unlinkProperty()">
                      <i data-feather="check"></i> Confirm
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <script>
            (function() {
              var CONTACT_ID = <?php echo (int)$viewContact['id']; ?>;
              var CSRF_TOKEN = '<?php echo csrf_token(); ?>';
              var propertiesData = <?php echo json_encode($contactProperties); ?>;
              var nearbyClientsData = <?php echo json_encode($nearbyClients); ?>;
              var availableTags = <?php echo json_encode($availableTags); ?>;
              var gmap = null;
              var markers = {};
              var nearbyMarkers = [];
              var openInfoWindow = null;

              // ── Google Map with Nearby Clients ────────────────────
              function initContactMap() {
                var mapEl = document.getElementById('contactMapContainer');
                if (!mapEl) return;
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') return;

                var bounds = new google.maps.LatLngBounds();
                var hasMarkers = false;

                gmap = new google.maps.Map(mapEl, {
                  zoom: 12,
                  center: { lat: 49.2827, lng: -123.1207 },
                  mapTypeId: google.maps.MapTypeId.ROADMAP,
                  mapTypeControl: false,
                  streetViewControl: false,
                  styles: [
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                    { featureType: 'transit', stylers: [{ visibility: 'simplified' }] }
                  ]
                });

                // ── This client's properties (red markers) ──
                propertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: prop.address,
                    icon: {
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 10,
                      fillColor: '#E53E3E',
                      fillOpacity: 1,
                      strokeColor: '#fff',
                      strokeWeight: 2.5
                    },
                    zIndex: 1000
                  });

                  var infoContent = '<div style="max-width:260px;font-family:system-ui,sans-serif;">' +
                    '<div style="font-weight:700;color:#E53E3E;margin-bottom:2px;">This Client</div>' +
                    '<strong>' + escHtml(prop.address) + '</strong><br>' +
                    '<small style="color:#6B7280;">' + escHtml((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() {
                    if (openInfoWindow) openInfoWindow.close();
                    infoWindow.open(gmap, marker);
                    openInfoWindow = infoWindow;
                  });

                  markers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                // ── Nearby active clients (green markers) ──
                nearbyClientsData.forEach(function(nc, idx) {
                  var lat = parseFloat(nc.latitude);
                  var lng = parseFloat(nc.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var distKm = parseFloat(nc.distance_km);
                  var scaleSize = distKm <= 1 ? 8 : (distKm <= 3 ? 7 : 6);
                  var opacity = distKm <= 2 ? 0.9 : (distKm <= 5 ? 0.7 : 0.5);

                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: nc.first_name + ' ' + nc.last_name + ' (' + nc.distance_km + ' km)',
                    icon: {
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: scaleSize,
                      fillColor: '#2D8659',
                      fillOpacity: opacity,
                      strokeColor: '#fff',
                      strokeWeight: 1.5
                    },
                    zIndex: 500 - idx
                  });

                  var services = (nc.service_types || '').split(',').map(function(s) {
                    return s.trim().replace(/_/g, ' ');
                  }).filter(Boolean).map(function(s) {
                    return '<span style="display:inline-block;background:#E8F3F0;color:#2D8659;padding:1px 6px;border-radius:3px;font-size:11px;margin:1px 2px 1px 0;">' + escHtml(s) + '</span>';
                  }).join('');

                  var infoContent = '<div style="max-width:280px;font-family:system-ui,sans-serif;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">' +
                      '<strong style="color:#2D8659;">' + escHtml(nc.first_name + ' ' + nc.last_name) + '</strong>' +
                      '<span style="background:#2D8659;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;">' + nc.distance_km + ' km</span>' +
                    '</div>' +
                    '<div style="font-size:13px;color:#374151;">' + escHtml(nc.address) + '</div>' +
                    '<div style="font-size:12px;color:#6B7280;margin-bottom:4px;">' + escHtml(nc.city || '') + '</div>' +
                    (services ? '<div style="margin-top:4px;">' + services + '</div>' : '') +
                    '<div style="margin-top:6px;font-size:12px;color:#6B7280;">' +
                      nc.active_plan_count + ' active plan' + (nc.active_plan_count != 1 ? 's' : '') +
                      (nc.total_per_visit ? ' &middot; $' + parseFloat(nc.total_per_visit).toFixed(0) + '/visit' : '') +
                    '</div>' +
                    '<a href="?action=view_contact&id=' + nc.contact_id + '" style="display:inline-block;margin-top:6px;font-size:12px;color:#2D8659;font-weight:600;text-decoration:none;">View Client &rarr;</a>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() {
                    if (openInfoWindow) openInfoWindow.close();
                    infoWindow.open(gmap, marker);
                    openInfoWindow = infoWindow;
                  });

                  nearbyMarkers.push({ marker: marker, infoWindow: infoWindow, data: nc });
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  gmap.fitBounds(bounds);
                  // Ensure we don't zoom in too much if only 1 property + few nearby
                  google.maps.event.addListenerOnce(gmap, 'bounds_changed', function() {
                    if (gmap.getZoom() > 15) gmap.setZoom(15);
                  });
                }

                // ── Hover highlight from route intel list ──
                document.querySelectorAll('.mw-route-intel-item').forEach(function(item) {
                  var idx = parseInt(item.getAttribute('data-nearby-idx'));
                  if (isNaN(idx) || !nearbyMarkers[idx]) return;
                  item.addEventListener('mouseenter', function() {
                    nearbyMarkers[idx].marker.setIcon({
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 12,
                      fillColor: '#F59E0B',
                      fillOpacity: 1,
                      strokeColor: '#fff',
                      strokeWeight: 2.5
                    });
                    nearbyMarkers[idx].marker.setZIndex(2000);
                  });
                  item.addEventListener('mouseleave', function() {
                    var nc = nearbyMarkers[idx].data;
                    var distKm = parseFloat(nc.distance_km);
                    nearbyMarkers[idx].marker.setIcon({
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: distKm <= 1 ? 8 : (distKm <= 3 ? 7 : 6),
                      fillColor: '#2D8659',
                      fillOpacity: distKm <= 2 ? 0.9 : (distKm <= 5 ? 0.7 : 0.5),
                      strokeColor: '#fff',
                      strokeWeight: 1.5
                    });
                    nearbyMarkers[idx].marker.setZIndex(500 - idx);
                  });
                });
              }

              // ── Focus Property on Map ──────────────────────────
              window.focusProperty = function(propertyId) {
                if (!gmap || !markers[propertyId]) return;
                var marker = markers[propertyId];
                gmap.panTo(marker.getPosition());
                gmap.setZoom(16);
                google.maps.event.trigger(marker, 'click');
              };

              // ── Unlink / Reassign Property ───────────────────────
              window.showUnlinkProperty = function(propertyId, address) {
                document.getElementById('unlinkPropertyId').value = propertyId;
                document.getElementById('unlinkPropAddress').textContent = address;
                document.getElementById('reassignContactSelect').value = '';
                document.querySelector('input[name="unlinkAction"][value="unlink"]').checked = true;
                document.getElementById('reassignContactRow').style.display = 'none';
                $('#unlinkPropertyModal').modal('show');
              };

              // Toggle reassign dropdown visibility
              document.querySelectorAll('input[name="unlinkAction"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                  document.getElementById('reassignContactRow').style.display =
                    this.value === 'reassign' ? 'block' : 'none';
                });
              });

              window.unlinkProperty = function() {
                var propertyId = parseInt(document.getElementById('unlinkPropertyId').value);
                var action = document.querySelector('input[name="unlinkAction"]:checked').value;
                var newContactId = 0;

                if (action === 'reassign') {
                  newContactId = parseInt(document.getElementById('reassignContactSelect').value);
                  if (!newContactId) {
                    alert('Please select a contact to reassign to.');
                    return;
                  }
                }

                var btn = document.getElementById('confirmUnlinkBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';

                fetch('clients_appstack.php?action=unlink_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    property_id: propertyId,
                    current_contact_id: CONTACT_ID,
                    new_contact_id: newContactId,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="check"></i> Confirm';
                    feather.replace();
                  }
                })
                .catch(function() {
                  alert('Network error. Please try again.');
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="check"></i> Confirm';
                  feather.replace();
                });
              };

              // ── Add Property ────────────────────────────────────
              window.addPropertyToContact = function(event) {
                event.preventDefault();
                var address = document.getElementById('propAddress').value.trim();
                if (!address) { alert('Please enter a street address'); return; }

                var btn = document.getElementById('addPropBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

                fetch('clients_appstack.php?action=add_property_to_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    address: address,
                    city: document.getElementById('propCity').value.trim() || 'Vancouver',
                    postal_code: document.getElementById('propPostalCode').value.trim(),
                    property_name: document.getElementById('propName').value.trim(),
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    // Geocode the new property, then prompt for arrival border
                    var newPropId = data.property_id;
                    geocodePropertyAjax(newPropId).then(function() {
                      // Geocode succeeded — prompt to draw arrival border
                      $('#addPropertyModal').modal('hide');
                      var returnTo = 'clients_appstack.php?action=view_contact&id=' + CONTACT_ID;
                      var zoneUrl = 'jobs/zone-editor.php?property_id=' + newPropId + '&return_to=' + encodeURIComponent(returnTo);
                      document.getElementById('drawBorderNowBtn').href = zoneUrl;
                      $('#arrivalBorderPromptModal').modal('show');
                      feather.replace();
                    }).catch(function() {
                      // Geocode failed — just reload (can't draw border without coords)
                      location.reload();
                    });
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                    feather.replace();
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                  feather.replace();
                });
              };

              window.skipArrivalBorder = function() {
                $('#arrivalBorderPromptModal').modal('hide');
                location.reload();
              };

              // ── Link Company ────────────────────────────────────
              window.linkCompanyToContact = function() {
                var sel = document.getElementById('linkCompanySelect');
                if (!sel || !sel.value) { alert('Please select a company'); return; }

                fetch('clients_appstack.php?action=link_company_to_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    company_id: parseInt(sel.value),
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) location.reload();
                  else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              // ── Geocode Property (client-side via Google Maps JS API) ──
              var geocoder = null;
              function geocodePropertyAjax(propertyId) {
                if (!geocoder) geocoder = new google.maps.Geocoder();
                var prop = propertiesData.find(function(p) { return p.id == propertyId; });
                if (!prop) return Promise.reject(new Error('Property not found'));

                var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
                var address = parts.join(', ');

                return new Promise(function(resolve, reject) {
                  geocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                      var loc = results[0].geometry.location;
                      fetch('clients_appstack.php?action=save_property_coords', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                          property_id: propertyId,
                          lat: loc.lat(),
                          lng: loc.lng(),
                          csrf_token: CSRF_TOKEN
                        })
                      })
                      .then(function(r) { return r.json(); })
                      .then(function(data) { resolve(data); })
                      .catch(function(err) { reject(err); });
                    } else {
                      resolve({ success: false, error: 'Geocode failed: ' + status + ' for "' + address + '"' });
                    }
                  });
                });
              }

              window.geocodeProperty = function(propertyId, btn) {
                if (btn) {
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;"></span>';
                }
                geocodePropertyAjax(propertyId)
                  .then(function(data) {
                    if (data.success) {
                      location.reload();
                    } else {
                      alert('Geocoding failed: ' + (data.error || 'Unknown error'));
                      if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" style="width:12px;height:12px;"></i>'; feather.replace(); }
                    }
                  })
                  .catch(function(err) {
                    alert('Error: ' + err.message);
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" style="width:12px;height:12px;"></i>'; feather.replace(); }
                  });
              };

              window.geocodeAllProperties = function() {
                var toGeocode = propertiesData.filter(function(p) { return !parseFloat(p.latitude) || !parseFloat(p.longitude); });
                if (toGeocode.length === 0) return;

                var chain = Promise.resolve();
                toGeocode.forEach(function(p) {
                  chain = chain.then(function() {
                    return geocodePropertyAjax(p.id);
                  }).then(function() {
                    // Small delay between geocode requests to avoid rate limiting
                    return new Promise(function(resolve) { setTimeout(resolve, 300); });
                  });
                });
                chain.then(function() { location.reload(); });
              };

              // ── Utilities ───────────────────────────────────────
              function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
              }

              // ── Property Tags ──────────────────────────────────
              var activeTagPicker = null;

              window.showTagPicker = function(propertyId, btn) {
                // Close any existing picker
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }

                var picker = document.createElement('div');
                picker.className = 'mw-tag-picker-dropdown';
                picker.onclick = function(e) { e.stopPropagation(); };

                var html = '<div class="mw-tag-picker-inner">';
                html += '<select class="mw-tag-picker-select" id="tagSelect_' + propertyId + '" onchange="onTagSelectChange(' + propertyId + ')">';
                html += '<option value="">Select tag…</option>';
                for (var i = 0; i < availableTags.length; i++) {
                  html += '<option value="' + availableTags[i].tag_id + '" data-has-value="' + availableTags[i].has_value + '">' + escHtml(availableTags[i].tag_label) + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="mw-tag-picker-value" id="tagValue_' + propertyId + '" placeholder="Value…" style="display:none;">';
                html += '<button type="button" class="mw-tag-picker-save" onclick="applyPropertyTag(' + propertyId + ')">Save</button>';
                html += '<button type="button" class="mw-tag-picker-cancel" onclick="closeTagPicker()">&times;</button>';
                html += '</div>';

                picker.innerHTML = html;

                // Insert after the add button
                var tagsRow = document.getElementById('propTags_' + propertyId);
                tagsRow.appendChild(picker);
                activeTagPicker = picker;
              };

              window.onTagSelectChange = function(propertyId) {
                var sel = document.getElementById('tagSelect_' + propertyId);
                var valInput = document.getElementById('tagValue_' + propertyId);
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-has-value') === '1') {
                  valInput.style.display = '';
                  valInput.focus();
                } else {
                  valInput.style.display = 'none';
                  valInput.value = '';
                }
              };

              window.closeTagPicker = function() {
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }
              };

              window.applyPropertyTag = function(propertyId) {
                var sel = document.getElementById('tagSelect_' + propertyId);
                var valInput = document.getElementById('tagValue_' + propertyId);
                var tagId = parseInt(sel.value);
                if (!tagId) { alert('Please select a tag'); return; }

                var tagValue = valInput.value.trim() || null;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'apply',
                    entity_type: 'property',
                    entity_id: propertyId,
                    tag_id: tagId,
                    tag_value: tagValue,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                  }
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              window.removePropertyTag = function(propertyId, entityTagId, btn) {
                if (!confirm('Remove this tag?')) return;
                btn.disabled = true;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'remove',
                    entity_tag_id: entityTagId,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    // Remove the tag badge from DOM
                    var tagBadge = btn.parentElement;
                    tagBadge.remove();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                });
              };

              // Close tag picker on outside click
              document.addEventListener('click', function() {
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }
              });

              // ── Print Billing Statement ──────────────────────────
              function printStatement(contactId) {
                window.print();
              }

              // ── Add Client Note ──────────────────────────────────
              function addClientNote() {
                var noteType = document.getElementById('noteType').value;
                var noteContent = document.getElementById('noteContent').value.trim();
                if (!noteContent) { alert('Please enter a note.'); return; }

                fetch('clients_appstack.php?action=add_client_note', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    note_type: noteType,
                    content: noteContent,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) location.reload();
                  else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              }

              // ── Init ────────────────────────────────────────────
              document.addEventListener('DOMContentLoaded', function() {
                // Wait for Google Maps to load
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                  initContactMap();
                } else {
                  // Poll until loaded
                  var attempts = 0;
                  var interval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                      clearInterval(interval);
                      initContactMap();
                    }
                    if (attempts > 50) clearInterval(interval);
                  }, 200);
                }
              });
            })();
            </script>

          <?php elseif ($action === 'edit_company' && $editCompany): ?>
            <!-- Edit Company Form -->
            <form method="POST" id="editCompanyForm">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="update_company">
              <input type="hidden" name="company_id" value="<?php echo (int)$editCompany['id']; ?>">

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="briefcase"></i> Edit Company</h5>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Company Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="company_name" required
                          value="<?php echo h($_POST['company_name'] ?? $editCompany['company_name'] ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Type</label>
                        <?php $ct = $_POST['company_type'] ?? $editCompany['company_type'] ?? 'individual'; ?>
                        <select class="form-control" name="company_type">
                          <option value="individual" <?php echo $ct === 'individual' ? 'selected' : ''; ?>>Individual</option>
                          <option value="business" <?php echo $ct === 'business' ? 'selected' : ''; ?>>Business</option>
                          <option value="strata" <?php echo $ct === 'strata' ? 'selected' : ''; ?>>Strata</option>
                          <option value="property_manager" <?php echo $ct === 'property_manager' ? 'selected' : ''; ?>>Property Manager</option>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Billing Email</label>
                        <input type="email" class="form-control" name="billing_email"
                          value="<?php echo h($_POST['billing_email'] ?? $editCompany['billing_email'] ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Billing Phone</label>
                        <input type="tel" class="form-control" name="billing_phone"
                          value="<?php echo h($_POST['billing_phone'] ?? $editCompany['billing_phone'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Account Status</label>
                        <?php $as = $_POST['account_status'] ?? $editCompany['account_status'] ?? 'active'; ?>
                        <select class="form-control" name="account_status">
                          <option value="active" <?php echo $as === 'active' ? 'selected' : ''; ?>>Active</option>
                          <option value="inactive" <?php echo $as === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                          <option value="suspended" <?php echo $as === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="form-group">
                        <label>Payment Terms</label>
                        <?php $pt = $_POST['payment_terms'] ?? $editCompany['payment_terms'] ?? 'Net 30'; ?>
                        <select class="form-control" name="payment_terms">
                          <option value="Due on receipt" <?php echo $pt === 'Due on receipt' ? 'selected' : ''; ?>>Due on receipt</option>
                          <option value="Net 15" <?php echo $pt === 'Net 15' ? 'selected' : ''; ?>>Net 15</option>
                          <option value="Net 30" <?php echo $pt === 'Net 30' ? 'selected' : ''; ?>>Net 30</option>
                          <option value="Net 60" <?php echo $pt === 'Net 60' ? 'selected' : ''; ?>>Net 60</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="form-group">
                        <label>Payment Method</label>
                        <?php $pm = $_POST['payment_method'] ?? $editCompany['payment_method'] ?? 'invoice'; ?>
                        <select class="form-control" name="payment_method">
                          <option value="invoice" <?php echo $pm === 'invoice' ? 'selected' : ''; ?>>Invoice</option>
                          <option value="credit_card" <?php echo $pm === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                          <option value="bank_transfer" <?php echo $pm === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                          <option value="cheque" <?php echo $pm === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                        </select>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="file-text"></i> Billing Address</h5>
                </div>
                <div class="card-body">
                  <div class="form-group">
                    <label>Address</label>
                    <input type="text" class="form-control" name="billing_address"
                      value="<?php echo h($_POST['billing_address'] ?? $editCompany['billing_address'] ?? ''); ?>">
                  </div>
                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>City</label>
                        <input type="text" class="form-control" name="billing_city"
                          value="<?php echo h($_POST['billing_city'] ?? $editCompany['billing_city'] ?? 'Vancouver'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Province</label>
                        <input type="text" class="form-control" name="billing_province" maxlength="2"
                          value="<?php echo h($_POST['billing_province'] ?? $editCompany['billing_province'] ?? 'BC'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Postal Code</label>
                        <input type="text" class="form-control" name="billing_postal_code"
                          value="<?php echo h($_POST['billing_postal_code'] ?? $editCompany['billing_postal_code'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="file-text"></i> Notes</h5>
                </div>
                <div class="card-body">
                  <div class="form-group mb-0">
                    <textarea class="form-control" name="notes" rows="3"><?php echo h($_POST['notes'] ?? $editCompany['notes'] ?? ''); ?></textarea>
                  </div>
                </div>
              </div>

              <div class="form-group mt-3">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i data-feather="save"></i> Update Company
                </button>
                <a href="?action=view_company&id=<?php echo (int)$editCompany['id']; ?>" class="btn btn-secondary btn-lg ml-2">
                  <i data-feather="x"></i> Cancel
                </a>
              </div>
            </form>

          <?php elseif ($action === 'view_company' && $viewCompany): ?>
            <!-- View Company Profile -->
            <?php
              $primaryContact = null;
              $primaryContactId = (int)($viewCompany['primary_contact_id'] ?? 0);
              foreach ($companyContacts as $cc) {
                  if ((int)$cc['id'] === $primaryContactId) { $primaryContact = $cc; break; }
              }
              $primaryName = $primaryContact
                  ? trim(h($primaryContact['first_name'] . ' ' . ($primaryContact['last_name'] ?? '')))
                  : '';
            ?>

            <!-- Header: Contact name prominent, company secondary -->
            <div class="mw-contact-header">
              <div>
                <?php if ($primaryName): ?>
                  <h3 class="mw-contact-name">
                    <i data-feather="user" style="width: 24px; height: 24px;"></i>
                    <?php echo $primaryName; ?>
                  </h3>
                  <div class="text-muted" style="font-size: 0.95rem;">
                    <i data-feather="briefcase" style="width: 16px; height: 16px;"></i>
                    <?php echo h($viewCompany['company_name']); ?>
                    <span class="badge badge-light ml-1"><?php echo ucwords(str_replace('_', ' ', $viewCompany['company_type'] ?? 'individual')); ?></span>
                  </div>
                <?php else: ?>
                  <h3 class="mw-contact-name">
                    <i data-feather="briefcase" style="width: 24px; height: 24px;"></i>
                    <?php echo h($viewCompany['company_name']); ?>
                  </h3>
                  <div class="text-muted" style="font-size: 0.95rem;">
                    <span class="badge badge-light"><?php echo ucwords(str_replace('_', ' ', $viewCompany['company_type'] ?? 'individual')); ?></span>
                    <span class="text-warning ml-2"><i data-feather="alert-circle" style="width: 14px; height: 14px;"></i> No contact linked</span>
                  </div>
                <?php endif; ?>
                <?php
                  $statusColor = ($viewCompany['account_status'] ?? 'active') === 'active' ? 'success' : (($viewCompany['account_status'] ?? '') === 'inactive' ? 'secondary' : 'danger');
                ?>
                <span class="badge badge-<?php echo $statusColor; ?> mt-1"><?php echo ucfirst(h($viewCompany['account_status'] ?? 'active')); ?></span>
              </div>
              <div class="mw-contact-actions">
                <a href="?action=edit_company&id=<?php echo (int)$viewCompany['id']; ?>" class="btn btn-primary">
                  <i data-feather="edit-2"></i> Edit
                </a>
                <a href="clients_appstack.php" class="btn btn-secondary">
                  <i data-feather="arrow-left"></i> Back
                </a>
              </div>
            </div>

            <div class="row">
              <!-- Left Column -->
              <div class="col-lg-7">

                <!-- Contacts Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0">
                      <i data-feather="users"></i> Property Managers
                      <span class="badge badge-primary ml-1"><?php echo count($companyContacts); ?></span>
                    </h5>
                  </div>
                  <div class="card-body">
                    <?php if (empty($companyContacts)): ?>
                      <div class="text-center text-muted py-3">
                        <i data-feather="user-plus" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">No contacts linked to this company yet.</p>
                      </div>
                    <?php else: ?>
                      <?php foreach ($companyContacts as $cc):
                          $ccName = trim(h($cc['first_name'] . ' ' . ($cc['last_name'] ?? '')));
                          $isPrimary = ((int)$cc['id'] === $primaryContactId);
                          $isBilling = ((int)$cc['id'] === (int)($viewCompany['billing_contact_id'] ?? 0));
                      ?>
                        <div class="d-flex align-items-center justify-content-between py-2 <?php echo $isPrimary ? '' : 'border-top'; ?>">
                          <div>
                            <a href="?action=view_contact&id=<?php echo (int)$cc['id']; ?>" class="font-weight-bold" style="color: var(--mw-green);">
                              <?php echo $ccName; ?>
                            </a>
                            <?php if ($isPrimary): ?>
                              <span class="badge badge-success ml-1" style="font-size: 0.65rem;">Primary</span>
                            <?php endif; ?>
                            <?php if ($isBilling && !$isPrimary): ?>
                              <span class="badge badge-info ml-1" style="font-size: 0.65rem;">Billing</span>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size: 0.85rem;">
                              <?php if (!empty($cc['email'])): ?>
                                <i data-feather="mail" style="width: 12px; height: 12px;"></i> <?php echo h($cc['email']); ?>
                              <?php endif; ?>
                              <?php if (!empty($cc['phone'])): ?>
                                <span class="ml-2"><i data-feather="phone" style="width: 12px; height: 12px;"></i> <?php echo h($cc['phone']); ?></span>
                              <?php endif; ?>
                              <?php if (!empty($cc['mobile'])): ?>
                                <span class="ml-2"><i data-feather="smartphone" style="width: 12px; height: 12px;"></i> <?php echo h($cc['mobile']); ?></span>
                              <?php endif; ?>
                            </div>
                          </div>
                          <a href="?action=view_contact&id=<?php echo (int)$cc['id']; ?>" class="btn btn-sm btn-outline-secondary">
                            <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Properties Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="map-pin"></i> Properties
                      <span class="badge badge-primary ml-1"><?php echo count($companyProperties); ?></span>
                    </h5>
                    <?php if (!empty($companyContacts)): ?>
                      <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#addCompanyPropertyModal">
                        <i data-feather="plus"></i> Add Property
                      </button>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <?php if (empty($companyProperties)): ?>
                      <div class="text-center text-muted py-3">
                        <i data-feather="map" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">No properties linked yet.</p>
                        <?php if (empty($companyContacts)): ?>
                          <small>Add a contact to this company first, then you can add properties.</small>
                        <?php else: ?>
                          <small>Add a property address to enable scheduling and quoting.</small>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <?php foreach ($companyProperties as $prop):
                          $compPropTags = $companyPropertyTagMap[(int)$prop['id']] ?? [];
                          $ownerName = $companyContactNameMap[(int)($prop['site_contact_id'] ?? 0)] ?? '';
                          $mData = $compMeasurementMap[(int)$prop['id']] ?? [];
                          $mCount = (int)($mData['measurement_count'] ?? 0);
                          $lawnSqft = floatval($mData['total_lawn_sqft'] ?? 0);
                          $hardSqft = floatval($mData['total_hard_surface_sqft'] ?? 0);
                          $hedgeFt = floatval($mData['total_hedge_linear_ft'] ?? 0);
                          $otherSqft = floatval($mData['total_other_sqft'] ?? 0);
                          $measuredAt = $mData['measurements_updated_at'] ?? null;
                      ?>
                        <div class="mw-contact-property-item" onclick="focusCompanyProperty(<?php echo (int)$prop['id']; ?>)">
                          <div style="flex: 1; min-width: 0;">
                            <div class="mw-contact-property-addr">
                              <i data-feather="home" style="width: 14px; height: 14px;"></i>
                              <?php echo h($prop['address']); ?>
                            </div>
                            <div class="mw-contact-property-meta">
                              <?php echo h($prop['city'] ?? ''); ?><?php echo !empty($prop['province']) ? ', ' . h($prop['province']) : ''; ?> <?php echo h($prop['postal_code'] ?? ''); ?>
                              <?php if (!empty($prop['property_type'])): ?>
                                &middot; <?php echo ucwords(str_replace('_', ' ', $prop['property_type'])); ?>
                              <?php endif; ?>
                              <?php if ($ownerName): ?>
                                &middot; <i data-feather="user" style="width: 11px; height: 11px;"></i> <?php echo h($ownerName); ?>
                              <?php endif; ?>
                            </div>
                            <!-- Property Tags -->
                            <div class="mw-property-tags-row" id="compPropTags_<?php echo (int)$prop['id']; ?>" onclick="event.stopPropagation();">
                              <?php foreach ($compPropTags as $pTag): ?>
                                <span class="mw-property-tag" style="--tag-color: <?php echo h($pTag['tag_color']); ?>">
                                  <?php echo h($pTag['has_value'] && !empty($pTag['tag_value']) ? $pTag['tag_label'] . ': ' . $pTag['tag_value'] : $pTag['tag_label']); ?>
                                  <button type="button" class="mw-property-tag-remove" onclick="removeCompanyPropertyTag(<?php echo (int)$prop['id']; ?>, <?php echo (int)$pTag['entity_tag_id']; ?>, this)" title="Remove tag">&times;</button>
                                </span>
                              <?php endforeach; ?>
                              <button type="button" class="mw-property-tag-add-btn" onclick="showCompanyTagPicker(<?php echo (int)$prop['id']; ?>, this)" title="Add tag">
                                <i data-feather="plus" style="width: 10px; height: 10px;"></i>
                              </button>
                            </div>
                            <!-- Measurement Status -->
                            <div class="mw-property-measurement-status" onclick="event.stopPropagation();">
                              <?php if ($mCount > 0): ?>
                                <span class="mw-measurement-badge mw-measurement-done" title="<?php echo $mCount; ?> area(s) measured">
                                  <i data-feather="check-circle" style="width: 11px; height: 11px;"></i> Measured
                                </span>
                                <span class="mw-measurement-summary">
                                  <?php
                                    $parts = [];
                                    if ($lawnSqft > 0) $parts[] = number_format($lawnSqft) . ' sq ft lawn';
                                    if ($hardSqft > 0) $parts[] = number_format($hardSqft) . ' sq ft hard';
                                    if ($hedgeFt > 0) $parts[] = number_format($hedgeFt) . ' lin ft hedge';
                                    if ($otherSqft > 0) $parts[] = number_format($otherSqft) . ' sq ft other';
                                    echo h(implode(' · ', $parts) ?: $mCount . ' area(s)');
                                  ?>
                                </span>
                                <?php if ($measuredAt): ?>
                                  <span class="mw-measurement-date"><?php echo h(timeAgo($measuredAt)); ?></span>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="mw-measurement-badge mw-measurement-pending">
                                  <i data-feather="alert-circle" style="width: 11px; height: 11px;"></i> Not measured
                                </span>
                              <?php endif; ?>
                            </div>
                            <!-- Arrival Border Status -->
                            <?php if (floatval($prop['latitude'] ?? 0) != 0 && floatval($prop['longitude'] ?? 0) != 0): ?>
                            <div class="mw-property-geofence-status" onclick="event.stopPropagation();">
                              <?php if ((int)($prop['has_arrival_border'] ?? 0) > 0): ?>
                                <span class="mw-geofence-badge mw-geofence-set">
                                  <i data-feather="shield" style="width: 11px; height: 11px;"></i> Arrival Border Set
                                </span>
                              <?php else: ?>
                                <a href="jobs/zone-editor.php?property_id=<?php echo (int)$prop['id']; ?>&return_to=<?php echo urlencode('clients_appstack.php?action=view_company&id=' . (int)$companyId); ?>"
                                   class="mw-geofence-badge mw-geofence-missing" title="Draw arrival border for accurate auto-clock-in">
                                  <i data-feather="alert-triangle" style="width: 11px; height: 11px;"></i> No Arrival Border
                                </a>
                              <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <!-- Quick Actions -->
                            <div class="mw-property-quick-actions" onclick="event.stopPropagation();">
                              <a href="quote-workflow.php?contact_id=<?php echo (int)($prop['site_contact_id'] ?? 0); ?>&property_id=<?php echo (int)$prop['id']; ?>" class="mw-prop-action-btn mw-prop-action-primary" title="Measure, quote & auto-fill pricing">
                                <i data-feather="file-text" style="width: 11px; height: 11px;"></i> Quote &amp; Measure
                              </a>
                              <a href="jobs/create.php?contact_id=<?php echo (int)($prop['site_contact_id'] ?? 0); ?>&property_id=<?php echo (int)$prop['id']; ?>" class="mw-prop-action-btn" title="Create job plan for this property">
                                <i data-feather="clipboard" style="width: 11px; height: 11px;"></i> Create Plan
                              </a>
                            </div>
                          </div>
                          <div class="d-flex align-items-center" onclick="event.stopPropagation();">
                            <?php if (floatval($prop['latitude'] ?? 0) == 0 || floatval($prop['longitude'] ?? 0) == 0): ?>
                              <button type="button" class="btn btn-sm btn-outline-secondary mr-1" onclick="geocodeCompanyProperty(<?php echo (int)$prop['id']; ?>, this)" title="Geocode this address">
                                <i data-feather="crosshair" style="width: 12px; height: 12px;"></i>
                              </button>
                            <?php else: ?>
                              <span class="text-success mr-1" title="Geocoded"><i data-feather="check-circle" style="width: 14px; height: 14px;"></i></span>
                            <?php endif; ?>
                            <button type="button" class="mw-property-unlink-btn" onclick="showUnlinkCompanyProperty(<?php echo (int)$prop['id']; ?>, '<?php echo addslashes(h($prop['address'])); ?>', <?php echo (int)($prop['site_contact_id'] ?? 0); ?>)" title="Remove or reassign this property">
                              <i data-feather="x-circle" style="width: 14px; height: 14px;"></i>
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

              </div><!-- end left col -->

              <!-- Right Column -->
              <div class="col-lg-5">

                <!-- Company Details Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="info"></i> Company Details</h5>
                  </div>
                  <div class="card-body">
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Company</div>
                      <div class="col-sm-8"><strong><?php echo h($viewCompany['company_name']); ?></strong></div>
                    </div>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Type</div>
                      <div class="col-sm-8"><?php echo ucwords(str_replace('_', ' ', $viewCompany['company_type'] ?? 'individual')); ?></div>
                    </div>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Status</div>
                      <div class="col-sm-8">
                        <span class="badge badge-<?php echo $statusColor; ?>"><?php echo ucfirst(h($viewCompany['account_status'] ?? 'active')); ?></span>
                      </div>
                    </div>
                    <?php if (!empty($viewCompany['billing_email'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Billing Email</div>
                      <div class="col-sm-8"><a href="mailto:<?php echo h($viewCompany['billing_email']); ?>"><?php echo h($viewCompany['billing_email']); ?></a></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($viewCompany['billing_phone'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Billing Phone</div>
                      <div class="col-sm-8"><a href="tel:<?php echo h($viewCompany['billing_phone']); ?>"><?php echo h($viewCompany['billing_phone']); ?></a></div>
                    </div>
                    <?php endif; ?>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Payment</div>
                      <div class="col-sm-8"><?php echo h($viewCompany['payment_terms'] ?? 'Net 30'); ?> &middot; <?php echo ucwords(str_replace('_', ' ', $viewCompany['payment_method'] ?? 'invoice')); ?></div>
                    </div>
                    <?php if (!empty($viewCompany['notes'])): ?>
                    <div class="row mb-2">
                      <div class="col-sm-4 text-muted">Notes</div>
                      <div class="col-sm-8"><span class="text-muted"><?php echo nl2br(h($viewCompany['notes'])); ?></span></div>
                    </div>
                    <?php endif; ?>
                    <div class="row mb-0">
                      <div class="col-sm-4 text-muted">Created</div>
                      <div class="col-sm-8"><?php echo formatDate($viewCompany['created_at']); ?></div>
                    </div>
                  </div>
                </div>

                <!-- Property Map Card -->
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="map"></i> Property Map</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (!empty($companyProperties) && $compGeocodedCount > 0): ?>
                      <div id="companyMapContainer" class="mw-contact-map-container"></div>
                      <?php if ($compUngeocodedCount > 0): ?>
                        <div class="text-center py-2 border-top">
                          <small class="text-muted"><?php echo $compUngeocodedCount; ?> propert<?php echo $compUngeocodedCount === 1 ? 'y' : 'ies'; ?> not yet geocoded</small>
                          <button type="button" class="btn btn-sm btn-outline-secondary ml-2" onclick="geocodeAllCompanyProperties()">
                            <i data-feather="crosshair" style="width: 12px; height: 12px;"></i> Geocode All
                          </button>
                        </div>
                      <?php endif; ?>
                    <?php elseif (!empty($companyProperties)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="map" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">Properties not yet geocoded.</p>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="geocodeAllCompanyProperties()">
                          <i data-feather="crosshair" style="width: 12px; height: 12px;"></i> Geocode All
                        </button>
                      </div>
                    <?php else: ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="map" style="width: 32px; height: 32px;"></i>
                        <p class="mt-2 mb-0">Add properties to see them on the map.</p>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Billing Address Card -->
                <?php
                  $hasBillingAddr = !empty($viewCompany['billing_address']) || !empty($viewCompany['billing_city']);
                ?>
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="file-text"></i> Billing Address</h5>
                  </div>
                  <div class="card-body">
                    <?php if ($hasBillingAddr): ?>
                      <div><?php echo h($viewCompany['billing_address'] ?? ''); ?></div>
                      <div>
                        <?php echo h($viewCompany['billing_city'] ?? ''); ?><?php echo !empty($viewCompany['billing_province']) ? ', ' . h($viewCompany['billing_province']) : ''; ?>
                        <?php echo h($viewCompany['billing_postal_code'] ?? ''); ?>
                      </div>
                    <?php else: ?>
                      <span class="text-muted">No billing address on file</span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Quotes Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="file-text"></i> Quotes
                      <?php if (!empty($companyQuotes)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($companyQuotes); ?></span>
                      <?php endif; ?>
                    </h5>
                    <?php if (!empty($companyProperties) && !empty($companyContacts)): ?>
                      <a href="quote-workflow.php?contact_id=<?php echo (int)($viewCompany['primary_contact_id'] ?? $companyContacts[0]['id'] ?? 0); ?>&property_id=<?php echo (int)$companyProperties[0]['id']; ?>" class="btn btn-sm btn-success">
                        <i data-feather="plus"></i> New Quote
                      </a>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($companyQuotes)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="file-text" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No quotes yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Quote</th><th>Status</th><th class="text-right">Amount</th><th>Date</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($companyQuotes as $q): ?>
                              <tr onclick="window.location='quotes/view.php?id=<?php echo (int)$q['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($q['quote_number']); ?></strong>
                                  <?php if (!empty($q['title'])): ?>
                                    <br><small class="text-muted"><?php echo h($q['title']); ?></small>
                                  <?php endif; ?>
                                  <?php if (!empty($q['property_address'])): ?>
                                    <br><small class="text-muted"><i data-feather="map-pin" style="width: 10px; height: 10px;"></i> <?php echo h($q['property_address']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($q['status'], 'quote'); ?></td>
                                <td class="text-right"><?php echo formatCurrency($q['total_amount'] ?? 0); ?></td>
                                <td class="text-muted small"><?php echo formatDate($q['created_at']); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Job Plans Card -->
                <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                      <i data-feather="clipboard"></i> Job Plans
                      <?php if (!empty($companyPlans)): ?>
                        <span class="badge badge-primary ml-1"><?php echo count($companyPlans); ?></span>
                      <?php endif; ?>
                    </h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (empty($companyPlans)): ?>
                      <div class="text-center text-muted py-4">
                        <i data-feather="clipboard" style="width: 32px; height: 32px; opacity: 0.4;"></i>
                        <p class="mt-2 mb-0 small">No job plans yet</p>
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 mw-client-compact-table">
                          <thead>
                            <tr><th>Plan</th><th>Status</th><th>Visits</th><th class="text-right">$/Visit</th></tr>
                          </thead>
                          <tbody>
                            <?php foreach ($companyPlans as $pl): ?>
                              <tr onclick="window.location='jobs/view.php?id=<?php echo (int)$pl['id']; ?>'" style="cursor:pointer;">
                                <td>
                                  <strong><?php echo h($pl['plan_number']); ?></strong>
                                  <br><small class="text-muted">
                                    <?php echo h(ucwords(str_replace('_', ' ', $pl['service_type'] ?? ''))); ?>
                                    <?php if ($pl['is_recurring']): ?>
                                      &middot; <?php echo ucfirst($pl['recurrence_pattern'] ?? 'recurring'); ?>
                                    <?php endif; ?>
                                  </small>
                                  <?php if (!empty($pl['property_address'])): ?>
                                    <br><small class="text-muted"><i data-feather="map-pin" style="width: 10px; height: 10px;"></i> <?php echo h($pl['property_address']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($pl['status'], 'plan'); ?></td>
                                <td>
                                  <span class="<?php echo $pl['completed_count'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                    <?php echo (int)$pl['completed_count']; ?>/<?php echo (int)$pl['visit_count']; ?>
                                  </span>
                                  <?php if (!empty($pl['last_visit_date'])): ?>
                                    <br><small class="text-muted">Last: <?php echo formatDate($pl['last_visit_date']); ?></small>
                                  <?php endif; ?>
                                </td>
                                <td class="text-right"><?php echo formatCurrency($pl['price_per_visit'] ?? 0); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

              </div><!-- end right col -->
            </div><!-- end row -->

            <!-- Add Property Modal (Company View) -->
            <div class="modal fade" id="addCompanyPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: var(--mw-green); color: #fff;">
                    <h5 class="modal-title"><i data-feather="plus-circle" style="width: 18px; height: 18px;"></i> Add Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <form onsubmit="addPropertyToCompany(event)">
                    <div class="modal-body">
                      <div class="form-group">
                        <label>Street Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="compPropAddress" required placeholder="Start typing an address..." autocomplete="off">
                        <div id="compPropAddressDupeWarning" class="alert alert-warning mt-2 small" style="display:none;"></div>
                      </div>
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" id="compPropCity" value="Vancouver">
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Postal Code</label>
                            <input type="text" class="form-control" id="compPropPostalCode" placeholder="V5K 1A1">
                          </div>
                        </div>
                      </div>
                      <input type="hidden" id="compPropLat">
                      <input type="hidden" id="compPropLng">
                      <div class="form-group mb-0">
                        <label>Property Name <small class="text-muted">(optional, auto-generated if blank)</small></label>
                        <input type="text" class="form-control" id="compPropName" placeholder="e.g. Main Office">
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-success" id="addCompPropBtn">
                        <i data-feather="plus"></i> Add Property
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Unlink/Reassign Property Modal (Company View) -->
            <div class="modal fade" id="unlinkCompanyPropertyModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <div class="modal-header" style="background: #dc3545; color: #fff;">
                    <h5 class="modal-title"><i data-feather="x-circle" style="width: 18px; height: 18px;"></i> Remove Property</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body">
                    <p>Remove <strong id="unlinkCompPropAddress"></strong> from this company?</p>
                    <div class="form-group">
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkCompAction" value="unlink" checked> Unlink only <small class="text-muted">(property keeps its data but has no contact)</small>
                      </label>
                      <label class="d-block mb-2">
                        <input type="radio" name="unlinkCompAction" value="reassign"> Reassign to another contact
                      </label>
                    </div>
                    <div id="reassignCompContactRow" style="display: none;">
                      <label>Select contact:</label>
                      <select class="form-control" id="reassignCompContactSelect">
                        <option value="">-- Choose a contact --</option>
                        <?php foreach ($companyOtherContacts as $oc): ?>
                          <option value="<?php echo (int)$oc['id']; ?>">
                            <?php echo h($oc['first_name'] . ' ' . $oc['last_name']); ?><?php echo !empty($oc['email']) ? ' (' . h($oc['email']) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <input type="hidden" id="unlinkCompPropertyId" value="">
                    <input type="hidden" id="unlinkCompCurrentContactId" value="">
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmUnlinkCompBtn" onclick="unlinkCompanyProperty()">
                      <i data-feather="check"></i> Confirm
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <script>
            (function() {
              var COMPANY_ID = <?php echo (int)$viewCompany['id']; ?>;
              var CSRF_TOKEN_CO = '<?php echo csrf_token(); ?>';
              var companyPropertiesData = <?php echo json_encode($companyProperties); ?>;
              var companyAvailableTags = <?php echo json_encode($companyAvailableTags); ?>;
              var compGmap = null;
              var compMarkers = {};
              var compActiveTagPicker = null;

              // ── Utility ─────────────────────────────────────────────
              function escHtmlCo(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
              }

              // ── Google Places autocomplete ──────────────────────────
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof initAddressAutocomplete === 'function') {
                  initAddressAutocomplete('compPropAddress', 'compPropCity', 'compPropPostalCode', null, 'compPropLat', 'compPropLng');
                }
              });

              // ── Add Property ────────────────────────────────────────
              window.addPropertyToCompany = function(event) {
                event.preventDefault();
                var address = document.getElementById('compPropAddress').value.trim();
                if (!address) { alert('Please enter a street address'); return; }

                var btn = document.getElementById('addCompPropBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

                fetch('clients_appstack.php?action=add_property_to_company', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    company_id: COMPANY_ID,
                    address: address,
                    city: document.getElementById('compPropCity').value.trim() || 'Vancouver',
                    postal_code: document.getElementById('compPropPostalCode').value.trim(),
                    property_name: document.getElementById('compPropName').value.trim(),
                    latitude: document.getElementById('compPropLat').value || '',
                    longitude: document.getElementById('compPropLng').value || '',
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                    feather.replace();
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                  feather.replace();
                });
              };

              // ── Map ─────────────────────────────────────────────────
              function initCompanyMap() {
                var mapEl = document.getElementById('companyMapContainer');
                if (!mapEl) return;
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') return;

                var bounds = new google.maps.LatLngBounds();
                var hasMarkers = false;

                compGmap = new google.maps.Map(mapEl, {
                  zoom: 12,
                  center: { lat: 49.2827, lng: -123.1207 },
                  mapTypeId: google.maps.MapTypeId.ROADMAP,
                  mapTypeControl: false,
                  streetViewControl: false
                });

                companyPropertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: compGmap,
                    title: prop.address
                  });

                  var infoContent = '<div style="max-width: 250px;">' +
                    '<strong>' + escHtmlCo(prop.address) + '</strong><br>' +
                    '<small>' + escHtmlCo((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() { infoWindow.open(compGmap, marker); });

                  compMarkers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  compGmap.fitBounds(bounds);
                  if (Object.keys(compMarkers).length === 1) {
                    google.maps.event.addListenerOnce(compGmap, 'bounds_changed', function() {
                      compGmap.setZoom(15);
                    });
                  }
                }
              }

              window.focusCompanyProperty = function(propertyId) {
                if (!compGmap || !compMarkers[propertyId]) return;
                var marker = compMarkers[propertyId];
                compGmap.panTo(marker.getPosition());
                compGmap.setZoom(16);
                google.maps.event.trigger(marker, 'click');
              };

              // Init map on DOMContentLoaded
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                  initCompanyMap();
                } else {
                  var attempts = 0;
                  var interval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                      clearInterval(interval);
                      initCompanyMap();
                    }
                    if (attempts > 50) clearInterval(interval);
                  }, 200);
                }
              });

              // ── Geocoding ───────────────────────────────────────────
              var compGeocoder = null;

              function geocodeCompanyPropertyAjax(propertyId) {
                if (!compGeocoder) compGeocoder = new google.maps.Geocoder();
                var prop = companyPropertiesData.find(function(p) { return p.id == propertyId; });
                if (!prop) return Promise.reject(new Error('Property not found'));

                var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
                var address = parts.join(', ');

                return new Promise(function(resolve, reject) {
                  compGeocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                      var loc = results[0].geometry.location;
                      fetch('clients_appstack.php?action=save_property_coords', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                          property_id: propertyId,
                          lat: loc.lat(),
                          lng: loc.lng(),
                          csrf_token: CSRF_TOKEN_CO
                        })
                      })
                      .then(function(r) { return r.json(); })
                      .then(function(data) { resolve(data); })
                      .catch(function(err) { reject(err); });
                    } else {
                      resolve({ success: false, error: 'Geocode failed: ' + status });
                    }
                  });
                });
              }

              window.geocodeCompanyProperty = function(propertyId, btn) {
                if (btn) {
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;"></span>';
                }
                geocodeCompanyPropertyAjax(propertyId)
                  .then(function(data) {
                    if (data.success) {
                      location.reload();
                    } else {
                      alert('Geocoding failed: ' + (data.error || 'Unknown error'));
                      if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" style="width:12px;height:12px;"></i>'; feather.replace(); }
                    }
                  })
                  .catch(function(err) {
                    alert('Error: ' + err.message);
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" style="width:12px;height:12px;"></i>'; feather.replace(); }
                  });
              };

              window.geocodeAllCompanyProperties = function() {
                var toGeocode = companyPropertiesData.filter(function(p) { return !parseFloat(p.latitude) || !parseFloat(p.longitude); });
                if (toGeocode.length === 0) return;

                var chain = Promise.resolve();
                toGeocode.forEach(function(p) {
                  chain = chain.then(function() {
                    return geocodeCompanyPropertyAjax(p.id);
                  }).then(function() {
                    return new Promise(function(resolve) { setTimeout(resolve, 300); });
                  });
                });
                chain.then(function() { location.reload(); });
              };

              // ── Property Tags ───────────────────────────────────────
              window.showCompanyTagPicker = function(propertyId, btn) {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }

                var picker = document.createElement('div');
                picker.className = 'mw-tag-picker-dropdown';
                picker.onclick = function(e) { e.stopPropagation(); };

                var html = '<div class="mw-tag-picker-inner">';
                html += '<select class="mw-tag-picker-select" id="compTagSelect_' + propertyId + '" onchange="onCompanyTagSelectChange(' + propertyId + ')">';
                html += '<option value="">Select tag…</option>';
                for (var i = 0; i < companyAvailableTags.length; i++) {
                  html += '<option value="' + companyAvailableTags[i].tag_id + '" data-has-value="' + companyAvailableTags[i].has_value + '">' +
                          escHtmlCo(companyAvailableTags[i].tag_label) + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="mw-tag-picker-value" id="compTagValue_' + propertyId + '" placeholder="Value…" style="display:none;">';
                html += '<button type="button" class="mw-tag-picker-save" onclick="applyCompanyPropertyTag(' + propertyId + ')">Save</button>';
                html += '<button type="button" class="mw-tag-picker-cancel" onclick="closeCompanyTagPicker()">&times;</button>';
                html += '</div>';

                picker.innerHTML = html;
                var tagsRow = document.getElementById('compPropTags_' + propertyId);
                tagsRow.appendChild(picker);
                compActiveTagPicker = picker;
              };

              window.onCompanyTagSelectChange = function(propertyId) {
                var sel = document.getElementById('compTagSelect_' + propertyId);
                var valInput = document.getElementById('compTagValue_' + propertyId);
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-has-value') === '1') {
                  valInput.style.display = '';
                  valInput.focus();
                } else {
                  valInput.style.display = 'none';
                  valInput.value = '';
                }
              };

              window.closeCompanyTagPicker = function() {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }
              };

              window.applyCompanyPropertyTag = function(propertyId) {
                var sel = document.getElementById('compTagSelect_' + propertyId);
                var valInput = document.getElementById('compTagValue_' + propertyId);
                var tagId = parseInt(sel.value);
                if (!tagId) { alert('Please select a tag'); return; }

                var tagValue = valInput.value.trim() || null;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'apply',
                    entity_type: 'property',
                    entity_id: propertyId,
                    tag_id: tagId,
                    tag_value: tagValue,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                  }
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              window.removeCompanyPropertyTag = function(propertyId, entityTagId, btn) {
                if (!confirm('Remove this tag?')) return;
                btn.disabled = true;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'remove',
                    entity_tag_id: entityTagId,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    var tagBadge = btn.parentElement;
                    tagBadge.remove();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                });
              };

              // Close tag picker on outside click
              document.addEventListener('click', function() {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }
              });

              // ── Unlink / Reassign Property ──────────────────────────
              window.showUnlinkCompanyProperty = function(propertyId, address, currentContactId) {
                document.getElementById('unlinkCompPropertyId').value = propertyId;
                document.getElementById('unlinkCompCurrentContactId').value = currentContactId;
                document.getElementById('unlinkCompPropAddress').textContent = address;
                document.getElementById('reassignCompContactSelect').value = '';
                document.querySelector('input[name="unlinkCompAction"][value="unlink"]').checked = true;
                document.getElementById('reassignCompContactRow').style.display = 'none';
                $('#unlinkCompanyPropertyModal').modal('show');
              };

              // Toggle reassign dropdown visibility
              document.querySelectorAll('input[name="unlinkCompAction"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                  document.getElementById('reassignCompContactRow').style.display =
                    this.value === 'reassign' ? 'block' : 'none';
                });
              });

              window.unlinkCompanyProperty = function() {
                var propertyId = parseInt(document.getElementById('unlinkCompPropertyId').value);
                var currentContactId = parseInt(document.getElementById('unlinkCompCurrentContactId').value);
                var action = document.querySelector('input[name="unlinkCompAction"]:checked').value;
                var newContactId = 0;

                if (action === 'reassign') {
                  newContactId = parseInt(document.getElementById('reassignCompContactSelect').value);
                  if (!newContactId) {
                    alert('Please select a contact to reassign to.');
                    return;
                  }
                }

                var btn = document.getElementById('confirmUnlinkCompBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';

                fetch('clients_appstack.php?action=unlink_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    property_id: propertyId,
                    current_contact_id: currentContactId,
                    new_contact_id: newContactId,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="check"></i> Confirm';
                    feather.replace();
                  }
                })
                .catch(function() {
                  alert('Network error. Please try again.');
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="check"></i> Confirm';
                  feather.replace();
                });
              };

            })();
            </script>

          <?php else: ?>

          <?php if (($_GET['view'] ?? '') === 'duplicates'): ?>
            <!-- ── Duplicates Review View ─────────────────────────────────── -->
            <div class="mb-3">
              <a href="clients_appstack.php" class="btn btn-outline-secondary btn-sm">
                <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back to Client List
              </a>
            </div>

            <?php if (empty($duplicateGroups)): ?>
              <div class="card">
                <div class="card-body text-center py-5">
                  <i data-feather="check-circle" style="width:48px;height:48px;color:var(--mw-green);"></i>
                  <h5 class="mt-3">No Duplicates Found</h5>
                  <p class="text-muted">All contacts look clean. No potential duplicates detected.</p>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-info d-flex align-items-center mb-3">
                <i data-feather="info" style="width:18px;height:18px;margin-right:8px;flex-shrink:0;"></i>
                <span><?php echo count($duplicateGroups); ?> group<?php echo count($duplicateGroups) > 1 ? 's' : ''; ?> of potential duplicates found. Review each group and merge where appropriate.</span>
              </div>

              <?php foreach ($duplicateGroups as $groupIdx => $groupIds): ?>
                <?php
                  $reasons = $dupeMatchReasons[$groupIdx] ?? [];
                  $reasonLabels = [];
                  if (isset($reasons['email'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="mail" style="width:12px;height:12px;"></i> Email: ' . $reasons['email'] . '</span>';
                  if (isset($reasons['phone'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="phone" style="width:12px;height:12px;"></i> Phone: ' . $reasons['phone'] . '</span>';
                  if (isset($reasons['address'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="map-pin" style="width:12px;height:12px;"></i> Address</span>';
                  if (isset($reasons['name'])) $reasonLabels[] = '<span class="badge badge-info mr-1"><i data-feather="user" style="width:12px;height:12px;"></i> Name: ' . $reasons['name'] . '</span>';
                ?>
                <div class="card mb-3 mw-dupe-group-card">
                  <div class="card-header d-flex justify-content-between align-items-center" style="background:var(--mw-light);">
                    <div>
                      <strong>Group <?php echo $groupIdx + 1; ?></strong>
                      <span class="text-muted ml-2"><?php echo count($groupIds); ?> contacts</span>
                    </div>
                    <div>Match: <?php echo !empty($reasonLabels) ? implode(' ', $reasonLabels) : '<span class="text-muted">unknown</span>'; ?></div>
                  </div>
                  <div class="card-body p-0">
                    <div class="row no-gutters">
                      <?php foreach ($groupIds as $ci => $cId):
                        $c = $dupeContactDetails[$cId] ?? null;
                        if (!$c) continue;
                      ?>
                        <div class="col-md<?php echo count($groupIds) === 2 ? '-6' : ''; ?> mw-dupe-contact-col <?php echo $ci > 0 ? 'mw-dupe-contact-border' : ''; ?>">
                          <div class="p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                              <div>
                                <h6 class="mb-0">
                                  <a href="?action=view_contact&id=<?php echo (int)$c['id']; ?>" class="text-dark">
                                    <?php echo h($c['first_name'] . ' ' . $c['last_name']); ?>
                                  </a>
                                </h6>
                                <small class="text-muted">ID #<?php echo (int)$c['id']; ?> &middot; Created <?php echo date('M j, Y', strtotime($c['created_at'])); ?></small>
                              </div>
                            </div>
                            <table class="table table-sm table-borderless mb-0 mw-dupe-fields">
                              <tr>
                                <td class="text-muted" style="width:100px;">Email</td>
                                <td><?php echo $c['email'] ? h($c['email']) : '<span class="text-muted">—</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Phone</td>
                                <td><?php echo $c['phone'] ? h($c['phone']) : '<span class="text-muted">—</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Mobile</td>
                                <td><?php echo $c['mobile'] ? h($c['mobile']) : '<span class="text-muted">—</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Properties</td>
                                <td><?php echo $c['property_addresses'] ? h($c['property_addresses']) : '<span class="text-muted">none</span>'; ?></td>
                              </tr>
                              <tr>
                                <td class="text-muted">Quotes</td>
                                <td><?php echo (int)$c['quote_request_count']; ?> request<?php echo $c['quote_request_count'] != 1 ? 's' : ''; ?></td>
                              </tr>
                              <?php if (!empty($c['notes'])): ?>
                              <tr>
                                <td class="text-muted">Notes</td>
                                <td><small><?php echo h(mb_substr($c['notes'], 0, 80)); ?><?php echo mb_strlen($c['notes']) > 80 ? '...' : ''; ?></small></td>
                              </tr>
                              <?php endif; ?>
                            </table>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div class="card-footer text-center">
                    <button class="btn btn-warning" onclick="showMergeModal(<?php echo (int)$groupIds[0]; ?>)">
                      <i data-feather="git-merge" style="width:16px;height:16px;"></i> Review &amp; Merge
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          <?php else: ?>
            <!-- Search Bar -->
            <div class="mb-3">
              <div class="input-group" style="max-width: 400px;">
                <div class="input-group-prepend">
                  <span class="input-group-text"><i data-feather="search" style="width: 16px; height: 16px;"></i></span>
                </div>
                <input type="text" class="form-control" id="mw-client-search" placeholder="Search clients by name, company, email, phone..." autocomplete="off">
                <div class="input-group-append" id="mw-search-clear" style="display: none;">
                  <button class="btn btn-outline-secondary" type="button" onclick="clearClientSearch()">
                    <i data-feather="x" style="width: 16px; height: 16px;"></i>
                  </button>
                </div>
              </div>
              <small class="text-muted mt-1 d-none" id="mw-search-count"></small>
            </div>

            <!-- View Toggle -->
            <div class="mb-3 d-flex justify-content-between align-items-center">
              <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="view_mode" id="view_kanban" value="kanban" checked>
                <label class="btn btn-outline-secondary" for="view_kanban">
                  <i data-feather="layout"></i> Kanban
                </label>
                <input type="radio" class="btn-check" name="view_mode" id="view_list" value="list">
                <label class="btn btn-outline-secondary" for="view_list">
                  <i data-feather="list"></i> List
                </label>
              </div>
              <button class="btn btn-outline-primary" onclick="showStageManagerModal()">
                <i data-feather="settings"></i> Manage Stages
              </button>
            </div>

            <!-- Kanban View -->
            <div id="kanban-view" class="mw-kanban-container">
              <?php
                $stagesData = getCompaniesByLifecycleStage();
                $allStages = getLifecycleStages();

                // Map standalone contacts into stagesData by prospect_status
                // prospect_status maps: prospect → prospect, client → client, inactive → inactive
                foreach ($standaloneContacts as $contact) {
                    $contactStage = $contact['prospect_status'] ?? 'prospect';
                    if (!isset($stagesData[$contactStage])) {
                        $stagesData[$contactStage] = [
                            'label' => ucfirst($contactStage),
                            'color' => '#6B7280',
                            'companies' => [],
                            'contacts' => []
                        ];
                    }
                    if (!isset($stagesData[$contactStage]['contacts'])) {
                        $stagesData[$contactStage]['contacts'] = [];
                    }
                    $stagesData[$contactStage]['contacts'][] = $contact;
                }

                // Build effective columns: start with defined stages, then add any
                // stages that exist in company data but aren't in the stages table
                $kanbanColumns = [];
                $seenKeys = [];
                foreach ($allStages as $stage) {
                    $kanbanColumns[] = $stage;
                    $seenKeys[$stage['stage_key']] = true;
                }
                foreach ($stagesData as $stageKey => $data) {
                    if (!isset($seenKeys[$stageKey])) {
                        $kanbanColumns[] = [
                            'stage_key' => $stageKey,
                            'stage_label' => $data['label'] ?? ucfirst($stageKey),
                            'stage_color' => $data['color'] ?? '#6B7280',
                        ];
                        $seenKeys[$stageKey] = true;
                    }
                }

                // If no columns at all, provide default fallback columns
                if (empty($kanbanColumns)) {
                    $kanbanColumns = [
                        ['stage_key' => 'prospect', 'stage_label' => 'Prospect', 'stage_color' => '#3B82F6'],
                        ['stage_key' => 'qualified', 'stage_label' => 'Qualified', 'stage_color' => '#F59E0B'],
                        ['stage_key' => 'client', 'stage_label' => 'Client', 'stage_color' => '#2D8659'],
                        ['stage_key' => 'inactive', 'stage_label' => 'Inactive', 'stage_color' => '#6B7280'],
                    ];
                }
              ?>
              <?php foreach ($kanbanColumns as $stage): ?>
                <?php
                  $companyCount = count($stagesData[$stage['stage_key']]['companies'] ?? []);
                  $contactCount = count($stagesData[$stage['stage_key']]['contacts'] ?? []);
                  $totalCount = $companyCount + $contactCount;
                ?>
                <div class="mw-kanban-column" data-stage="<?php echo h($stage['stage_key']); ?>" style="border-top: 4px solid <?php echo h($stage['stage_color']); ?>;">
                  <div class="mw-kanban-header" style="background: <?php echo h($stage['stage_color']); ?>;">
                    <h5 class="mb-0 text-white">
                      <?php echo h($stage['stage_label']); ?>
                      <span class="badge badge-light ml-2"><?php echo $totalCount; ?></span>
                    </h5>
                  </div>
                  <div class="mw-kanban-cards" data-stage="<?php echo h($stage['stage_key']); ?>">
                    <?php foreach ($stagesData[$stage['stage_key']]['companies'] ?? [] as $company): ?>
                      <div class="mw-kanban-card" draggable="true" data-company-id="<?php echo (int)$company['id']; ?>" data-company-name="<?php echo h($company['company_name']); ?>">
                        <a href="?action=view_company&id=<?php echo (int)$company['id']; ?>" class="mw-card-link">
                          <div class="mw-card-header">
                            <?php if (!empty($company['primary_contact_name'])): ?>
                              <strong><?php echo h($company['primary_contact_name']); ?></strong>
                              <small class="text-muted d-block"><?php echo h($company['company_name']); ?></small>
                            <?php else: ?>
                              <strong><?php echo h($company['company_name']); ?></strong>
                            <?php endif; ?>
                          </div>
                          <div class="mw-card-body">
                            <small class="text-muted d-block">
                              <i data-feather="mail" style="width: 14px; height: 14px;"></i>
                              <?php echo h($company['billing_email'] ?? '—'); ?>
                            </small>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="home" style="width: 14px; height: 14px;"></i>
                              <?php echo ucwords(str_replace('_', ' ', $company['company_type'])); ?>
                            </small>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="calendar" style="width: 14px; height: 14px;"></i>
                              <?php echo formatDate($company['created_at']); ?>
                            </small>
                          </div>
                        </a>
                        <div class="mw-card-actions mt-2 pt-2 border-top">
                          <a href="?action=view_company&id=<?php echo (int)$company['id']; ?>" class="btn btn-sm btn-outline-primary" title="View">
                            <i data-feather="edit-2"></i>
                          </a>
                        </div>
                      </div>
                    <?php endforeach; ?>

                    <?php foreach ($stagesData[$stage['stage_key']]['contacts'] ?? [] as $contact): ?>
                      <div class="mw-kanban-card" draggable="true" data-contact-id="<?php echo (int)$contact['id']; ?>" data-company-name="<?php echo h(trim($contact['first_name'] . ' ' . $contact['last_name'])); ?>" style="border-left: 3px solid var(--mw-orange);">
                        <a href="?action=view_contact&id=<?php echo (int)$contact['id']; ?>" class="mw-card-link">
                          <div class="mw-card-header">
                            <strong><?php echo h(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))); ?></strong>
                            <span class="badge badge-light ml-1" style="font-size: 0.65rem;">Contact</span>
                          </div>
                          <div class="mw-card-body">
                            <small class="text-muted d-block">
                              <i data-feather="mail" style="width: 14px; height: 14px;"></i>
                              <?php echo h($contact['email'] ?? '—'); ?>
                            </small>
                            <?php if (!empty($contact['phone'])): ?>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="phone" style="width: 14px; height: 14px;"></i>
                              <?php echo h($contact['phone']); ?>
                            </small>
                            <?php endif; ?>
                            <small class="text-muted d-block mt-1">
                              <i data-feather="calendar" style="width: 14px; height: 14px;"></i>
                              <?php echo formatDate($contact['created_at']); ?>
                            </small>
                          </div>
                        </a>
                        <?php if (!empty($duplicateMap[$contact['id']])): ?>
                        <div class="mt-2 pt-2 border-top">
                          <button type="button" class="mw-duplicate-badge" onclick="event.preventDefault(); event.stopPropagation(); showMergeModal(<?php echo (int)$contact['id']; ?>)" title="Possible duplicate contact detected — click to review and merge">
                            <i data-feather="alert-triangle" style="width: 12px; height: 12px;"></i>
                            Possible Duplicate
                          </button>
                        </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- List View -->
            <div id="list-view" style="display: none;">
            <div class="card">
              <div class="card-header">
                <h5 class="card-title mb-0">
                  All Clients &amp; Contacts
                  <span class="badge badge-primary ml-2"><?php echo count($clients) + count($standaloneContacts); ?></span>
                </h5>
              </div>
              <div class="card-body">
                <?php if (empty($clients) && empty($standaloneContacts) && empty($unconvertedRequests)): ?>
                  <div class="text-center text-muted py-5">
                    <i data-feather="inbox" style="width: 48px; height: 48px;"></i>
                    <p class="mt-3 mb-0">No clients yet. <a href="?action=new">Create one now</a></p>
                  </div>
                <?php else: ?>
                  <!-- Clients & Prospects Table -->
                  <?php if (!empty($clients)): ?>
                    <div class="table-responsive mb-4">
                      <table class="table table-hover" id="mw-clients-table">
                        <thead>
                          <tr>
                            <th class="mw-bulk-checkbox-cell">
                              <input type="checkbox" class="mw-bulk-checkbox" id="mw-clients-select-all" title="Select all">
                            </th>
                            <th>Contact</th>
                            <th>Company</th>
                            <th>Type</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($clients as $c): ?>
                          <tr class="mw-client-row" data-search="<?php echo h(strtolower(trim(($c['primary_contact_name'] ?? '') . ' ' . $c['company_name'] . ' ' . ($c['billing_email'] ?? '') . ' ' . ($c['primary_contact_phone'] ?? '')))); ?>" data-href="?action=view_company&id=<?php echo (int)$c['id']; ?>" <?php echo $c['source_type'] === 'prospect' ? 'style="background: #fef3c7; opacity: 0.9;"' : ''; ?>>
                            <td class="mw-bulk-checkbox-cell">
                              <input type="checkbox" class="mw-bulk-checkbox mw-bulk-row-select" data-id="<?php echo (int)$c['id']; ?>">
                            </td>
                            <td>
                              <a href="?action=view_company&id=<?php echo (int)$c['id']; ?>" class="mw-client-name-link">
                                <?php if (!empty(trim($c['primary_contact_name'] ?? ''))): ?>
                                  <strong><?php echo h($c['primary_contact_name']); ?></strong>
                                <?php else: ?>
                                  <span class="text-muted">—</span>
                                <?php endif; ?>
                              </a>
                            </td>
                            <td>
                              <a href="?action=view_company&id=<?php echo (int)$c['id']; ?>" class="mw-client-name-link">
                                <?php echo h($c['company_name']); ?>
                              </a>
                              <?php if ($c['source_type'] === 'prospect'): ?>
                                <br><small class="text-warning">Prospect</small>
                              <?php endif; ?>
                            </td>
                            <td>
                              <span class="badge badge-light">
                                <?php echo ucwords(str_replace('_', ' ', $c['company_type'])); ?>
                              </span>
                            </td>
                            <td><?php echo h($c['billing_email'] ?? '—'); ?></td>
                            <td><?php echo h($c['primary_contact_phone'] ?? '—'); ?></td>
                            <td>
                              <?php
                                if ($c['source_type'] === 'prospect') {
                                  $statusColor = 'info';
                                  $statusText = 'Prospect - ' . ucfirst($c['qr_status'] ?? 'new');
                                } else {
                                  $statusColor = $c['account_status'] === 'active' ? 'success' : ($c['account_status'] === 'inactive' ? 'secondary' : 'danger');
                                  $statusText = ucfirst($c['account_status']);
                                }
                              ?>
                              <span class="badge badge-<?php echo $statusColor; ?>">
                                <?php echo $statusText; ?>
                              </span>
                            </td>
                            <td>
                              <a href="?action=view_company&id=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-primary">
                                <i data-feather="eye"></i> View
                              </a>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>

                  <!-- Standalone Contacts (not linked to a company) -->
                  <?php if (!empty($standaloneContacts)): ?>
                    <h6 class="mb-2 mt-2" id="mw-standalone-header">
                      <i data-feather="user" style="width: 18px; height: 18px; display: inline; margin-right: 4px;"></i>
                      Standalone Contacts
                      <span class="badge badge-secondary ml-1"><?php echo count($standaloneContacts); ?></span>
                    </h6>
                    <p class="text-muted small mb-2" id="mw-standalone-desc">Contacts not yet linked to a company.</p>
                    <div class="table-responsive mb-4">
                      <table class="table table-sm table-hover" id="mw-standalone-table">
                        <thead>
                          <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Created</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($standaloneContacts as $ct): ?>
                          <tr class="mw-client-row" data-search="<?php echo h(strtolower(trim(($ct['first_name'] ?? '') . ' ' . ($ct['last_name'] ?? '') . ' ' . ($ct['email'] ?? '') . ' ' . ($ct['phone'] ?? '')))); ?>" data-href="?action=view_contact&id=<?php echo (int)$ct['id']; ?>">
                            <td>
                              <a href="?action=view_contact&id=<?php echo (int)$ct['id']; ?>" class="mw-client-name-link">
                                <strong><?php echo h(trim($ct['first_name'] . ' ' . ($ct['last_name'] ?? ''))); ?></strong>
                              </a>
                              <?php if (!empty($ct['stripe_card_last4'])): ?>
                                <span class="mw-card-on-file-badge" title="Card on file: <?php echo h(ucfirst($ct['stripe_card_brand'] ?? 'Card')); ?> ••••<?php echo h($ct['stripe_card_last4']); ?>">
                                  <i data-feather="credit-card"></i> ••••<?php echo h($ct['stripe_card_last4']); ?>
                                </span>
                              <?php endif; ?>
                            </td>
                            <td><?php echo h($ct['email'] ?? '—'); ?></td>
                            <td><?php echo h($ct['phone'] ?? '—'); ?></td>
                            <td>
                              <span class="badge badge-<?php echo $ct['prospect_status'] === 'client' ? 'success' : ($ct['prospect_status'] === 'inactive' ? 'secondary' : 'info'); ?>">
                                <?php echo ucfirst($ct['prospect_status'] ?? 'prospect'); ?>
                              </span>
                            </td>
                            <td>
                              <small class="text-muted"><?php echo formatDate($ct['created_at']); ?></small>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>

                  <!-- Unconverted Quote Requests -->
                  <?php if (!empty($unconvertedRequests)): ?>
                    <div class="alert alert-info">
                      <h6 class="mb-2">
                        <i data-feather="inbox" style="width: 18px; height: 18px; display: inline; margin-right: 4px;"></i>
                        New Quote Requests (<?php echo count($unconvertedRequests); ?>)
                      </h6>
                      <p class="mb-2 small">These are new inquiries that haven't been converted to clients yet.</p>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover">
                        <thead>
                          <tr>
                            <th>Contact</th>
                            <th>Property</th>
                            <th>Services</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($unconvertedRequests as $req): ?>
                          <tr style="background: #fef9e7;">
                            <td>
                              <strong><?php echo h($req['contact_name'] ?? 'Unknown'); ?></strong>
                            </td>
                            <td>
                              <small><?php echo h($req['property_address'] ?? '—'); ?></small>
                              <?php if ($req['city']): ?>
                                <br><small class="text-muted"><?php echo h($req['city']); ?></small>
                              <?php endif; ?>
                            </td>
                            <td>
                              <small><?php echo h(substr($req['service_types'] ?? 'Not specified', 0, 40)); ?></small>
                            </td>
                            <td>
                              <span class="badge badge-<?php echo $req['urgency'] === 'asap' ? 'danger' : ($req['urgency'] === 'soon' ? 'warning' : 'info'); ?>">
                                <?php echo ucfirst($req['urgency']); ?>
                              </span>
                            </td>
                            <td>
                              <span class="badge badge-light"><?php echo ucfirst($req['status']); ?></span>
                            </td>
                            <td>
                              <small class="text-muted"><?php echo formatDate($req['created_at']); ?></small>
                            </td>
                            <td>
                              <a href="products/quote-requests.php?id=<?php echo (int)$req['id']; ?>" class="btn btn-sm btn-outline-primary" title="View request">
                                <i data-feather="eye"></i>
                              </a>
                              <button class="btn btn-sm btn-outline-success" onclick="convertToProspect(<?php echo (int)$req['id']; ?>, '<?php echo h(addslashes($req['contact_name'] ?? 'New Prospect')); ?>')" title="Convert to prospect">
                                <i data-feather="plus"></i>
                              </button>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            </div><!-- end list-view -->
          <?php endif; ?><!-- end duplicates vs normal view -->
          <?php endif; ?><!-- end action chain -->

          <!-- Bulk Action Bar -->
          <div class="mw-bulk-action-bar" id="mw-clients-bulk-bar">
            <div>
              <span class="mw-bulk-count" id="mw-clients-bulk-count">0</span> clients selected
              <button class="btn btn-sm mw-bulk-clear-btn ml-3" onclick="mwBulkClearClients()">Clear Selection</button>
            </div>
            <button class="btn btn-sm btn-danger" onclick="mwBulkDeleteClients()">
              <i data-feather="trash-2"></i> Delete Selected
            </button>
          </div>

          <!-- Stage Manager Modal -->
          <div class="modal fade" id="stageManagerModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Manage Lifecycle Stages</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <div class="mb-4">
                    <h6><strong>Add New Stage</strong></h6>
                    <form id="addStageForm" onsubmit="handleAddStage(event)">
                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Stage Key *</label>
                            <input type="text" class="form-control" id="stageKey" placeholder="e.g., won, lost" required>
                            <small class="text-muted">Unique identifier (lowercase, no spaces)</small>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label>Stage Label *</label>
                            <input type="text" class="form-control" id="stageLabel" placeholder="e.g., Won" required>
                          </div>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Sort Order</label>
                            <input type="number" class="form-control" id="stageOrder" value="0">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>Color</label>
                            <input type="color" class="form-control" id="stageColor" value="#6B7280">
                          </div>
                        </div>
                        <div class="col-md-4">
                          <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-success btn-block">
                              <i data-feather="plus"></i> Add Stage
                            </button>
                          </div>
                        </div>
                      </div>
                      <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" id="stageDescription" rows="2"></textarea>
                      </div>
                    </form>
                  </div>

                  <hr>

                  <div>
                    <h6><strong>Current Stages</strong></h6>
                    <div id="stagesList">
                      <?php foreach (getLifecycleStages() as $stage): ?>
                        <div class="mw-stage-item" data-stage-id="<?php echo (int)$stage['id']; ?>">
                          <div class="d-flex align-items-center justify-content-between p-2 border rounded mb-2" style="background: #f8f9fa;">
                            <div>
                              <strong><?php echo h($stage['stage_label']); ?></strong>
                              <span class="badge" style="background: <?php echo h($stage['stage_color']); ?>; color: white;">
                                <?php echo h($stage['stage_key']); ?>
                              </span>
                              <?php if ($stage['description']): ?>
                                <p class="text-muted small mb-0 mt-1"><?php echo h($stage['description']); ?></p>
                              <?php endif; ?>
                            </div>
                            <div class="btn-group btn-group-sm">
                              <button class="btn btn-outline-primary" onclick="editStage(<?php echo (int)$stage['id']; ?>, '<?php echo h(addslashes($stage['stage_label'])); ?>', '<?php echo h($stage['stage_color']); ?>', <?php echo (int)$stage['stage_order']; ?>)" title="Edit">
                                <i data-feather="edit-2"></i>
                              </button>
                              <button class="btn btn-outline-danger" onclick="deleteStage(<?php echo (int)$stage['id']; ?>, '<?php echo h(addslashes($stage['stage_label'])); ?>')" title="Delete">
                                <i data-feather="trash-2"></i>
                              </button>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Merge Contact Modal -->
          <div class="modal fade" id="mergeContactModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-xl" role="document">
              <div class="modal-content">
                <div class="modal-header" style="background: var(--mw-orange); color: #fff;">
                  <h5 class="modal-title"><i data-feather="git-merge" style="width: 18px; height: 18px;"></i> Merge Duplicate Contacts</h5>
                  <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body" id="mergeModalBody">
                  <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading contact details...</p>
                  </div>
                </div>
                <div class="modal-footer" id="mergeModalFooter" style="display: none;">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-warning" id="mergeConfirmBtn" onclick="executeMerge()">
                    <i data-feather="git-merge" style="width: 14px; height: 14px;"></i> Merge Contacts
                  </button>
                </div>
              </div>
            </div>
          </div>

          <script>
            // Kanban drag and drop functionality
            let draggedCard = null;

            function setupKanbanDragDrop() {
              const cards = document.querySelectorAll('.mw-kanban-card');
              const dropZones = document.querySelectorAll('.mw-kanban-cards');

              cards.forEach(card => {
                card.addEventListener('dragstart', handleDragStart);
                card.addEventListener('dragend', handleDragEnd);
              });

              dropZones.forEach(zone => {
                zone.addEventListener('dragover', handleDragOver);
                zone.addEventListener('drop', handleDrop);
              });
            }

            function handleDragStart(e) {
              draggedCard = this;
              this.style.opacity = '0.5';
              e.dataTransfer.effectAllowed = 'move';
            }

            function handleDragEnd(e) {
              this.style.opacity = '1';
              draggedCard = null;
            }

            function handleDragOver(e) {
              if (e.preventDefault) {
                e.preventDefault();
              }
              e.dataTransfer.dropEffect = 'move';
              return false;
            }

            function handleDrop(e) {
              if (e.stopPropagation) {
                e.stopPropagation();
              }

              if (!draggedCard) return false;

              const targetStage = this.dataset.stage;
              const companyId = draggedCard.dataset.companyId ? parseInt(draggedCard.dataset.companyId) : null;
              const contactId = draggedCard.dataset.contactId ? parseInt(draggedCard.dataset.contactId) : null;

              // Move card to new column
              this.appendChild(draggedCard);

              // Update backend — company or contact
              if (companyId) {
                fetch('clients_appstack.php?action=move_company', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    company_id: companyId,
                    new_stage: targetStage,
                    csrf_token: '<?php echo csrf_token(); ?>'
                  })
                })
                .then(r => r.json())
                .then(data => {
                  if (!data.success) {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    location.reload();
                  }
                })
                .catch(err => {
                  alert('Error: ' + err.message);
                  location.reload();
                });
              } else if (contactId) {
                fetch('clients_appstack.php?action=move_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: contactId,
                    new_stage: targetStage,
                    csrf_token: '<?php echo csrf_token(); ?>'
                  })
                })
                .then(r => r.json())
                .then(data => {
                  if (!data.success) {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    location.reload();
                  }
                })
                .catch(err => {
                  alert('Error: ' + err.message);
                  location.reload();
                });
              }

              return false;
            }

            // View mode toggle
            function setupViewToggle() {
              const kanbanView = document.getElementById('kanban-view');
              const listView = document.getElementById('list-view');
              const kanbanRadio = document.getElementById('view_kanban');
              const listRadio = document.getElementById('view_list');

              if (!kanbanRadio || !listRadio) return;

              kanbanRadio.addEventListener('change', function() {
                if (this.checked) {
                  kanbanView.style.display = 'flex';
                  listView.style.display = 'none';
                }
              });

              listRadio.addEventListener('change', function() {
                if (this.checked) {
                  kanbanView.style.display = 'none';
                  listView.style.display = 'block';
                }
              });
            }

            // Stage management functions
            function showStageManagerModal() {
              const modal = document.getElementById('stageManagerModal');
              if (modal) {
                $(modal).modal('show');
              }
            }

            function handleAddStage(event) {
              event.preventDefault();

              const stageKey = document.getElementById('stageKey').value.trim().toLowerCase();
              const stageLabel = document.getElementById('stageLabel').value.trim();
              const stageOrder = parseInt(document.getElementById('stageOrder').value) || 0;
              const stageColor = document.getElementById('stageColor').value;
              const stageDescription = document.getElementById('stageDescription').value.trim();

              if (!stageKey || !stageLabel) {
                alert('Please fill in Stage Key and Stage Label');
                return;
              }

              fetch('clients_appstack.php?action=manage_stages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  stage_action: 'add',
                  stage_key: stageKey,
                  stage_label: stageLabel,
                  stage_order: stageOrder,
                  stage_color: stageColor,
                  description: stageDescription,
                  csrf_token: '<?php echo csrf_token(); ?>'
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Stage added! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            function editStage(stageId, label, color, order) {
              const newLabel = prompt('Edit stage label:', label);
              if (!newLabel) return;

              fetch('clients_appstack.php?action=manage_stages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  stage_action: 'update',
                  stage_id: stageId,
                  stage_label: newLabel,
                  stage_color: color,
                  stage_order: order,
                  csrf_token: '<?php echo csrf_token(); ?>'
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Stage updated! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            function deleteStage(stageId, label) {
              if (!confirm('Delete stage "' + label + '"? This action cannot be undone and the stage must not be in use.')) {
                return;
              }

              fetch('clients_appstack.php?action=manage_stages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  stage_action: 'delete',
                  stage_id: stageId,
                  csrf_token: '<?php echo csrf_token(); ?>'
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Stage deleted! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // ── Google Places Address Autocomplete ──
            function initAddressAutocomplete(inputId, cityId, postalId, provinceId, latId, lngId) {
              var input = document.getElementById(inputId);
              if (!input) return;
              if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                setTimeout(function() { initAddressAutocomplete(inputId, cityId, postalId, provinceId, latId, lngId); }, 200);
                return;
              }
              var ac = new google.maps.places.Autocomplete(input, {
                types: ['address'],
                componentRestrictions: { country: ['ca'] },
                fields: ['address_components', 'geometry', 'formatted_address']
              });
              ac.addListener('place_changed', function() {
                var place = ac.getPlace();
                if (!place || !place.geometry) return;
                var street = '', city = '', postal = '', province = '';
                if (place.address_components) {
                  for (var i = 0; i < place.address_components.length; i++) {
                    var c = place.address_components[i];
                    if (c.types.indexOf('street_number') !== -1) street = c.long_name + ' ' + street;
                    if (c.types.indexOf('route') !== -1) street = street + c.long_name;
                    if (c.types.indexOf('locality') !== -1) city = c.long_name;
                    if (c.types.indexOf('postal_code') !== -1) postal = c.long_name;
                    if (c.types.indexOf('administrative_area_level_1') !== -1) province = c.short_name;
                  }
                }
                if (street.trim()) input.value = street.trim();
                var cityEl = document.getElementById(cityId);
                if (cityEl && city) cityEl.value = city;
                var postalEl = document.getElementById(postalId);
                if (postalEl && postal) postalEl.value = postal;
                if (provinceId) {
                  var provEl = document.getElementById(provinceId);
                  if (provEl && province) provEl.value = province;
                }
                if (latId) {
                  var latEl = document.getElementById(latId);
                  if (latEl) latEl.value = place.geometry.location.lat();
                }
                if (lngId) {
                  var lngEl = document.getElementById(lngId);
                  if (lngEl) lngEl.value = place.geometry.location.lng();
                }
                // Check for duplicate property address after selection
                if (inputId === 'propertyAddress' || inputId === 'propAddress' || inputId === 'compPropAddress') {
                  checkDuplicatePropertyAddress(input.value.trim(), inputId);
                }
              });
            }

            // ── Duplicate property address check ──
            function checkDuplicatePropertyAddress(address, sourceInputId) {
              if (!address) return;
              fetch('api/check-address.php?address=' + encodeURIComponent(address))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  var warningId = sourceInputId === 'propAddress' ? 'propAddressDupeWarning' :
                                  sourceInputId === 'compPropAddress' ? 'compPropAddressDupeWarning' : 'propertyAddressDupeWarning';
                  var warningEl = document.getElementById(warningId);
                  if (!warningEl) return;
                  if (data.exists) {
                    warningEl.innerHTML = '<i data-feather="alert-triangle" style="width:14px;height:14px;"></i> <strong>Address already exists</strong> — linked to ' +
                      (data.contact_name ? data.contact_name : 'an existing property') +
                      (data.property_id ? ' (Property #' + data.property_id + ')' : '') +
                      '. Saving will link this contact to the existing property.';
                    warningEl.style.display = 'block';
                    if (typeof feather !== 'undefined') feather.replace();
                  } else {
                    warningEl.style.display = 'none';
                  }
                })
                .catch(function() {});
            }

            // Client search functionality
            function setupClientSearch() {
              var searchInput = document.getElementById('mw-client-search');
              var searchClear = document.getElementById('mw-search-clear');
              var searchCount = document.getElementById('mw-search-count');
              if (!searchInput) return;

              searchInput.addEventListener('input', function() {
                var query = this.value.toLowerCase().trim();
                searchClear.style.display = query ? 'flex' : 'none';

                // Filter list view rows
                var rows = document.querySelectorAll('.mw-client-row');
                var visible = 0;
                rows.forEach(function(row) {
                  var data = row.getAttribute('data-search') || '';
                  var match = !query || data.indexOf(query) !== -1;
                  row.style.display = match ? '' : 'none';
                  if (match) visible++;
                });

                // Filter kanban cards
                var cards = document.querySelectorAll('.mw-kanban-card');
                cards.forEach(function(card) {
                  var name = (card.getAttribute('data-company-name') || '').toLowerCase();
                  var text = (card.textContent || '').toLowerCase();
                  var match = !query || name.indexOf(query) !== -1 || text.indexOf(query) !== -1;
                  card.style.display = match ? '' : 'none';
                });

                // Update kanban column counts
                document.querySelectorAll('.mw-kanban-column').forEach(function(col) {
                  var visibleCards = col.querySelectorAll('.mw-kanban-card:not([style*="display: none"])').length;
                  var badge = col.querySelector('.mw-kanban-header .badge');
                  if (badge) badge.textContent = visibleCards;
                });

                // Show/hide standalone section header when filtered
                var standaloneHeader = document.getElementById('mw-standalone-header');
                var standaloneDesc = document.getElementById('mw-standalone-desc');
                var standaloneTable = document.getElementById('mw-standalone-table');
                if (standaloneTable) {
                  var standaloneVisible = standaloneTable.querySelectorAll('.mw-client-row:not([style*="display: none"])').length;
                  var showStandalone = !query || standaloneVisible > 0;
                  if (standaloneHeader) standaloneHeader.style.display = showStandalone ? '' : 'none';
                  if (standaloneDesc) standaloneDesc.style.display = showStandalone ? '' : 'none';
                }

                // Show result count
                if (query) {
                  searchCount.textContent = visible + ' result' + (visible !== 1 ? 's' : '') + ' found';
                  searchCount.classList.remove('d-none');
                } else {
                  searchCount.classList.add('d-none');
                }
              });
            }

            function clearClientSearch() {
              var input = document.getElementById('mw-client-search');
              if (input) {
                input.value = '';
                input.dispatchEvent(new Event('input'));
                input.focus();
              }
            }

            // Company toggle and mode switching
            document.addEventListener('DOMContentLoaded', function() {
              setupKanbanDragDrop();
              setupViewToggle();
              setupClientSearch();

              // Initialize address autocomplete for create form
              initAddressAutocomplete('propertyAddress', 'propertyCity', 'propertyPostalCode', null, 'propertyLatitude', 'propertyLongitude');
              initAddressAutocomplete('billingAddress', 'billingCity', 'billingPostalCode', 'billingProvince', null, null);
              // For Add Property modal
              initAddressAutocomplete('propAddress', 'propCity', 'propPostalCode', null, null, null);

              // Company link toggle
              const linkToggle = document.getElementById('linkCompanyToggle');
              const companySection = document.getElementById('companySection');
              const existingRadio = document.getElementById('company_mode_existing');
              const newRadio = document.getElementById('company_mode_new');
              const existingSection = document.getElementById('existing-company-section');
              const newSection = document.getElementById('new-company-section');

              if (linkToggle) {
                function toggleCompanySection() {
                  companySection.style.display = linkToggle.checked ? 'block' : 'none';
                }
                linkToggle.addEventListener('change', toggleCompanySection);
                toggleCompanySection(); // apply initial state
              }

              if (existingRadio && newRadio) {
                function updateCompanyMode() {
                  if (existingRadio.checked) {
                    existingSection.style.display = 'block';
                    newSection.style.display = 'none';
                  } else {
                    existingSection.style.display = 'none';
                    newSection.style.display = 'block';
                  }
                }
                existingRadio.addEventListener('change', updateCompanyMode);
                newRadio.addEventListener('change', updateCompanyMode);
                updateCompanyMode(); // apply initial state
              }
            });

            function convertToProspect(requestId, contactName) {
              if (!confirm('Convert "' + contactName + '" to a prospect client?')) return;

              fetch('clients_appstack.php?action=convert_to_prospect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  request_id: requestId,
                  csrf_token: '<?php echo csrf_token(); ?>'
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Prospect created! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // ── Bulk Delete ──────────────────────────────────────
            var mwClientsBulkSelected = new Set();

            // Select-all checkbox
            var clientsSelectAll = document.getElementById('mw-clients-select-all');
            if (clientsSelectAll) {
              clientsSelectAll.addEventListener('change', function() {
                var checked = this.checked;
                document.querySelectorAll('#list-view .mw-bulk-row-select').forEach(function(cb) {
                  cb.checked = checked;
                  var id = parseInt(cb.dataset.id);
                  if (checked) {
                    mwClientsBulkSelected.add(id);
                  } else {
                    mwClientsBulkSelected.delete(id);
                  }
                });
                mwClientsBulkUpdateBar();
              });
            }

            // Individual row checkbox
            document.addEventListener('change', function(e) {
              if (e.target.classList.contains('mw-bulk-row-select') && e.target.closest('#list-view')) {
                var id = parseInt(e.target.dataset.id);
                if (e.target.checked) {
                  mwClientsBulkSelected.add(id);
                } else {
                  mwClientsBulkSelected.delete(id);
                  if (clientsSelectAll) clientsSelectAll.checked = false;
                }
                mwClientsBulkUpdateBar();
              }
            });

            function mwClientsBulkUpdateBar() {
              var bar = document.getElementById('mw-clients-bulk-bar');
              var count = document.getElementById('mw-clients-bulk-count');
              if (!bar || !count) return;
              count.textContent = mwClientsBulkSelected.size;
              if (mwClientsBulkSelected.size > 0) {
                bar.classList.add('mw-bulk-visible');
              } else {
                bar.classList.remove('mw-bulk-visible');
              }
            }

            function mwBulkClearClients() {
              mwClientsBulkSelected.clear();
              document.querySelectorAll('#list-view .mw-bulk-row-select').forEach(function(cb) { cb.checked = false; });
              if (clientsSelectAll) clientsSelectAll.checked = false;
              mwClientsBulkUpdateBar();
            }

            function mwBulkDeleteClients() {
              var count = mwClientsBulkSelected.size;
              if (count === 0) return;

              var response = prompt(
                'WARNING: Permanently delete ' + count + ' client(s)?\n\n' +
                'This will CASCADE DELETE all their:\n' +
                '• Jobs\n' +
                '• Invoices\n' +
                '• Property associations\n\n' +
                'This is IRREVERSIBLE. Type DELETE to confirm:'
              );
              if (response !== 'DELETE') return;

              fetch('?action=bulk_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  ids: Array.from(mwClientsBulkSelected),
                  csrf_token: '<?php echo generateCSRFToken(); ?>'
                })
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.success) {
                  var msg = data.deleted_count + ' client(s) deleted.';
                  if (data.related_jobs > 0) msg += '\n' + data.related_jobs + ' job(s) cascade-deleted.';
                  if (data.related_invoices > 0) msg += '\n' + data.related_invoices + ' invoice(s) cascade-deleted.';
                  alert(msg);
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(function(err) { alert('Error: ' + err.message); });
            }

            // ── Merge Duplicate Contacts ──────────────────────────────

            var mergeKeepId = null;
            var mergeMergeId = null;
            var mergeContactA = null;
            var mergeContactB = null;

            function showMergeModal(contactId) {
              mergeKeepId = null;
              mergeMergeId = null;
              document.getElementById('mergeModalBody').innerHTML =
                '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Loading contact details...</p></div>';
              document.getElementById('mergeModalFooter').style.display = 'none';
              $('#mergeContactModal').modal('show');

              fetch('api/contact-duplicates.php?action=pair&id=' + contactId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (!data.success || !data.contact || !data.duplicates.length) {
                    document.getElementById('mergeModalBody').innerHTML =
                      '<div class="text-center py-4"><p class="text-muted">No duplicates found for this contact.</p></div>';
                    return;
                  }

                  mergeContactA = data.contact;
                  mergeContactB = data.duplicates[0]; // First duplicate
                  mergeKeepId = parseInt(mergeContactA.id);
                  mergeMergeId = parseInt(mergeContactB.id);

                  renderMergeComparison();
                  document.getElementById('mergeModalFooter').style.display = '';
                  feather.replace();
                })
                .catch(function(err) {
                  document.getElementById('mergeModalBody').innerHTML =
                    '<div class="alert alert-danger">Failed to load contact details: ' + err.message + '</div>';
                });
            }

            function renderMergeComparison() {
              var a = mergeContactA;
              var b = mergeContactB;
              var fields = [
                { key: 'first_name', label: 'First Name' },
                { key: 'last_name', label: 'Last Name' },
                { key: 'email', label: 'Email' },
                { key: 'phone', label: 'Phone' },
                { key: 'mobile', label: 'Mobile' },
                { key: 'preferred_contact_method', label: 'Preferred Contact' }
              ];

              var html = '<div class="mb-3">';
              html += '<div class="d-flex justify-content-between align-items-center mb-3">';
              html += '<div><small class="text-muted">Select which value to keep for each field. The "merge" contact will be deactivated.</small></div>';
              html += '<button type="button" class="btn btn-sm mw-merge-swap-btn" onclick="swapMergeSides()">';
              html += '<i data-feather="refresh-cw" style="width: 14px; height: 14px;"></i> Swap Sides</button>';
              html += '</div>';

              // Duplicate selector if multiple duplicates
              html += '<table class="mw-merge-table">';
              html += '<thead><tr>';
              html += '<th class="mw-merge-field-label">Field</th>';
              html += '<th class="mw-merge-radio-cell" style="background: #d4edda;">Keep — #' + a.id + ' <small>(created ' + formatMergeDate(a.created_at) + ')</small></th>';
              html += '<th class="mw-merge-radio-cell" style="background: #f8d7da;">Merge — #' + b.id + ' <small>(created ' + formatMergeDate(b.created_at) + ')</small></th>';
              html += '</tr></thead><tbody>';

              fields.forEach(function(f) {
                var valA = (a[f.key] || '').toString().trim();
                var valB = (b[f.key] || '').toString().trim();
                // Default: pick the non-empty value, or the 'keep' side
                var defaultChoice = 'keep';
                if (!valA && valB) defaultChoice = 'merge';

                html += '<tr>';
                html += '<td class="mw-merge-field-label">' + f.label + '</td>';
                html += '<td class="mw-merge-radio-cell"><label>';
                html += '<input type="radio" name="merge_' + f.key + '" value="keep"' + (defaultChoice === 'keep' ? ' checked' : '') + '> ';
                html += valA ? escHtml(valA) : '<span class="mw-merge-value-empty">empty</span>';
                html += '</label></td>';
                html += '<td class="mw-merge-radio-cell"><label>';
                html += '<input type="radio" name="merge_' + f.key + '" value="merge"' + (defaultChoice === 'merge' ? ' checked' : '') + '> ';
                html += valB ? escHtml(valB) : '<span class="mw-merge-value-empty">empty</span>';
                html += '</label></td>';
                html += '</tr>';
              });

              // Notes row — special handling with append option
              var notesA = (a.notes || '').trim();
              var notesB = (b.notes || '').trim();
              html += '<tr>';
              html += '<td class="mw-merge-field-label">Notes</td>';
              html += '<td colspan="2">';
              html += '<label class="d-block mb-1"><input type="radio" name="merge_notes" value="keep" checked> Keep notes from #' + a.id;
              if (notesA) html += ': <em class="text-muted">' + escHtml(notesA.substring(0, 80)) + (notesA.length > 80 ? '...' : '') + '</em>';
              html += '</label>';
              html += '<label class="d-block mb-1"><input type="radio" name="merge_notes" value="merge"> Use notes from #' + b.id;
              if (notesB) html += ': <em class="text-muted">' + escHtml(notesB.substring(0, 80)) + (notesB.length > 80 ? '...' : '') + '</em>';
              html += '</label>';
              if (notesA || notesB) {
                html += '<label class="d-block"><input type="radio" name="merge_notes" value="append"> Append both notes together</label>';
              }
              html += '</td>';
              html += '</tr>';

              html += '</tbody></table>';

              // Show related data summary
              html += '<div class="row mt-3">';
              html += '<div class="col-md-6"><div class="card"><div class="card-body p-2">';
              html += '<small class="font-weight-bold">Contact #' + a.id + ' links:</small><br>';
              html += '<small>Properties: ' + (a.property_addresses || 'none') + '</small><br>';
              html += '<small>Quote requests: ' + (a.quote_request_count || 0) + '</small>';
              html += '</div></div></div>';
              html += '<div class="col-md-6"><div class="card"><div class="card-body p-2">';
              html += '<small class="font-weight-bold">Contact #' + b.id + ' links:</small><br>';
              html += '<small>Properties: ' + (b.property_addresses || 'none') + '</small><br>';
              html += '<small>Quote requests: ' + (b.quote_request_count || 0) + '</small>';
              html += '</div></div></div>';
              html += '</div>';

              html += '<div class="alert alert-info mt-3 mb-0"><small><strong>What happens on merge:</strong> All quote requests, properties, notes, and other records from the "Merge" contact will be reassigned to the "Keep" contact. The merged contact will be deactivated.</small></div>';

              html += '</div>';

              document.getElementById('mergeModalBody').innerHTML = html;
            }

            function swapMergeSides() {
              var tmp = mergeContactA;
              mergeContactA = mergeContactB;
              mergeContactB = tmp;
              mergeKeepId = parseInt(mergeContactA.id);
              mergeMergeId = parseInt(mergeContactB.id);
              renderMergeComparison();
              feather.replace();
            }

            function executeMerge() {
              if (!mergeKeepId || !mergeMergeId) return;

              var fields = {};
              var fieldKeys = ['first_name', 'last_name', 'email', 'phone', 'mobile', 'preferred_contact_method', 'notes'];
              fieldKeys.forEach(function(key) {
                var radios = document.querySelectorAll('input[name="merge_' + key + '"]');
                radios.forEach(function(r) {
                  if (r.checked) fields[key] = r.value;
                });
              });

              if (!confirm('Are you sure you want to merge contact #' + mergeMergeId + ' into contact #' + mergeKeepId + '? This cannot be undone.')) {
                return;
              }

              document.getElementById('mergeConfirmBtn').disabled = true;
              document.getElementById('mergeConfirmBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Merging...';

              fetch('api/contact-duplicates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  action: 'merge',
                  keep_id: mergeKeepId,
                  merge_id: mergeMergeId,
                  fields: fields,
                  csrf_token: '<?php echo csrf_token(); ?>'
                })
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.success) {
                  alert('Contacts merged successfully!');
                  location.reload();
                } else {
                  alert('Merge failed: ' + (data.error || 'Unknown error'));
                  document.getElementById('mergeConfirmBtn').disabled = false;
                  document.getElementById('mergeConfirmBtn').innerHTML = '<i data-feather="git-merge" style="width: 14px; height: 14px;"></i> Merge Contacts';
                  feather.replace();
                }
              })
              .catch(function(err) {
                alert('Error: ' + err.message);
                document.getElementById('mergeConfirmBtn').disabled = false;
                document.getElementById('mergeConfirmBtn').innerHTML = '<i data-feather="git-merge" style="width: 14px; height: 14px;"></i> Merge Contacts';
                feather.replace();
              });
            }

            function formatMergeDate(dateStr) {
              if (!dateStr) return '—';
              var d = new Date(dateStr);
              return d.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
            }

            function escHtml(str) {
              var div = document.createElement('div');
              div.appendChild(document.createTextNode(str));
              return div.innerHTML;
            }
          </script>

<?php if ($action === 'view_contact' && $viewContact): ?>
<!-- ── Work Zone (Geofence) Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="workZoneModal" tabindex="-1" role="dialog" aria-labelledby="workZoneModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="workZoneModalLabel">
          <i data-feather="map-pin" style="width:18px;height:18px;"></i> Work Zone — Auto Clock-In Area
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-3">

        <!-- ── 3-Step Guide ──────────────────────────────────────────────── -->
        <div class="mw-wz-steps mb-3">
          <div class="mw-wz-step mw-wz-step--active" id="wz-step-1">
            <div class="mw-wz-step-num">1</div>
            <div class="mw-wz-step-body">
              <div class="mw-wz-step-title">Select Plan</div>
              <div class="mw-wz-step-desc">Choose which job plan this zone applies to</div>
            </div>
          </div>
          <div class="mw-wz-step-connector"></div>
          <div class="mw-wz-step" id="wz-step-2">
            <div class="mw-wz-step-num">2</div>
            <div class="mw-wz-step-body">
              <div class="mw-wz-step-title">Draw Boundary</div>
              <div class="mw-wz-step-desc">Click the map to outline the property work area</div>
            </div>
          </div>
          <div class="mw-wz-step-connector"></div>
          <div class="mw-wz-step" id="wz-step-3">
            <div class="mw-wz-step-num">3</div>
            <div class="mw-wz-step-body">
              <div class="mw-wz-step-title">Save Zone</div>
              <div class="mw-wz-step-desc">Zone activates on next crew clock-in visit</div>
            </div>
          </div>
        </div>

        <!-- ── Plan Selector ────────────────────────────────────────────── -->
        <div class="form-group mb-3">
          <label class="font-weight-bold small mb-1">Job Plan</label>
          <select id="wz-plan-select" class="form-control form-control-sm" onchange="onWZPlanChange()">
            <option value="">-- Select a plan --</option>
          </select>
          <small class="text-muted">Work zones are saved per plan — each plan can have its own zone.</small>
        </div>

        <!-- ── Map ──────────────────────────────────────────────────────── -->
        <div class="mw-wz-map-wrap">
          <div id="wz-map"></div>
          <div id="wz-map-placeholder" class="mw-wz-map-placeholder">
            <i data-feather="map" style="width:36px;height:36px;color:#ccc;"></i>
            <p class="mt-2 mb-0 text-muted small">Select a plan above to load the map</p>
          </div>
        </div>

        <!-- ── Contextual Hint ───────────────────────────────────────────── -->
        <div id="wz-hint" class="mw-wz-hint mt-2">
          <i class="mw-wz-hint-icon" data-feather="info" style="width:13px;height:13px;flex-shrink:0;"></i>
          <span id="wz-hint-text">Select a job plan above to get started.</span>
        </div>

        <!-- ── Status (success / error feedback) ────────────────────────── -->
        <div id="wz-status" class="mw-wz-status mt-2" style="display:none;"></div>

        <!-- ── Drawing Tips (collapsed by default, shown during draw) ────── -->
        <div id="wz-draw-tips" class="mw-wz-draw-tips mt-2" style="display:none;">
          <div class="mw-wz-draw-tips-title">
            <i data-feather="crosshair" style="width:12px;height:12px;"></i> Drawing mode active
          </div>
          <ul class="mw-wz-draw-tips-list">
            <li><strong>Click</strong> anywhere on the map to add a corner point</li>
            <li><strong>Double-click</strong> the last point (or click the first) to close the shape</li>
            <li>Aim for 4–8 points — trace the property edge, not just the house</li>
            <li>Press <kbd>Esc</kbd> or click <strong>Cancel Draw</strong> to start over</li>
          </ul>
        </div>

      </div>
      <div class="modal-footer d-flex justify-content-between align-items-center">
        <button type="button" class="btn btn-outline-danger btn-sm" id="wz-delete-btn" disabled
                onclick="wzDeleteZone()">
          <i data-feather="trash-2" style="width:14px;height:14px;"></i> Delete Zone
        </button>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="wz-cancel-draw-btn"
                  style="display:none" onclick="wzCancelDraw()">
            Cancel Draw
          </button>
          <button type="button" class="btn btn-outline-primary btn-sm mr-2" id="wz-draw-btn" disabled
                  onclick="wzStartDraw()">
            <i data-feather="edit-2" style="width:14px;height:14px;"></i> Draw Zone
          </button>
          <button type="button" class="btn btn-success btn-sm" id="wz-save-btn" disabled
                  onclick="wzSave()">
            <i data-feather="save" style="width:14px;height:14px;"></i> Save Zone
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="/crm/js/geofence/geofence-manager.js?v=2"></script>
<script>
(function() {
  // ── PHP data ─────────────────────────────────────────────────────────────
  var WZ_PLANS_BY_PROP = <?php echo json_encode($plansByProperty ?? []); ?>;
  var WZ_CSRF          = <?php echo json_encode(generateCSRFToken()); ?>;

  var wzMgr       = null;
  var wzPropLat   = 49.2827;
  var wzPropLng   = -123.1207;
  var wzMapReady  = false;

  // ── Open modal ────────────────────────────────────────────────────────────
  window.openWorkZoneModal = function(propId, lat, lng) {
    wzPropLat = lat && lat !== 0 ? lat : 49.2827;
    wzPropLng = lng && lng !== 0 ? lng : -123.1207;

    var plans = WZ_PLANS_BY_PROP[propId] || [];
    var sel   = document.getElementById('wz-plan-select');
    sel.innerHTML = '<option value="">-- Select a plan --</option>';
    plans.forEach(function(pl) {
      var opt   = document.createElement('option');
      opt.value = pl.id;
      var label = pl.plan_number;
      if (pl.title)             label += ' — ' + pl.title;
      else if (pl.service_type) label += ' — ' + pl.service_type.replace(/_/g,' ');
      if (pl.status !== 'active') label += ' (' + pl.status + ')';
      opt.textContent = label;
      sel.appendChild(opt);
    });

    // Auto-select if exactly one plan
    if (plans.length === 1) sel.value = plans[0].id;

    wzSetControls(false);
    wzShowStatus('');
    wzSetStep(1);
    wzSetHint('Select a job plan above to load the map and start drawing.', 'info');
    wzShowDrawTips(false);
    $('#workZoneModal').modal('show');
  };

  // ── Modal shown / hidden — bind after jQuery+Bootstrap have loaded ───────
  document.addEventListener('DOMContentLoaded', function() {
    $('#workZoneModal').on('shown.bs.modal', function() {
      wzMapReady = true;
      var planId = parseInt(document.getElementById('wz-plan-select').value) || null;
      if (planId) {
        wzInitMap(planId);
      } else {
        wzShowMapPlaceholder(true);
        wzSetStep(1);
        wzSetHint('Select a job plan above to load the map and start drawing.', 'info');
      }
      wzSafeFeather();
    });

    $('#workZoneModal').on('hidden.bs.modal', function() {
      wzDestroyMap();
      wzMapReady  = false;
      wzShowDrawTips(false);
    });
  });

  // ── Plan selector changed ─────────────────────────────────────────────────
  window.onWZPlanChange = function() {
    if (!wzMapReady) return;
    var planId = parseInt(document.getElementById('wz-plan-select').value) || null;
    wzDestroyMap();
    wzShowDrawTips(false);
    if (planId) {
      wzInitMap(planId);
    } else {
      wzShowMapPlaceholder(true);
      wzSetControls(false);
      wzSetStep(1);
      wzSetHint('Select a job plan above to load the map and start drawing.', 'info');
    }
  };

  function wzInitMap(planId) {
    wzShowMapPlaceholder(false);
    if (wzMgr) { wzMgr.destroy(); wzMgr = null; }

    wzMgr = new GeofenceManager({
      mapContainer: 'wz-map',
      planId:       planId,
      csrfToken:    WZ_CSRF,
      mode:         'edit',
      center:       [wzPropLat, wzPropLng],
      zoom:         18,
      onLoad: function(hasPolygon) {
        // Called after get_polygon response — show correct state
        if (hasPolygon) {
          wzSetStep(3, 'done');
          wzSetHint('Zone is active — crew will auto-track when they enter this area. Click <strong>Draw Zone</strong> to redraw it.', 'success');
        } else {
          wzSetStep(2);
          wzSetHint('Click <strong>Draw Zone</strong> below to start drawing the property boundary on the map.', 'info');
        }
        wzSetControls(true);
      },
      onSave: function(id) {
        wzShowStatus('Work zone saved — GPS auto-tracking activates on the next crew visit.', 'success');
        wzSetStep(3, 'done');
        wzSetHint('Zone saved! Crew will auto clock-in when they enter this area.', 'success');
        wzShowDrawTips(false);
        wzSetControls(true);
        document.getElementById('wz-draw-btn').style.display        = 'inline-block';
        document.getElementById('wz-cancel-draw-btn').style.display = 'none';
      },
      onDraw: function() {
        document.getElementById('wz-save-btn').disabled              = false;
        document.getElementById('wz-draw-btn').style.display         = 'inline-block';
        document.getElementById('wz-cancel-draw-btn').style.display  = 'none';
        wzSetStep(2);
        wzSetHint('Zone outlined — click <strong>Save Zone</strong> to activate GPS tracking.', 'success');
        wzShowDrawTips(false);
        wzSafeFeather();
      },
      onDelete: function() {
        wzShowStatus('Work zone removed.', 'info');
        wzSetStep(2);
        wzSetHint('Zone removed. Click <strong>Draw Zone</strong> to create a new boundary.', 'info');
        wzShowDrawTips(false);
        wzSetControls(true);
      },
    });
    wzMgr.init();

    // Fallback hint while map is loading (onLoad callback will update it)
    wzSetStep(2);
    wzSetHint('Map loading — click <strong>Draw Zone</strong> to outline the property boundary.', 'info');
    wzSetControls(true);
  }

  function wzDestroyMap() {
    if (wzMgr) { wzMgr.destroy(); wzMgr = null; }
  }

  // ── Button actions ────────────────────────────────────────────────────────
  window.wzStartDraw = function() {
    if (!wzMgr) return;
    wzMgr.startDraw();
    document.getElementById('wz-draw-btn').style.display        = 'none';
    document.getElementById('wz-cancel-draw-btn').style.display = 'inline-block';
    document.getElementById('wz-save-btn').disabled = true;
    wzSetStep(2);
    wzSetHint('Drawing mode active — click the map to place corners, then double-click to close the shape.', 'draw');
    wzShowDrawTips(true);
    wzSafeFeather();
  };

  window.wzCancelDraw = function() {
    if (!wzMgr) return;
    wzMgr.cancelDraw();
    document.getElementById('wz-draw-btn').style.display        = 'inline-block';
    document.getElementById('wz-cancel-draw-btn').style.display = 'none';
    wzSetStep(2);
    wzSetHint('Draw cancelled. Click <strong>Draw Zone</strong> again to start over.', 'info');
    wzShowDrawTips(false);
    wzSafeFeather();
  };

  window.wzSave = function() {
    if (!wzMgr) return;
    document.getElementById('wz-draw-btn').style.display        = 'inline-block';
    document.getElementById('wz-cancel-draw-btn').style.display = 'none';
    wzSetHint('Saving zone\u2026', 'info');
    wzMgr.save();
    wzSafeFeather();
  };

  window.wzDeleteZone = function() {
    if (!wzMgr) return;
    if (!confirm('Remove the work zone for this plan?\nThe crew will no longer be auto-tracked at this property.')) return;
    wzMgr.deletePolygon();
  };

  // ── Step indicator ────────────────────────────────────────────────────────
  function wzSetStep(n, doneTo) {
    [1, 2, 3].forEach(function(i) {
      var el = document.getElementById('wz-step-' + i);
      if (!el) return;
      el.className = 'mw-wz-step';
      if (doneTo === 'done' && i <= n) {
        el.className += ' mw-wz-step--done';
      } else if (i === n) {
        el.className += ' mw-wz-step--active';
      } else if (i < n) {
        el.className += ' mw-wz-step--done';
      }
    });
  }

  // ── Contextual hint ───────────────────────────────────────────────────────
  function wzSetHint(html, variant) {
    var el   = document.getElementById('wz-hint');
    var text = document.getElementById('wz-hint-text');
    if (!el || !text) return;
    text.innerHTML = html;
    el.className   = 'mw-wz-hint mt-2 mw-wz-hint--' + (variant || 'info');
  }

  // ── Drawing tips panel ────────────────────────────────────────────────────
  function wzShowDrawTips(show) {
    var el = document.getElementById('wz-draw-tips');
    if (el) el.style.display = show ? 'block' : 'none';
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function wzSetControls(enabled) {
    ['wz-draw-btn', 'wz-save-btn', 'wz-delete-btn'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.disabled = !enabled;
    });
    if (!enabled) {
      document.getElementById('wz-cancel-draw-btn').style.display = 'none';
      document.getElementById('wz-draw-btn').style.display        = 'inline-block';
    }
  }

  function wzShowMapPlaceholder(show) {
    var map = document.getElementById('wz-map');
    var ph  = document.getElementById('wz-map-placeholder');
    if (map) map.style.display = show ? 'none' : 'block';
    if (ph)  ph.style.display  = show ? 'flex'  : 'none';
  }

  function wzShowStatus(msg, type) {
    var el = document.getElementById('wz-status');
    if (!el) return;
    if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
    el.className = 'mw-wz-status mt-2 alert alert-' + (type === 'success' ? 'success' : type === 'info' ? 'info' : 'secondary');
    el.textContent = msg;
    el.style.display = 'block';
  }

  function wzSafeFeather() {
    if (typeof feather !== 'undefined') feather.replace();
  }
})();
</script>

<?php endif; ?>

<?php include 'includes/appstack_footer.php'; ?>
