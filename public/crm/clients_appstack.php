<?php
/**
 * Client Management - List, Create, Edit, Delete
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/error-handler.php';
require_once APP_ROOT . '/Modules/Contacts/Services/ContactService.php';
require_once APP_ROOT . '/Modules/Contacts/Services/ClientActionsService.php';
require_once APP_ROOT . '/Core/Uploads.php';

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

// Flash messages passed via GET after a redirect
if (isset($_GET['updated'])) {
    $message = 'Contact updated.';
    if (!empty($_GET['warn'])) {
        $message .= ' Note: ' . htmlspecialchars($_GET['warn']) . ' contained an invalid value and was cleared.';
    }
    $messageType = 'success';
}
if (isset($_GET['portal_link'])) {
    $message = 'New client portal link issued — the old link no longer works. Open Client Portal to copy the new one.';
    $messageType = 'success';
}

// Handle JSON requests (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $requestAction = $_GET['action'] ?? null;
    $clientActions = new ClientActionsService($db);

    if ($requestAction === 'move_company') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
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
                echo json_encode(['success' => false, 'error' => 'Couldn\'t update the stage. Try again.']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => htmlspecialchars($e->getMessage())]);
        }
        exit;
    } elseif ($requestAction === 'move_contact') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
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
                echo json_encode(['success' => false, 'error' => 'Couldn\'t update the stage. Try again.']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => htmlspecialchars($e->getMessage())]);
        }
        exit;
    } elseif ($requestAction === 'manage_stages') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
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
                    echo json_encode(['success' => false, 'error' => 'Couldn\'t add the stage. Try again.']);
                }
            } elseif ($stageAction === 'update') {
                if (updateLifecycleStage($stageId, $jsonData)) {
                    http_response_code(200);
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Couldn\'t update the stage. Try again.']);
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
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }
        // Hard delete is an office decision (crew hold clients.view only) — Fable review 2026-10-12.
        if (!userHasPermission('clients.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to delete clients']);
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
            // Never cascade-delete invoices or strand job plans: a client with any quote, job
            // plan or invoice is skipped (archive it instead).
            require_once APP_ROOT . '/Modules/Contacts/Services/CompanyDeleteGuard.php';
            $guard     = new CompanyDeleteGuard($db);
            $deletable = $guard->deletable($ids);
            $skipped   = count($ids) - count($deletable);
            $deleted   = 0;

            if ($deletable) {
                $placeholders = implode(',', array_fill(0, count($deletable), '?'));
                // Never delete audit rows: cut their link to the company instead (Fable audit #25).
                require_once APP_ROOT . '/Modules/Technology/Services/AuditTrail.php';
                $db->beginTransaction();
                $deletedNames = AuditTrail::detachCompanies($db, $deletable);
                $stmt = $db->prepare("DELETE FROM companies WHERE id IN ({$placeholders})");
                $stmt->execute($deletable);
                $deleted = $stmt->rowCount();
                $db->commit();
                AuditTrail::logCompaniesDeleted(isset($user['id']) ? (int)$user['id'] : null, $deletedNames);
            }

            echo json_encode([
                'success'       => true,
                'deleted_count' => $deleted,
                'skipped_count' => $skipped,
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Couldn\'t delete this. Try again.']);
        }
        exit;

    } elseif ($requestAction === 'convert_to_prospect') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->convertToProspect(intval($jsonData['request_id'] ?? 0));
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'add_property_to_contact') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->addPropertyToContact(
            intval($jsonData['contact_id'] ?? 0),
            trim($jsonData['address'] ?? ''),
            trim($jsonData['city'] ?? 'Vancouver'),
            trim($jsonData['postal_code'] ?? ''),
            trim($jsonData['property_name'] ?? '')
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'add_property_to_company') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $lat = isset($jsonData['latitude']) && $jsonData['latitude'] !== '' ? floatval($jsonData['latitude']) : null;
        $lng = isset($jsonData['longitude']) && $jsonData['longitude'] !== '' ? floatval($jsonData['longitude']) : null;
        $result = $clientActions->addPropertyToCompany(
            intval($jsonData['company_id'] ?? 0),
            trim($jsonData['address'] ?? ''),
            trim($jsonData['city'] ?? 'Vancouver'),
            trim($jsonData['postal_code'] ?? ''),
            trim($jsonData['property_name'] ?? ''),
            $lat,
            $lng
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'link_company_to_contact') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->linkCompanyToContact(
            intval($jsonData['contact_id'] ?? 0),
            intval($jsonData['company_id'] ?? 0)
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'save_property_coords') {
        // Save lat/lng from client-side geocoding
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->savePropertyCoords(
            intval($jsonData['property_id'] ?? 0),
            floatval($jsonData['lat'] ?? 0),
            floatval($jsonData['lng'] ?? 0)
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'edit_property') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->editProperty(
            intval($jsonData['property_id'] ?? 0),
            trim($jsonData['address'] ?? ''),
            trim($jsonData['city'] ?? ''),
            trim($jsonData['province'] ?? ''),
            trim($jsonData['postal_code'] ?? ''),
            trim($jsonData['property_name'] ?? '')
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'unlink_property') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->unlinkProperty(
            intval($jsonData['property_id'] ?? 0),
            intval($jsonData['current_contact_id'] ?? 0),
            intval($jsonData['new_contact_id'] ?? 0)
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;

    } elseif ($requestAction === 'add_client_note') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This page timed out. Reload it and try again.']);
            exit;
        }

        $result = $clientActions->addClientNote(
            intval($jsonData['contact_id'] ?? 0),
            trim($jsonData['note_type'] ?? 'general'),
            trim($jsonData['content'] ?? ''),
            intval($user['id'])
        );
        http_response_code($result['status']);
        echo json_encode($result['ok']
            ? array_merge(['success' => true], $result['data'])
            : ['success' => false, 'error' => $result['error']]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'This page timed out. Reload it and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? null;

        if ($action === 'save_client') {
            // ── Contact fields ──
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = sanitizePhone($_POST['phone'] ?? '');
            $mobile = sanitizePhone($_POST['mobile'] ?? '');
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
                                trim($firstName . ' ' . $lastName),
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
                            $message = 'Contact and company added.';
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
                        $message = 'Contact added.';
                        $messageType = 'success';
                    }

                    $db->commit();
                    $action = null; // Return to list view
                } catch (PDOException $e) {
                    $db->rollBack();
                    $errorHandler->logDatabaseError($e, '', [], 'Couldn\'t save this contact. Nothing was changed. Try again.');
                    $message = 'Couldn\'t save this contact. Nothing was changed. Try again.';
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'update_contact') {
            $contactId = intval($_POST['contact_id'] ?? 0);
            if ($contactId) {
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $email     = trim($_POST['email'] ?? '');
                $rawPhone  = trim($_POST['phone'] ?? '');
                $rawMobile = trim($_POST['mobile'] ?? '');
                $phone     = sanitizePhone($rawPhone);
                $mobile    = sanitizePhone($rawMobile);
                $preferredContact = $_POST['preferred_contact_method'] ?? 'phone';
                $receiveSms = isset($_POST['receive_sms']) ? 1 : 0;
                $receiveMarketing = isset($_POST['receive_marketing']) ? 1 : 0;
                $consentQuoteFollowup = isset($_POST['consent_quote_followup']) ? 1 : 0;
                $hasReviewed = isset($_POST['has_reviewed']) ? 1 : 0;
                $notes = trim($_POST['notes'] ?? '');
                $contactRole       = trim($_POST['contact_role'] ?? '');
                $employerCompanyId = (int)($_POST['employer_company_id'] ?? 0);

                if (empty($firstName)) {
                    $message = 'Please enter a first name.';
                    $messageType = 'error';
                    $action = 'edit_contact';
                    $clientId = $contactId;
                } else {
                    try {
                        // Fetch old values for field change tracking
                        $oldStmt = $db->prepare("SELECT first_name, last_name, email, phone, mobile, preferred_contact_method, receive_sms, receive_marketing, consent_quote_followup, notes FROM contacts WHERE id = ?");
                        $oldStmt->execute([$contactId]);
                        $oldContact = $oldStmt->fetch(PDO::FETCH_ASSOC);

                        $stmt = $db->prepare("
                            UPDATE contacts SET
                                first_name = ?, last_name = ?, email = ?, phone = ?, mobile = ?,
                                preferred_contact_method = ?, receive_sms = ?, receive_marketing = ?,
                                consent_quote_followup = ?, has_reviewed = ?, notes = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $firstName, $lastName, $email, $phone, $mobile,
                            $preferredContact, $receiveSms, $receiveMarketing,
                            $consentQuoteFollowup, $hasReviewed, $notes, $contactId
                        ]);

                        // Update pipeline stage if provided
                        // Only a real change is a manual choice (and pins the stage) — the select
                        // is always posted, so an untouched form must not pin anything.
                        $newLifecycleStage = trim($_POST['lifecycle_stage'] ?? '');
                        $oldLifecycleStage = trim((string)($_POST['lifecycle_stage_was'] ?? ''));
                        if (!empty($_POST['lifecycle_unpin'])) {
                            setContactLifecyclePinned((int)$contactId, false);   // let Sam manage it
                        } elseif ($newLifecycleStage !== '' && $newLifecycleStage !== $oldLifecycleStage) {
                            updateContactLifecycleStage($contactId, $newLifecycleStage, $user['id']);
                        }

                        // Update role & employer company
                        try {
                            $contactService = new ContactService($db);
                            $contactService->updateContactRoleAndEmployer(
                                $contactId,
                                $contactRole ?: null,
                                $employerCompanyId ?: null
                            );
                        } catch (Throwable $roleErr) {
                            // Graceful — columns may not exist yet if migration hasn't run
                        }

                        // Track field changes
                        if ($oldContact) {
                            trackFieldChanges('contact', $contactId, $oldContact, [
                                'first_name' => $firstName, 'last_name' => $lastName,
                                'email' => $email, 'phone' => $phone, 'mobile' => $mobile,
                                'preferred_contact_method' => $preferredContact,
                                'receive_sms' => (string)$receiveSms, 'receive_marketing' => (string)$receiveMarketing,
                                'consent_quote_followup' => (string)$consentQuoteFollowup,
                                'has_reviewed' => (string)$hasReviewed, 'notes' => $notes,
                            ], $user['id']);
                        }

                        // Warn if an invalid phone value was silently cleared
                        $sanitizeWarnings = [];
                        if ($rawPhone !== '' && $phone === '') $sanitizeWarnings[] = 'Phone';
                        if ($rawMobile !== '' && $mobile === '') $sanitizeWarnings[] = 'Cell / Mobile';
                        $warningParam = $sanitizeWarnings ? '&warn=' . urlencode(implode(',', $sanitizeWarnings)) : '';
                        header("Location: clients_appstack.php?action=view_contact&id={$contactId}&updated=1{$warningParam}");
                        exit;
                    } catch (PDOException $e) {
                        $errorHandler->logDatabaseError($e, '', [], 'Failed to update contact.');
                        $message = 'Couldn\'t update this contact. Nothing was changed. Try again.';
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
                        $message = 'Company updated.';
                        $messageType = 'success';
                        // Redirect to view
                        $action = 'view_company';
                        $clientId = $companyId;
                    } catch (PDOException $e) {
                        $errorHandler->logDatabaseError($e, '', [], 'Failed to update company.');
                        $message = 'Couldn\'t update this company. Nothing was changed. Try again.';
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

        // Employer company (the firm this contact works for, distinct from primary_contact linkage)
        $employerCompany = null;
        if (!empty($viewContact['employer_company_id'])) {
            try {
                $empStmt = $db->prepare("SELECT id, company_name, company_type FROM companies WHERE id = ? LIMIT 1");
                $empStmt->execute([(int)$viewContact['employer_company_id']]);
                $employerCompany = $empStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { /* ok */ }
        }

        // Properties managed by this contact (site_contact or employer PM)
        $managedProperties = [];
        if (!empty($viewContact['contact_role']) || !empty($viewContact['employer_company_id'])) {
            try {
                $contactSvc = new ContactService($db);
                $managedProperties = $contactSvc->getManagedProperties((int)$clientId);
            } catch (Throwable $e) { $managedProperties = []; }
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
                       q.created_at, q.sent_at, q.accepted_at, q.property_id, p.address AS property_address
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
                       jp.property_id, jp.quote_id,
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

        // ── Build unified service lifecycle data (property → quotes → plans) ──
        $serviceLifecycle = [];
        foreach ($contactProperties as $prop) {
            $propId = (int)$prop['id'];
            $serviceLifecycle[$propId] = [
                'property'         => $prop,
                'tags'             => $propertyTagMap[$propId] ?? [],
                'quotes'           => [],
                'plans_with_quote' => [],
                'plans_no_quote'   => [],
            ];
        }
        foreach ($contactQuotes as $q) {
            $propId = (int)($q['property_id'] ?? 0);
            if (isset($serviceLifecycle[$propId])) {
                $serviceLifecycle[$propId]['quotes'][(int)$q['id']] = $q;
            }
        }
        foreach ($contactPlans as $pl) {
            $propId  = (int)($pl['property_id'] ?? 0);
            $quoteId = !empty($pl['quote_id']) ? (int)$pl['quote_id'] : null;
            if (!isset($serviceLifecycle[$propId])) continue;
            if ($quoteId && isset($serviceLifecycle[$propId]['quotes'][$quoteId])) {
                $serviceLifecycle[$propId]['plans_with_quote'][$quoteId][] = $pl;
            } else {
                $serviceLifecycle[$propId]['plans_no_quote'][] = $pl;
            }
        }

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
                           i.issue_date, i.due_date, i.amount_paid,
                           i.payment_method, i.payment_reference
                    FROM invoices i
                    WHERE {$whereClause}
                    ORDER BY i.created_at DESC
                ");
                $stmt->execute($params);
                $contactInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) { $contactInvoices = []; }

        // ── Candidate bank-deposit matches for this contact's unpaid invoices ──
        // Same inline reconciliation as /crm/invoices/index.php ("likely match" pill).
        $contactInvoiceMatches = [];
        try {
            $__payableIds = [];
            foreach ($contactInvoices as $__inv) {
                if (in_array($__inv['status'], ['sent', 'viewed', 'partial', 'overdue'], true)
                    && (float)$__inv['balance_due'] > 0.005) {
                    $__payableIds[] = (int)$__inv['id'];
                }
            }
            $__svcFile = APP_ROOT . '/Modules/Accounting/Services/InvoiceReconciliationService.php';
            if ($__payableIds && is_file($__svcFile)) {
                require_once $__svcFile;
                $contactInvoiceMatches = (new InvoiceReconciliationService($db))->topCandidatesForInvoiceIds($__payableIds);
            }
            unset($__payableIds, $__inv, $__svcFile);
        } catch (Throwable $__e) {
            error_log('[clients/view_contact] candidate match error: ' . $__e->getMessage());
            $contactInvoiceMatches = [];
        }

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
                // Cancelled invoices represent no real financial event — exclude
                // them from the statement so voided/duplicate bills don't inflate
                // "total charged" or the running balance.
                if (($inv['status'] ?? '') === 'cancelled') { continue; }

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
        $geocodedCount = 0;
        $ungeocodedCount = 0;
        foreach ($contactProperties as $_gp) {
            $lat = floatval($_gp['latitude'] ?? 0);
            $lng = floatval($_gp['longitude'] ?? 0);
            if ($lat != 0 && $lng != 0) $geocodedCount++;
            else $ungeocodedCount++;
        }
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
if ($action === 'view_contact' || $action === 'new' || $action === 'edit_contact') {
    $existingCompaniesForLink = $db->query("SELECT id, company_name, company_type FROM companies ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get all clients and prospects
// Prospects are created from quote_requests
// Stats for the metrics banner
$statsTotalActive  = (int)$db->query("SELECT COUNT(*) FROM companies WHERE account_status = 'active'")->fetchColumn();
$statsNewThisMonth = (int)$db->query("SELECT COUNT(*) FROM companies WHERE account_status = 'active' AND YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())")->fetchColumn();
$statsOverdueAmt   = (float)$db->query("SELECT COALESCE(SUM(balance_due), 0) FROM invoices WHERE status IN ('overdue', 'sent', 'viewed') AND balance_due > 0")->fetchColumn();
$statsOverdueCount = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status = 'overdue' AND balance_due > 0")->fetchColumn();
$statsContactsTotal = (int)$db->query("SELECT COUNT(*) FROM contacts WHERE is_active = 1")->fetchColumn();

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
        pc.phone as primary_contact_phone,
        (SELECT MAX(al.created_at) FROM activity_log al WHERE al.company_id = c.id) as last_activity_at
    FROM companies c
    LEFT JOIN quote_requests qr ON c.id = qr.company_id AND qr.status IN ('new', 'reviewing')
    LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
    ORDER BY last_activity_at DESC, c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get standalone contacts (not linked to any company as primary or billing contact)
try {
    // Check if billing_contact_id column exists
    $cols = $db->query("SHOW COLUMNS FROM companies LIKE 'billing_contact_id'")->fetchAll();
    $hasBillingContact = count($cols) > 0;

    // Check if prospect_status column exists on contacts
    $cols2 = $db->query("SHOW COLUMNS FROM contacts LIKE 'prospect_status'")->fetchAll();
    $hasProspectStatus = count($cols2) > 0;

    // Check if lifecycle_stage column exists — add it if not (safe inline migration, outside transaction)
    $colsLC = $db->query("SHOW COLUMNS FROM contacts LIKE 'lifecycle_stage'")->fetchAll();
    $hasLifecycleStage = count($colsLC) > 0;
    if (!$hasLifecycleStage) {
        try {
            $db->exec("ALTER TABLE contacts ADD COLUMN lifecycle_stage VARCHAR(50) DEFAULT NULL AFTER prospect_status");
            // Seed from prospect_status so existing cards land in the right column
            $db->exec("UPDATE contacts SET lifecycle_stage = prospect_status WHERE lifecycle_stage IS NULL AND prospect_status IS NOT NULL");
            $hasLifecycleStage = true;
        } catch (Exception $ignore) {}
    }

    $excludeSubquery = "SELECT COALESCE(primary_contact_id, 0) FROM companies";
    if ($hasBillingContact) {
        $excludeSubquery .= " UNION SELECT COALESCE(billing_contact_id, 0) FROM companies";
    }

    $prospectCol      = $hasProspectStatus  ? "ct.prospect_status"  : "'prospect' as prospect_status";
    $lifecycleStageCol = $hasLifecycleStage
        ? "COALESCE(ct.lifecycle_stage, ct.prospect_status) as kanban_stage"
        : ($hasProspectStatus ? "ct.prospect_status as kanban_stage" : "'prospect' as kanban_stage");

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
            {$lifecycleStageCol},
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

// Ittai list skin (css/ittai-lists.css) on the clients list only, not on the forms and detail views. Applies only when the new design is on.
if (!$action) $bodyClass = 'it-list';
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
                  <button type="button" class="btn-close" data-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['alert']); ?>
          <?php endif; ?>

          <?php if (!in_array($action, ['view_contact', 'view_company'])): ?>
          <div class="mw-page-header">
            <div class="mw-page-header-left">
              <h1 class="mw-page-title"><?php echo (($_GET['view'] ?? '') === 'duplicates') ? 'Review duplicate contacts' : 'Clients'; ?></h1>
              <p class="mw-page-subtitle">The people and companies you quote and work for</p>
            </div>
            <?php if (!in_array($action, ['edit', 'new', 'edit_contact', 'edit_company']) && ($_GET['view'] ?? '') !== 'duplicates'): ?>
            <div class="mw-page-actions">
              <a href="/crm/api/export-contacts.php" class="btn btn-outline-secondary" title="Export CSV"><i data-feather="download"></i> Export</a>
              <button class="btn btn-primary" onclick="location.href='?action=new'"><i data-feather="plus"></i> Add client</button>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
              <?php echo htmlspecialchars($message); ?>
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          <?php endif; ?>

          <?php if ($action === 'edit' || $action === 'new'): ?>
          <?php include __DIR__ . '/includes/clients/new-edit-client.php'; ?>
          <?php elseif ($action === 'edit_contact' && $contact): ?>
          <?php include __DIR__ . '/includes/clients/edit-contact.php'; ?>
          <?php elseif ($action === 'view_contact' && $viewContact): ?>
          <?php include __DIR__ . '/includes/clients/view-contact.php'; ?>
          <?php elseif ($action === 'edit_company' && $editCompany): ?>
          <?php include __DIR__ . '/includes/clients/edit-company.php'; ?>
          <?php elseif ($action === 'view_company' && $viewCompany): ?>
          <?php include __DIR__ . '/includes/clients/view-company.php'; ?>
          <?php else: ?>
          <?php include __DIR__ . '/includes/clients/view-list.php'; ?>
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

          <script>(window.mwClients = window.mwClients || {}).clients_pipeline = { c0: <?php echo json_encode(csrf_token()); ?>, c1: <?php echo json_encode(generateCSRFToken()); ?> };</script>
          <script src="<?= _av('/crm/js/clients-pipeline.js') ?>"></script>

<?php if ($action === 'view_contact' && $viewContact): ?>
          <?php include __DIR__ . '/includes/clients/view-contact-modals.php'; ?>
<?php endif; ?>

<script src="<?= _av('/crm/js/clients-phone-check.js') ?>"></script>

<?php include 'includes/appstack_footer.php'; ?>
