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
                   p.lot_size_sqft, p.status, p.notes
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

        // Load Google Maps API (geometry for map display, places for address autocomplete)
        $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        if ($apiKey) {
            $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=geometry,places" defer></script>';
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
                       p.lot_size_sqft, p.status, p.site_contact_id
                FROM properties p
                WHERE p.site_contact_id IN ({$cPlaceholders})
                ORDER BY p.address ASC
            ");
            $stmt->execute(array_values($contactIds));
            $companyProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Load Google Maps API for address autocomplete on add property modal
        $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        if ($apiKey && empty($extraHead)) {
            $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=places" defer></script>';
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
            ct.notes
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
            <h1 class="h3 mb-0">Client Management</h1>
            <?php if (!in_array($action, ['edit', 'new', 'view_contact', 'edit_contact', 'view_company', 'edit_company'])): ?>
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
                      <div class="form-group">
                        <label>Cell / Mobile</label>
                        <input type="tel" class="form-control" name="mobile"
                          value="<?php echo h($_POST['mobile'] ?? ''); ?>"
                          placeholder="604-555-5678">
                        <small class="form-text text-muted">Used for SMS notifications</small>
                      </div>
                    </div>
                  </div>
                  <div class="form-group mb-0">
                    <label>Notes</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="Any additional info..."><?php echo h($_POST['notes'] ?? ''); ?></textarea>
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

              <!-- Card 3: Communication Preferences -->
              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Communication Preferences</h5>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select class="form-control" name="preferred_contact_method">
                          <option value="phone" <?php echo ($_POST['preferred_contact_method'] ?? 'phone') === 'phone' ? 'selected' : ''; ?>>Phone</option>
                          <option value="email" <?php echo ($_POST['preferred_contact_method'] ?? '') === 'email' ? 'selected' : ''; ?>>Email</option>
                          <option value="text" <?php echo ($_POST['preferred_contact_method'] ?? '') === 'text' ? 'selected' : ''; ?>>Text / SMS</option>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div class="mw-comm-prefs">
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

              <!-- Card 3: Company (Optional) -->
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
                    <div class="row">
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Company Name</label>
                          <input type="text" class="form-control" name="company_name"
                            value="<?php echo h($_POST['company_name'] ?? ''); ?>"
                            placeholder="e.g. Smith Landscaping Ltd.">
                        </div>
                      </div>
                      <div class="col-md-6">
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
                    </div>
                  </div>

                  <hr>

                  <!-- Billing / Account info (only when company is linked) -->
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
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Account Status</label>
                        <select class="form-control" name="account_status">
                          <option value="active">Active</option>
                          <option value="inactive">Inactive</option>
                          <option value="suspended">Suspended</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Payment Terms</label>
                        <input type="text" class="form-control" name="payment_terms"
                          value="<?php echo h($_POST['payment_terms'] ?? 'Net 30'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="custom-control custom-checkbox mt-2">
                          <input type="checkbox" class="custom-control-input" id="prefAttachPdf" name="pref_attach_pdf" checked>
                          <label class="custom-control-label" for="prefAttachPdf">Attach PDF to quote emails</label>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Submit -->
              <div class="form-group mt-3">
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
                      <div class="form-group">
                        <label>Cell / Mobile</label>
                        <input type="tel" class="form-control" name="mobile"
                          value="<?php echo h($_POST['mobile'] ?? $contact['mobile'] ?? ''); ?>">
                        <small class="form-text text-muted">Used for SMS notifications</small>
                      </div>
                    </div>
                  </div>
                  <div class="form-group mb-0">
                    <label>Notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?php echo h($_POST['notes'] ?? $contact['notes'] ?? ''); ?></textarea>
                  </div>
                </div>
              </div>

              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="message-circle"></i> Communication Preferences</h5>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select class="form-control" name="preferred_contact_method">
                          <?php $pref = $_POST['preferred_contact_method'] ?? $contact['preferred_contact_method'] ?? 'phone'; ?>
                          <option value="phone" <?php echo $pref === 'phone' ? 'selected' : ''; ?>>Phone</option>
                          <option value="email" <?php echo $pref === 'email' ? 'selected' : ''; ?>>Email</option>
                          <option value="text" <?php echo $pref === 'text' ? 'selected' : ''; ?>>Text / SMS</option>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div class="mw-comm-prefs">
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

              <div class="form-group mt-3">
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

                    <?php if (!empty($viewContact['notes'])): ?>
                    <div class="row mb-0">
                      <div class="col-sm-3 text-muted">Notes</div>
                      <div class="col-sm-9"><span class="text-muted"><?php echo nl2br(h($viewContact['notes'])); ?></span></div>
                    </div>
                    <?php endif; ?>
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

              </div><!-- end left col -->

              <!-- Right Column: Map -->
              <div class="col-lg-5">
                <div class="card mb-3">
                  <div class="card-header">
                    <h5 class="card-title mb-0"><i data-feather="map"></i> Property Map</h5>
                  </div>
                  <div class="card-body p-0">
                    <?php if (!empty($contactProperties) && $geocodedCount > 0): ?>
                      <div id="contactMapContainer" class="mw-contact-map-container"></div>
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
              var availableTags = <?php echo json_encode($availableTags); ?>;
              var gmap = null;
              var markers = {};

              // ── Google Map ──────────────────────────────────────
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
                  streetViewControl: false
                });

                propertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: prop.address
                  });

                  var infoContent = '<div style="max-width: 250px;">' +
                    '<strong>' + escHtml(prop.address) + '</strong><br>' +
                    '<small>' + escHtml((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() { infoWindow.open(gmap, marker); });

                  markers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  gmap.fitBounds(bounds);
                  if (Object.keys(markers).length === 1) {
                    google.maps.event.addListenerOnce(gmap, 'bounds_changed', function() {
                      gmap.setZoom(15);
                    });
                  }
                }
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
                    // Geocode the new property asynchronously then reload
                    geocodePropertyAjax(data.property_id).finally(function() {
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
                      <?php foreach ($companyProperties as $prop): ?>
                        <div class="mw-contact-property-item">
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
                          </div>
                          <span class="badge badge-<?php echo ($prop['status'] ?? 'active') === 'active' ? 'success' : 'secondary'; ?>">
                            <?php echo ucfirst(h($prop['status'] ?? 'active')); ?>
                          </span>
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

            <script>
              var COMPANY_ID = <?php echo (int)$viewCompany['id']; ?>;
              var CSRF_TOKEN_CO = '<?php echo csrf_token(); ?>';

              // Google Places autocomplete for company property modal — uses shared function
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof initAddressAutocomplete === 'function') {
                  initAddressAutocomplete('compPropAddress', 'compPropCity', 'compPropPostalCode', null, 'compPropLat', 'compPropLng');
                }
              });

              // Submit handler
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
            </script>

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
                          <tr class="mw-client-row" data-search="<?php echo h(strtolower(trim(($c['primary_contact_name'] ?? '') . ' ' . $c['company_name'] . ' ' . ($c['billing_email'] ?? '') . ' ' . ($c['primary_contact_phone'] ?? '')))); ?>" <?php echo $c['source_type'] === 'prospect' ? 'style="background: #fef3c7; opacity: 0.9;"' : ''; ?>>
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
                          <tr class="mw-client-row" data-search="<?php echo h(strtolower(trim(($ct['first_name'] ?? '') . ' ' . ($ct['last_name'] ?? '') . ' ' . ($ct['email'] ?? '') . ' ' . ($ct['phone'] ?? '')))); ?>">
                            <td>
                              <a href="?action=view_contact&id=<?php echo (int)$ct['id']; ?>" class="mw-client-name-link">
                                <strong><?php echo h(trim($ct['first_name'] . ' ' . ($ct['last_name'] ?? ''))); ?></strong>
                              </a>
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
          <?php endif; ?>

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

<?php include 'includes/appstack_footer.php'; ?>
