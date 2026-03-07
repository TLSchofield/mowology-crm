<?php
/**
 * Create/Edit Quote
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/error-handler.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

$db = getDB();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Create Quote', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;
$error = '';
$success = '';

// Check if editing existing quote or creating from quote request
$quoteId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$quoteRequestId = isset($_GET['quote_request_id']) ? intval($_GET['quote_request_id']) : 0;
$quote = null;
$lineItems = [];
$quoteRequest = null;
$prefilledPropertyId = 0;

if ($quoteId) {
    $stmt = $db->prepare("SELECT * FROM quotes WHERE id = ?");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($quote) {
        $stmt = $db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$quoteId]);
        $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($quoteRequestId) {
    // Load quote request data to pre-populate the form
    $stmt = $db->prepare("
        SELECT qr.*, c.first_name, c.last_name, p.id as property_id, p.address, p.city
        FROM quote_requests qr
        LEFT JOIN contacts c ON qr.contact_id = c.id
        LEFT JOIN properties p ON qr.property_id = p.id
        WHERE qr.id = ?
    ");
    $stmt->execute([$quoteRequestId]);
    $quoteRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($quoteRequest && $quoteRequest['property_id']) {
        $prefilledPropertyId = intval($quoteRequest['property_id']);
    }
}

// Determine pre-selected contact for edit mode
$prefilledContactId = 0;
$prefilledContactName = '';
$prefilledClientType = 'contact';
$prefilledProperties = [];

if ($quote && $quote['property_id']) {
    // Editing existing quote — look up the contact from the property
    $stmt = $db->prepare("
        SELECT p.site_contact_id, ct.first_name, ct.last_name
        FROM properties p
        LEFT JOIN contacts ct ON p.site_contact_id = ct.id
        WHERE p.id = ?
    ");
    $stmt->execute([$quote['property_id']]);
    $propContact = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($propContact && $propContact['site_contact_id']) {
        $prefilledContactId = intval($propContact['site_contact_id']);
        $prefilledContactName = trim($propContact['first_name'] . ' ' . $propContact['last_name']);
        // Load that contact's properties
        $stmt = $db->prepare("SELECT id, address, city, province, postal_code, property_type, status, latitude, longitude FROM properties WHERE site_contact_id = ? AND status = 'active' ORDER BY address");
        $stmt->execute([$prefilledContactId]);
        $prefilledProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($quoteRequest && $quoteRequest['contact_id']) {
    // Creating from quote request — pre-fill the contact
    $prefilledContactId = intval($quoteRequest['contact_id']);
    $prefilledContactName = trim(($quoteRequest['first_name'] ?? '') . ' ' . ($quoteRequest['last_name'] ?? ''));
    $stmt = $db->prepare("SELECT id, address, city, province, postal_code, property_type, status, latitude, longitude FROM properties WHERE site_contact_id = ? AND status = 'active' ORDER BY address");
    $stmt->execute([$prefilledContactId]);
    $prefilledProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_GET['contact_id']) && !empty($_GET['property_id'])) {
    // Direct entry from client page — pre-fill contact + property
    $directContactId = intval($_GET['contact_id']);
    $directPropertyId = intval($_GET['property_id']);
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM contacts WHERE id = ?");
    $stmt->execute([$directContactId]);
    $directContact = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($directContact) {
        $prefilledContactId = $directContactId;
        $prefilledContactName = trim($directContact['first_name'] . ' ' . $directContact['last_name']);
        $prefilledPropertyId = $directPropertyId;
        $stmt = $db->prepare("SELECT id, address, city, province, postal_code, property_type, status, latitude, longitude FROM properties WHERE site_contact_id = ? AND status = 'active' ORDER BY address");
        $stmt->execute([$prefilledContactId]);
        $prefilledProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Load products from catalog for template dropdown
// Uses p.* to avoid missing-column errors; category/unit joined same as api-products.php
$templates = [];
try {
    $stmt = $db->query("
        SELECT p.*,
               c.name as category_name,
               u.abbreviation as unit_abbreviation
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.id
        LEFT JOIN unit_types u ON p.unit_type_id = u.id
        WHERE p.is_archived = 0
        ORDER BY p.display_order, p.name
    ");
    if ($stmt) {
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    // products table or required columns may not exist yet
    error_log('create.php templates query failed: ' . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $propertyId = intval($_POST['property_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $serviceType = $_POST['service_type'] ?? 'landscaping';
        $validUntil = $_POST['valid_until'] ?? null;
        $terms = trim($_POST['terms'] ?? '');
        $notesCustomer = trim($_POST['notes_customer'] ?? '');
        $notesInternal = trim($_POST['notes_internal'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isContract = !empty($_POST['is_contract']) ? 1 : 0;

        // Parse line items from JSON
        $lineItemsJson = $_POST['line_items'] ?? '[]';
        $newLineItems = json_decode($lineItemsJson, true) ?: [];

        // Validate
        if (!$propertyId) {
            $error = 'Please select a property.';
        } elseif (empty($newLineItems)) {
            $error = 'Please add at least one line item.';
        } else {
            try {
                // Ensure required columns exist (migrations 930, contract)
                $schemaPatch = [
                    "ALTER TABLE `quote_line_items` ADD COLUMN `section_name` VARCHAR(100) NULL DEFAULT NULL AFTER `sort_order`",
                    "ALTER TABLE `quotes` ADD COLUMN `is_contract` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`",
                ];
                foreach ($schemaPatch as $ddl) {
                    try { $db->exec($ddl); } catch (\Throwable $ignore) {}
                }

                $db->beginTransaction();

                // Calculate totals
                $totals = calculateQuoteTotals($newLineItems);

                if ($quoteId) {
                    // Update existing quote
                    // Get the company_id associated with the selected property
                    $propertyStmt = $db->prepare("
                        SELECT c.id as company_id
                        FROM properties p
                        LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
                        LEFT JOIN companies c ON cp.company_id = c.id
                        WHERE p.id = ?
                        LIMIT 1
                    ");
                    $propertyStmt->execute([$propertyId]);
                    $propertyData = $propertyStmt->fetch(PDO::FETCH_ASSOC);
                    $companyId = $propertyData['company_id'] ?? null;

                    $stmt = $db->prepare("
                        UPDATE quotes SET
                            property_id = ?,
                            company_id = ?,
                            title = ?,
                            service_type = ?,
                            amount = ?,
                            subtotal = ?,
                            tax_rate = ?,
                            tax_amount = ?,
                            valid_until = ?,
                            terms = ?,
                            notes_customer = ?,
                            notes_internal = ?,
                            description = ?,
                            is_contract = ?,
                            pdf_path = NULL,
                            pdf_version = 0,
                            pdf_generated_at = NULL,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $propertyId, $companyId, $title, $serviceType, $totals['total'],
                        $totals['subtotal'], $totals['tax_rate'], $totals['tax_amount'],
                        $validUntil ?: null, $terms, $notesCustomer, $notesInternal,
                        $description, $isContract, $quoteId
                    ]);

                    // Delete old line items
                    $stmt = $db->prepare("DELETE FROM quote_line_items WHERE quote_id = ?");
                    $stmt->execute([$quoteId]);

                } else {
                    // Create new quote
                    $quoteNumber = generateQuoteNumber();
                    $accessToken = generateAccessToken();

                    // Look up company_id and site_contact_id for the selected property
                    $propertyStmt = $db->prepare("
                        SELECT c.id as company_id, p.site_contact_id
                        FROM properties p
                        LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
                        LEFT JOIN companies c ON cp.company_id = c.id
                        WHERE p.id = ?
                        LIMIT 1
                    ");
                    $propertyStmt->execute([$propertyId]);
                    $propertyData = $propertyStmt->fetch(PDO::FETCH_ASSOC);
                    $companyId = $propertyData['company_id'] ?? null;
                    $quoteContactId = $propertyData['site_contact_id'] ?? null;

                    $stmt = $db->prepare("
                        INSERT INTO quotes (
                            quote_number, property_id, company_id, contact_id, title, service_type, amount,
                            subtotal, tax_rate, tax_amount, valid_until, terms,
                            notes_customer, notes_internal, description, access_token,
                            token_expires_at, created_by, status, is_contract
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, 'draft', ?)
                    ");
                    $stmt->execute([
                        $quoteNumber, $propertyId, $companyId, $quoteContactId, $title, $serviceType, $totals['total'],
                        $totals['subtotal'], $totals['tax_rate'], $totals['tax_amount'],
                        $validUntil ?: null, $terms, $notesCustomer, $notesInternal,
                        $description, $accessToken, $user['id'], $isContract
                    ]);
                    $quoteId = $db->lastInsertId();

                    // If created from a quote request, link them together
                    if ($quoteRequestId) {
                        $stmt = $db->prepare("UPDATE quote_requests SET quote_id = ?, status = 'quoted' WHERE id = ?");
                        $stmt->execute([$quoteId, $quoteRequestId]);
                    }

                    logActivityExtended($user['id'], 'Quote created', "Quote {$quoteNumber} created", null, null, $quoteId);
                }

                // Insert line items (extended with pricing snapshot columns)
                $stmt = $db->prepare("
                    INSERT INTO quote_line_items (
                        quote_id, product_id, pricing_rule_id, measurement_group_key,
                        service_type, description, quantity, unit_type,
                        unit_price, line_total, sort_order, is_optional,
                        units_used, price_per_unit, minimum_applied, included_units,
                        pricing_snapshot, bundle_id, is_upsell, section_name
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($newLineItems as $index => $item) {
                    $sectionNameVal = isset($item['section_name']) && trim($item['section_name']) !== ''
                        ? trim($item['section_name'])
                        : null;
                    $stmt->execute([
                        $quoteId,
                        !empty($item['product_id']) ? intval($item['product_id']) : null,
                        !empty($item['pricing_rule_id']) ? intval($item['pricing_rule_id']) : null,
                        $item['measurement_group_key'] ?? null,
                        $item['service_type'] ?? 'Service',
                        $item['description'] ?? '',
                        floatval($item['quantity'] ?? 1),
                        $item['unit_type'] ?? 'each',
                        floatval($item['unit_price'] ?? 0),
                        floatval($item['line_total'] ?? 0),
                        $index,
                        $item['is_optional'] ?? false,
                        isset($item['units_used']) ? floatval($item['units_used']) : null,
                        isset($item['price_per_unit']) ? floatval($item['price_per_unit']) : null,
                        $item['minimum_applied'] ?? 0,
                        isset($item['included_units']) ? floatval($item['included_units']) : null,
                        $item['pricing_snapshot'] ?? null,
                        !empty($item['bundle_id']) ? intval($item['bundle_id']) : null,
                        $item['is_upsell'] ?? 0,
                        $sectionNameVal,
                    ]);
                }

                $db->commit();

                // Auto-attribution: record quote_created for new quotes only
                if (!intval($_GET['id'] ?? 0) && defined('APP_ROOT')) {
                    $__attrSvc = APP_ROOT . '/Modules/Marketing/Services/AttributionService.php';
                    if (file_exists($__attrSvc)) {
                        require_once $__attrSvc;
                        try {
                            AttributionService::onQuoteCreated($db, (int)$quoteId, $propertyId);
                        } catch (\Throwable $__e) {
                            error_log('[quotes/create] attribution error: ' . $__e->getMessage());
                        }
                    }
                }

                header("Location: view.php?id={$quoteId}&saved=1");
                exit;

            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errorHandler->logError('Error saving quote', $e, ['quote_id' => $quoteId, 'property_id' => $propertyId]);
                $error = 'Error saving quote: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle = $quoteId ? 'Edit Quote' : ($quoteRequestId ? 'Create Quote from Request' : 'Create Quote');
$activePage = 'quotes';

$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$extraHead = $apiKey ? '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '" defer></script>' : '';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

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

            <a href="<?php echo $quoteRequestId ? '../products/quote-requests.php?id=' . $quoteRequestId : 'index.php'; ?>" class="mw-back-link">
                &larr; Back to <?php echo $quoteRequestId ? 'Request' : 'Quotes'; ?>
            </a>

            <div class="mw-page-header">
                <div>
                    <h1 class="h3 mb-0"><?php echo $quoteId ? 'Edit Quote' : 'Create Quote'; ?></h1>
                    <p class="text-muted">
                        <?php if ($quote): ?>
                            <?php echo htmlspecialchars($quote['quote_number']); ?>
                        <?php elseif ($quoteRequest): ?>
                            From request by <?php echo htmlspecialchars(($quoteRequest['first_name'] ?? '') . ' ' . ($quoteRequest['last_name'] ?? '')); ?>
                        <?php else: ?>
                            New quote
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="mw-error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" id="quoteForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="line_items" id="lineItemsInput" value="">

                <div class="mw-content-grid">
                    <div class="left-column">
                        <!-- Client & Property Selection -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Customer &amp; Property</h5>
                            </div>
                            <div class="card-body">
                                <!-- Client Type Toggle -->
                                <div class="mw-form-group">
                                    <label class="form-label">Client Type</label>
                                    <div class="mw-client-type-toggle">
                                        <button type="button" class="mw-client-type-btn active" data-type="contact">Contact</button>
                                        <button type="button" class="mw-client-type-btn" data-type="company">Company</button>
                                    </div>
                                </div>

                                <!-- Client Search -->
                                <div class="mw-form-group">
                                    <label class="form-label">Search Client *</label>
                                    <div class="mw-client-search-wrapper">
                                        <input type="text" id="clientSearchInput" class="form-control"
                                               placeholder="Type to search contacts..."
                                               autocomplete="off"
                                               value="<?php echo htmlspecialchars($prefilledContactName); ?>">
                                        <input type="hidden" id="selectedClientId" value="<?php echo $prefilledContactId; ?>">
                                        <input type="hidden" id="selectedClientType" value="<?php echo $prefilledClientType; ?>">
                                        <div class="mw-client-search-results" id="clientSearchResults"></div>
                                    </div>
                                </div>

                                <!-- Property Dropdown (populated after client selection) -->
                                <div class="mw-form-group">
                                    <label class="form-label">Select Property *</label>
                                    <select name="property_id" id="propertySelect" class="form-control" required>
                                        <option value="">Select a client first...</option>
                                        <?php foreach ($prefilledProperties as $prop): ?>
                                            <option value="<?php echo $prop['id']; ?>"
                                                <?php echo (($quote && $quote['property_id'] == $prop['id']) || ($prefilledPropertyId == $prop['id'])) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($prop['address'] . ', ' . $prop['city']); ?>
                                                (<?php echo htmlspecialchars($prop['property_type'] ?? 'N/A'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mw-form-group">
                                    <label class="form-label">Quote Title</label>
                                    <input type="text" name="title" class="form-control"
                                           value="<?php echo htmlspecialchars($quote['title'] ?? ''); ?>"
                                           placeholder="e.g., Spring Lawn Care Package">
                                </div>

                                <div class="mw-form-group">
                                    <label class="form-label">Service Type</label>
                                    <select name="service_type" class="form-control">
                                        <option value="landscaping" <?php echo ($quote['service_type'] ?? '') === 'landscaping' ? 'selected' : ''; ?>>Landscaping</option>
                                        <option value="lawn_care" <?php echo ($quote['service_type'] ?? '') === 'lawn_care' ? 'selected' : ''; ?>>Lawn Care</option>
                                        <option value="snow_removal" <?php echo ($quote['service_type'] ?? '') === 'snow_removal' ? 'selected' : ''; ?>>Snow Removal</option>
                                        <option value="garden_maintenance" <?php echo ($quote['service_type'] ?? '') === 'garden_maintenance' ? 'selected' : ''; ?>>Garden Maintenance</option>
                                        <option value="seasonal_cleanup" <?php echo ($quote['service_type'] ?? '') === 'seasonal_cleanup' ? 'selected' : ''; ?>>Seasonal Cleanup</option>
                                    </select>
                                </div>

                                <div class="mw-form-group mb-0">
                                    <div class="mw-contract-toggle">
                                        <label class="mw-contract-toggle-label">
                                            <input type="checkbox" name="is_contract" id="isContractToggle" value="1"
                                                   <?php echo !empty($quote['is_contract']) ? 'checked' : ''; ?>>
                                            <span class="mw-contract-toggle-track"></span>
                                            <span class="mw-contract-toggle-text">
                                                <strong>Contract Pathway</strong>
                                                <small>Accepted quote will generate a formal contract with billing terms</small>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Measurement Summary + Service Picker -->
                        <div class="card mw-measurement-summary" id="measurementPanel" style="display:none;">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Property Measurements</h5>
                                <div>
                                    <a href="#" id="measurePropertyLink" class="btn btn-sm btn-outline-primary mr-2" style="display:none;" target="_blank">
                                        <i data-feather="maximize-2" style="width:14px;height:14px;"></i> Measure Property
                                    </a>
                                    <span class="badge badge-info" id="measurementBadge"></span>
                                </div>
                            </div>
                            <div class="card-body py-2">
                                <!-- No measurements message -->
                                <div id="noMeasurementsMsg" style="display:none;" class="text-center py-3">
                                    <i data-feather="maximize-2" style="width: 32px; height: 32px; color: #ccc;"></i>
                                    <p class="mb-2 mt-2 text-muted">This property hasn't been measured yet.</p>
                                    <a href="#" id="measurePropertyBtnLarge" class="btn btn-primary" target="_blank">
                                        <i data-feather="maximize-2" style="width:16px;height:16px;" class="mr-1"></i> Measure Property
                                    </a>
                                    <p class="mb-0 mt-2 small text-muted">After measuring, come back and refresh to see pricing options.</p>
                                </div>
                                <!-- Has measurements content -->
                                <div id="hasMeasurementsContent" style="display:none;">
                                    <div id="measurementSummaryContent" class="small text-muted mb-2"></div>
                                    <div id="servicePickerContainer"></div>
                                    <button type="button" class="btn btn-sm btn-success mw-autofill-btn" id="addSelectedServicesBtn" onclick="addSelectedServices()" style="display:none;">
                                        Add Selected Services
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Line Items -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Services &amp; Pricing</h5>
                            </div>
                            <div class="card-body">
                                    <div class="mw-line-items-header">
                                    <div></div>
                                    <div>Service</div>
                                    <div>Description</div>
                                    <div>Qty</div>
                                    <div>Price</div>
                                    <div>Total</div>
                                    <div></div>
                                </div>

                                <div id="lineItemsContainer">
                                    <!-- Line items will be added here by JavaScript -->
                                </div>

                                <div class="d-flex mt-3" style="gap: 12px; flex-wrap: wrap;">
                                    <div class="mw-template-dropdown">
                                        <button type="button" class="btn btn-secondary" id="addFromTemplateBtn">
                                            <i data-feather="grid" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>Add from Template
                                        </button>
                                        <div class="mw-template-menu" id="templateMenu">
                                            <div class="mw-template-search-wrap">
                                                <i data-feather="search" class="mw-template-search-icon"></i>
                                                <input type="text" id="templateSearch" class="mw-template-search" placeholder="Search services…" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();}">
                                            </div>
                                            <div id="templateResults" class="mw-template-results"></div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary" id="addCustomLineBtn">
                                        + Add Custom Line
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" id="addBundleBtn" onclick="openBundlePicker()">
                                        <i data-feather="package" style="width:13px;height:13px;vertical-align:middle;margin-right:3px;"></i>+ Add Bundle
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="addSectionBtn">
                                        <i data-feather="layers" style="width:13px;height:13px;vertical-align:middle;margin-right:3px;"></i>+ Add Section
                                    </button>
                                </div>

                                <div class="mw-totals">
                                    <div class="mw-total-row">
                                        <span>Subtotal</span>
                                        <span class="mw-line-total" id="subtotalDisplay">$0.00</span>
                                    </div>
                                    <div class="mw-total-row">
                                        <span>GST (5%)</span>
                                        <span class="mw-line-total" id="taxDisplay">$0.00</span>
                                    </div>
                                    <div class="mw-total-row mw-grand">
                                        <span>Total</span>
                                        <span class="mw-line-total" id="totalDisplay">$0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Terms & Notes -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Terms &amp; Notes</h5>
                            </div>
                            <div class="card-body">
                                <div class="mw-form-group">
                                    <label class="form-label">Valid Until</label>
                                    <input type="date" name="valid_until" class="form-control"
                                           value="<?php echo $quote['valid_until'] ?? date('Y-m-d', strtotime('+30 days')); ?>">
                                </div>

                                <div class="mw-form-group">
                                    <label class="form-label">Terms &amp; Conditions</label>
                                    <textarea name="terms" class="form-control" rows="4" placeholder="Payment terms, warranty info, etc."><?php echo htmlspecialchars($quote['terms'] ?? "Payment due within 30 days of service completion.\nAll prices include GST.\nWork to be completed weather permitting."); ?></textarea>
                                </div>

                                <div class="mw-form-group">
                                    <label class="form-label">Notes for Customer</label>
                                    <textarea name="notes_customer" class="form-control" rows="3" placeholder="Any additional information for the customer..."><?php echo htmlspecialchars($quote['notes_customer'] ?? ''); ?></textarea>
                                </div>

                                <div class="mw-form-group mb-0">
                                    <label class="form-label">Internal Notes (Not shown to customer)</label>
                                    <textarea name="notes_internal" class="form-control" rows="3" placeholder="Internal notes, reminders, etc."><?php echo htmlspecialchars($quote['notes_internal'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="right-column">
                        <!-- Property Location Map -->
                        <div class="card mw-property-map-card" id="propertyMapCard" style="display: none;">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Property Location</h5>
                                <span class="badge" id="geocodeStatusBadge"></span>
                            </div>
                            <div class="card-body p-0">
                                <div id="propertyMap" class="mw-quote-map"></div>
                                <div id="geocodeActions" class="p-3" style="display: none;">
                                    <p class="text-muted small mb-2">This property hasn't been geocoded yet.</p>
                                    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="geocodeBtn" onclick="geocodeSelectedProperty()">
                                        <i data-feather="crosshair" style="width:14px;height:14px;"></i> Geocode Address
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="card mw-sticky-summary">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Quote Summary</h5>
                            </div>
                            <div class="card-body">
                                <div id="propertySummary" class="mb-3 text-muted" style="font-size: 14px;">
                                    Select a property to see details
                                </div>

                                <div class="mw-totals" style="margin-top: 0; padding-top: 0; border-top: none;">
                                    <div class="mw-total-row">
                                        <span>Subtotal</span>
                                        <span class="mw-line-total" id="sideSubtotal">$0.00</span>
                                    </div>
                                    <div class="mw-total-row">
                                        <span>GST (5%)</span>
                                        <span class="mw-line-total" id="sideTax">$0.00</span>
                                    </div>
                                    <div class="mw-total-row mw-grand">
                                        <span>Total</span>
                                        <span class="mw-line-total" id="sideTotal">$0.00</span>
                                    </div>
                                </div>

                                <div class="mw-form-actions">
                                    <button type="button" id="saveQuoteBtn" class="btn btn-primary" style="flex: 1;">
                                        Save Quote
                                    </button>
                                    <input type="hidden" name="action" value="save">
                                    <script>
                                    document.getElementById('saveQuoteBtn').addEventListener('click', function() {
                                        try {
                                            if (typeof updateFormInput === 'function') updateFormInput();
                                        } catch(e) { console.error('updateFormInput error:', e); }
                                        // Fallback: if hidden input is still empty, serialize lineItems directly
                                        var inp = document.getElementById('lineItemsInput');
                                        if (!inp.value || inp.value === '[]') {
                                            try {
                                                if (typeof lineItems !== 'undefined' && lineItems.length) {
                                                    var out = lineItems.filter(function(i){ return !i._type; });
                                                    inp.value = JSON.stringify(out);
                                                }
                                            } catch(e2) { console.error('lineItems fallback error:', e2); }
                                        }
                                        if (!inp.value || inp.value === '[]') {
                                            alert('Error: Line items could not be serialized. Please reload the page and try again.');
                                            return;
                                        }
                                        document.getElementById('quoteForm').submit();
                                    });
                                    </script>
                                </div>

                                <?php if ($quoteId): ?>
                                <button type="button" class="btn btn-outline-secondary w-100 mt-2" onclick="openPreviewDrawer()">
                                    <i data-feather="eye" style="width:14px;height:14px;vertical-align:middle;margin-right:5px;"></i>Preview as Client
                                </button>
                                <?php endif; ?>

                                <p class="mt-3 mb-0 text-muted" style="font-size: 13px;">
                                    After saving, you can send this quote to the customer for approval.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

    <?php
    // Convert DB line items to JS array, inserting section header objects where sections begin.
    // Section headers (_type:'section') tell the JS renderer to draw a group divider and assign
    // section_name to the items that follow.
    $jsLineItems = [];
    $lastSectionName = false; // false = no section seen yet
    foreach ($lineItems as $item) {
        $sn = $item['section_name'] ?? null;
        if ($sn !== null && $sn !== $lastSectionName) {
            $jsLineItems[] = ['_type' => 'section', 'section_name' => $sn];
            $lastSectionName = $sn;
        } elseif ($sn === null) {
            $lastSectionName = null;
        }
        $jsLineItems[] = $item;
    }
    ?>
    <script>
        // Line items management
        let lineItems = <?php echo json_encode($jsLineItems ?: [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]'; ?>;
        let itemIdCounter = lineItems.filter(i => !i._type).length;

        const container = document.getElementById('lineItemsContainer');
        const templates = <?php echo json_encode($templates, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]'; ?>;

        // Bundle names for group headers: bundle_id → name (populated as bundles are added)
        const bundleNames = {};

        function formatCurrency(amount) {
            return '$' + parseFloat(amount).toFixed(2);
        }

        function calculateTotals() {
            let subtotal = 0;
            lineItems.forEach(item => {
                if (!item.is_optional) {
                    subtotal += parseFloat(item.line_total) || 0;
                }
            });

            const taxRate = 0.05;
            const tax = subtotal * taxRate;
            const total = subtotal + tax;

            document.getElementById('subtotalDisplay').textContent = formatCurrency(subtotal);
            document.getElementById('taxDisplay').textContent = formatCurrency(tax);
            document.getElementById('totalDisplay').textContent = formatCurrency(total);
            document.getElementById('sideSubtotal').textContent = formatCurrency(subtotal);
            document.getElementById('sideTax').textContent = formatCurrency(tax);
            document.getElementById('sideTotal').textContent = formatCurrency(total);
        }

        function renderLineItems() {
            container.innerHTML = '';
            const seenBundles = new Set();
            let dragSrcIdx = null;

            lineItems.forEach((item, index) => {
                // Section divider item
                if (item._type === 'section') {
                    const div = document.createElement('div');
                    div.className = 'mw-section-divider';
                    div.dataset.index = index;
                    div.draggable = true;
                    div.innerHTML =
                        '<span class="mw-drag-handle" title="Drag to reorder section">⠿</span>' +
                        '<i data-feather="layers" style="width:13px;height:13px;margin-right:6px;vertical-align:middle;"></i>' +
                        '<input type="text" class="mw-section-name-input" value="' + escapeHtml(item.section_name || '') + '" ' +
                               'placeholder="Section name…" ' +
                               'oninput="updateLineItem(' + index + ', \'section_name\', this.value)" ' +
                               'onkeydown="if(event.key===\'Enter\'){event.preventDefault();}">' +
                        '<button type="button" class="mw-section-remove-btn" onclick="removeLine(' + index + ')" title="Remove section">&times;</button>';
                    div.addEventListener('dragstart', function (e) {
                        dragSrcIdx = parseInt(this.dataset.index);
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', dragSrcIdx);
                        setTimeout(() => this.classList.add('mw-dragging'), 0);
                    });
                    div.addEventListener('dragend', function () {
                        this.classList.remove('mw-dragging');
                        container.querySelectorAll('.mw-drop-before, .mw-drop-after').forEach(r => r.classList.remove('mw-drop-before', 'mw-drop-after'));
                    });
                    div.addEventListener('dragover', function (e) {
                        e.preventDefault();
                        if (parseInt(this.dataset.index) !== dragSrcIdx) {
                            container.querySelectorAll('.mw-drop-before, .mw-drop-after').forEach(r => r.classList.remove('mw-drop-before', 'mw-drop-after'));
                            const rect = this.getBoundingClientRect();
                            this.classList.add(e.clientY < rect.top + rect.height / 2 ? 'mw-drop-before' : 'mw-drop-after');
                        }
                    });
                    div.addEventListener('drop', function (e) {
                        e.preventDefault();
                        this.classList.remove('mw-drop-before', 'mw-drop-after');
                        const targetIdx = parseInt(this.dataset.index);
                        if (dragSrcIdx === null || dragSrcIdx === targetIdx) return;
                        const rect = this.getBoundingClientRect();
                        const insertBefore = e.clientY < rect.top + rect.height / 2;
                        const moved = lineItems.splice(dragSrcIdx, 1)[0];
                        const newTarget = insertBefore ? targetIdx : targetIdx + 1;
                        const adjustedTarget = dragSrcIdx < targetIdx ? newTarget - 1 : newTarget;
                        lineItems.splice(adjustedTarget, 0, moved);
                        dragSrcIdx = null;
                        renderLineItems();
                    });
                    container.appendChild(div);
                    return;
                }

                // Bundle group header — show once per bundle_id
                if (item.bundle_id && !seenBundles.has(item.bundle_id)) {
                    seenBundles.add(item.bundle_id);
                    const hdr = document.createElement('div');
                    hdr.className = 'mw-bundle-group-header';
                    hdr.innerHTML = '<i data-feather="package" style="width:13px;height:13px;margin-right:5px;vertical-align:middle;"></i>'
                        + escapeHtml(bundleNames[item.bundle_id] || 'Service Bundle');
                    container.appendChild(hdr);
                }

                const row = document.createElement('div');
                row.className = 'mw-line-item' + (item.bundle_id ? ' mw-bundle-item' : '');
                row.dataset.index = index;
                row.draggable = true;

                row.innerHTML =
                    '<span class="mw-drag-handle" title="Drag to reorder">⠿</span>' +
                    '<input type="text" value="' + escapeHtml(item.service_type || '') + '" placeholder="Service name" ' +
                           'onchange="updateLineItem(' + index + ', \'service_type\', this.value)" ' +
                           'onkeydown="if(event.key===\'Enter\'){event.preventDefault();}">' +
                    '<input type="text" value="' + escapeHtml(item.description || '') + '" placeholder="Description" ' +
                           'onchange="updateLineItem(' + index + ', \'description\', this.value)" ' +
                           'onkeydown="if(event.key===\'Enter\'){event.preventDefault();}">' +
                    '<input type="number" value="' + (item.quantity || 1) + '" min="0" step="any" ' +
                           'onchange="updateLineItem(' + index + ', \'quantity\', this.value); recalculateLineTotal(' + index + ')">' +
                    '<input type="number" value="' + (item.unit_price || 0) + '" min="0" step="any" ' +
                           'onchange="updateLineItem(' + index + ', \'unit_price\', this.value); recalculateLineTotal(' + index + ')">' +
                    '<div class="mw-line-total">' + formatCurrency(item.line_total || 0) + '</div>' +
                    (!item.product_id ? '<button type="button" class="mw-save-lib-btn" onclick="openSaveToLibraryPopover(' + index + ')" title="Save to Products Library"><i data-feather="bookmark" style="width:12px;height:12px;"></i></button>' : '') +
                    '<button type="button" class="mw-remove-btn" onclick="removeLine(' + index + ')">&times;</button>';

                // Drag-to-reorder within create form
                row.addEventListener('dragstart', function (e) {
                    dragSrcIdx = parseInt(this.dataset.index);
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', dragSrcIdx);
                    setTimeout(() => this.classList.add('mw-dragging'), 0);
                });
                row.addEventListener('dragend', function () {
                    this.classList.remove('mw-dragging');
                    container.querySelectorAll('.mw-drop-before, .mw-drop-after').forEach(r => r.classList.remove('mw-drop-before', 'mw-drop-after'));
                });
                row.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    if (parseInt(this.dataset.index) !== dragSrcIdx) {
                        container.querySelectorAll('.mw-drop-before, .mw-drop-after').forEach(r => r.classList.remove('mw-drop-before', 'mw-drop-after'));
                        const rect = this.getBoundingClientRect();
                        this.classList.add(e.clientY < rect.top + rect.height / 2 ? 'mw-drop-before' : 'mw-drop-after');
                    }
                });
                row.addEventListener('drop', function (e) {
                    e.preventDefault();
                    this.classList.remove('mw-drop-before', 'mw-drop-after');
                    const targetIdx = parseInt(this.dataset.index);
                    if (dragSrcIdx === null || dragSrcIdx === targetIdx) return;
                    const rect = this.getBoundingClientRect();
                    const insertBefore = e.clientY < rect.top + rect.height / 2;
                    const moved = lineItems.splice(dragSrcIdx, 1)[0];
                    const newTarget = insertBefore ? targetIdx : targetIdx + 1;
                    const adjustedTarget = dragSrcIdx < targetIdx ? newTarget - 1 : newTarget;
                    lineItems.splice(adjustedTarget, 0, moved);
                    dragSrcIdx = null;
                    renderLineItems();
                });

                container.appendChild(row);
            });

            hydrateFeatherIcons(container);
            calculateTotals();
            updateFormInput();
        }

        function updateLineItem(index, field, value) {
            lineItems[index][field] = value;
            updateFormInput();
        }

        function recalculateLineTotal(index) {
            const qty = parseFloat(lineItems[index].quantity) || 0;
            const price = parseFloat(lineItems[index].unit_price) || 0;
            lineItems[index].line_total = qty * price;
            renderLineItems();
        }

        function removeLine(index) {
            lineItems.splice(index, 1);
            renderLineItems();
        }

        function addLine(templateData = null) {
            const price = parseFloat(templateData?.base_price || templateData?.default_price || 0);
            const newItem = {
                id: ++itemIdCounter,
                service_type: templateData?.name || '',
                description: templateData?.description || '',
                quantity: 1,
                unit_type: templateData?.unit_abbreviation || templateData?.unit_name || templateData?.unit_type || 'each',
                unit_price: price,
                line_total: price,
                is_optional: false
            };

            lineItems.push(newItem);
            renderLineItems();
        }

        function updateFormInput() {
            // Walk through items; section header objects set the section_name for following regular items.
            let currentSection = null;
            const outputItems = [];
            lineItems.forEach(item => {
                if (item._type === 'section') {
                    currentSection = (item.section_name && item.section_name.trim()) ? item.section_name.trim() : null;
                } else {
                    outputItems.push(Object.assign({}, item, { section_name: currentSection }));
                }
            });
            document.getElementById('lineItemsInput').value = JSON.stringify(outputItems);
        }

        // Searchable template dropdown
        const templateBtn    = document.getElementById('addFromTemplateBtn');
        const templateMenu   = document.getElementById('templateMenu');
        const templateSearch = document.getElementById('templateSearch');
        const templateResults = document.getElementById('templateResults');

        function renderTemplateResults(query) {
            const q = (query || '').toLowerCase().trim();
            templateResults.innerHTML = '';

            if (!templates || templates.length === 0) {
                templateResults.innerHTML = '<div class="mw-template-empty">No products in catalog.</div>';
                return;
            }

            let currentCat = null;
            let visibleCount = 0;

            templates.forEach(t => {
                const matchesName = t.name.toLowerCase().includes(q);
                const matchesCat  = (t.category_name || '').toLowerCase().includes(q);
                const matchesDesc = (t.description || '').toLowerCase().includes(q);
                if (q && !matchesName && !matchesCat && !matchesDesc) return;

                // Show category header when not filtering
                if (!q && t.category_name !== currentCat) {
                    currentCat = t.category_name;
                    const header = document.createElement('div');
                    header.className = 'mw-template-header';
                    header.textContent = currentCat || 'Uncategorized';
                    templateResults.appendChild(header);
                }

                const item = document.createElement('div');
                item.className = 'mw-template-item';

                const iconHtml = t.icon_base_path
                    ? `<img class="mw-template-icon" src="${t.icon_base_path}icon_32_sold.png" alt="" width="28" height="28" onerror="this.style.display='none'">`
                    : `<span class="mw-template-icon-placeholder"></span>`;

                const price = parseFloat(t.base_price || 0).toFixed(2);
                const priceStr = t.unit_abbreviation
                    ? `$${price}&nbsp;/&nbsp;${escapeHtml(t.unit_abbreviation)}`
                    : `$${price}`;

                item.innerHTML = `${iconHtml}<div><div class="mw-template-name">${escapeHtml(t.name)}</div><div class="mw-template-price">${priceStr}</div></div>`;
                item.addEventListener('click', () => {
                    addLine(t);
                    closeTemplateMenu();
                });
                templateResults.appendChild(item);
                visibleCount++;
            });

            if (visibleCount === 0) {
                templateResults.innerHTML = '<div class="mw-template-empty">No matching services.</div>';
            }
        }

        function openTemplateMenu() {
            templateSearch.value = '';
            renderTemplateResults('');
            templateMenu.classList.add('show');
            setTimeout(() => templateSearch.focus(), 60);
        }

        function closeTemplateMenu() {
            templateMenu.classList.remove('show');
        }

        templateBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            templateMenu.classList.contains('show') ? closeTemplateMenu() : openTemplateMenu();
        });

        templateSearch.addEventListener('input', () => renderTemplateResults(templateSearch.value));

        templateSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closeTemplateMenu(); templateBtn.focus(); }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.mw-template-dropdown')) closeTemplateMenu();
        });

        document.getElementById('addCustomLineBtn').addEventListener('click', () => addLine());

        document.getElementById('addSectionBtn').addEventListener('click', () => {
            lineItems.push({ _type: 'section', section_name: '' });
            renderLineItems();
            // Focus the new section input
            const inputs = container.querySelectorAll('.mw-section-name-input');
            if (inputs.length) inputs[inputs.length - 1].focus();
        });

        // Client search + property population
        const propertySelect = document.getElementById('propertySelect');
        const propertySummary = document.getElementById('propertySummary');
        const clientSearchInput = document.getElementById('clientSearchInput');
        const clientSearchResults = document.getElementById('clientSearchResults');
        const selectedClientIdInput = document.getElementById('selectedClientId');
        const selectedClientTypeInput = document.getElementById('selectedClientType');
        let clientSearchTimeout = null;
        let currentClientType = '<?php echo $prefilledClientType; ?>';
        let loadedProperties = <?php echo json_encode($prefilledProperties); ?>;

        // Client type toggle
        document.querySelectorAll('.mw-client-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.mw-client-type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentClientType = this.dataset.type;
                selectedClientTypeInput.value = currentClientType;
                clientSearchInput.placeholder = currentClientType === 'company'
                    ? 'Type to search companies...'
                    : 'Type to search contacts...';
                // Clear current selection
                clearClientSelection();
            });
        });

        function clearClientSelection() {
            clientSearchInput.value = '';
            selectedClientIdInput.value = '';
            propertySelect.innerHTML = '<option value="">Select a client first...</option>';
            propertySummary.textContent = 'Select a client first';
            loadedProperties = [];
            document.getElementById('measurementPanel').style.display = 'none';
        }

        // Client search input
        clientSearchInput.addEventListener('input', function() {
            const q = this.value.trim();
            clearTimeout(clientSearchTimeout);

            if (q.length < 1) {
                clientSearchResults.innerHTML = '';
                clientSearchResults.style.display = 'none';
                return;
            }

            clientSearchTimeout = setTimeout(() => {
                fetch('../api/client-search.php?action=search&type=' + currentClientType + '&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.results.length > 0) {
                            clientSearchResults.innerHTML = data.results.map(r => `
                                <div class="mw-client-result" data-id="${r.id}">
                                    <div class="mw-client-result-name">${escapeHtml(r.label)}</div>
                                    <div class="mw-client-result-meta">
                                        ${escapeHtml(r.sublabel)}
                                        ${r.property_count > 0 ? ' &middot; ' + r.property_count + ' propert' + (r.property_count === 1 ? 'y' : 'ies') : ''}
                                    </div>
                                </div>
                            `).join('');
                            clientSearchResults.style.display = 'block';

                            // Attach click handlers
                            clientSearchResults.querySelectorAll('.mw-client-result').forEach(el => {
                                el.addEventListener('click', function() {
                                    selectClient(parseInt(this.dataset.id), this.querySelector('.mw-client-result-name').textContent);
                                });
                            });
                        } else {
                            clientSearchResults.innerHTML = '<div class="mw-client-no-results">No results found</div>';
                            clientSearchResults.style.display = 'block';
                        }
                    })
                    .catch(() => {
                        clientSearchResults.innerHTML = '<div class="mw-client-no-results">Error searching</div>';
                        clientSearchResults.style.display = 'block';
                    });
            }, 250);
        });

        // Close dropdown on click outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mw-client-search-wrapper')) {
                clientSearchResults.style.display = 'none';
            }
        });

        // Focus shows results again if populated
        clientSearchInput.addEventListener('focus', function() {
            if (clientSearchResults.innerHTML && !selectedClientIdInput.value) {
                clientSearchResults.style.display = 'block';
            }
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function selectClient(clientId, clientName) {
            selectedClientIdInput.value = clientId;
            clientSearchInput.value = clientName;
            clientSearchResults.style.display = 'none';

            // Load properties for this client
            const paramKey = currentClientType === 'company' ? 'company_id' : 'contact_id';
            fetch('../api/client-search.php?action=properties&' + paramKey + '=' + clientId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        loadedProperties = data.properties;
                        propertySelect.innerHTML = '<option value="">Choose a property...</option>';
                        data.properties.forEach(p => {
                            const opt = document.createElement('option');
                            opt.value = p.id;
                            opt.textContent = p.address + ', ' + p.city + ' (' + (p.property_type || 'N/A') + ')';
                            propertySelect.appendChild(opt);
                        });

                        // Auto-select if only one property
                        if (data.properties.length === 1) {
                            propertySelect.value = data.properties[0].id;
                            propertySelect.dispatchEvent(new Event('change'));
                        }

                        if (data.properties.length === 0) {
                            propertySelect.innerHTML = '<option value="">No properties found for this client</option>';
                        }
                    }
                });

            // Update sidebar summary
            propertySummary.innerHTML = '<strong>' + escapeHtml(clientName) + '</strong><br><span class="text-muted">Loading properties...</span>';
        }

        // Property selection updates summary + measurements
        propertySelect.addEventListener('change', function() {
            const selected = loadedProperties.find(p => p.id == this.value);
            const clientName = clientSearchInput.value;
            if (selected) {
                propertySummary.innerHTML = `
                    <strong>${escapeHtml(clientName)}</strong><br>
                    ${escapeHtml(selected.address)}<br>
                    ${escapeHtml(selected.city)}<br>
                    <span style="opacity: 0.7">${escapeHtml(selected.property_type || '')}</span>
                `;
                fetchMeasurements(this.value);
            } else {
                if (clientName) {
                    propertySummary.innerHTML = '<strong>' + escapeHtml(clientName) + '</strong><br><span class="text-muted">Select a property</span>';
                } else {
                    propertySummary.textContent = 'Select a client first';
                }
                document.getElementById('measurementPanel').style.display = 'none';
            }
        });

        // Allow clearing the client search to start over
        clientSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && selectedClientIdInput.value) {
                // User is deleting — clear the selection so they can search again
                selectedClientIdInput.value = '';
                propertySelect.innerHTML = '<option value="">Select a client first...</option>';
                loadedProperties = [];
                document.getElementById('measurementPanel').style.display = 'none';
            }
        });

        // Measurement auto-fill functionality
        let propertyMeasurements = null;

        function fetchMeasurements(propertyId) {
            const panel = document.getElementById('measurementPanel');
            const content = document.getElementById('measurementSummaryContent');
            const badge = document.getElementById('measurementBadge');
            const pickerContainer = document.getElementById('servicePickerContainer');
            const addBtn = document.getElementById('addSelectedServicesBtn');
            const noMeasMsg = document.getElementById('noMeasurementsMsg');
            const hasMeasContent = document.getElementById('hasMeasurementsContent');
            const measureLink = document.getElementById('measurePropertyLink');
            const measureBtnLarge = document.getElementById('measurePropertyBtnLarge');

            // Set measure tool links
            const measureUrl = '../products/area-measurement.php?property_id=' + propertyId;
            measureLink.href = measureUrl;
            measureBtnLarge.href = measureUrl;

            // Always show the panel when a property is selected
            panel.style.display = '';
            if (typeof feather !== 'undefined') feather.replace();

            fetch('../api/quote-autofill.php?action=get-measurements&property_id=' + propertyId)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.has_data) {
                        propertyMeasurements = data;
                        noMeasMsg.style.display = 'none';
                        hasMeasContent.style.display = '';
                        measureLink.style.display = '';
                        badge.style.display = '';

                        const groupLabels = {
                            'lawn_area': 'Lawn & Garden',
                            'hard_surface': 'Hard Surface',
                            'hedge_linear': 'Hedge / Edge',
                            'other_area': 'Other'
                        };
                        let summaryHtml = '';
                        let totalAreas = 0;

                        Object.entries(data.measurements).forEach(([key, m]) => {
                            totalAreas += m.count;
                            const label = groupLabels[key] || key;
                            const value = key === 'hedge_linear'
                                ? m.linear_ft.toLocaleString() + ' lin ft'
                                : m.sqft.toLocaleString() + ' sq ft';
                            const names = m.names.join(', ');
                            summaryHtml += `<div class="mb-1"><strong>${label}:</strong> ${value} <span class="text-muted">(${names})</span></div>`;
                        });

                        content.innerHTML = summaryHtml;
                        badge.textContent = totalAreas + ' area' + (totalAreas !== 1 ? 's' : '');

                        // Build service picker per measurement group
                        buildServicePicker(data.measurements, data.rules);
                    } else {
                        // No measurements — show "measure property" prompt
                        propertyMeasurements = null;
                        noMeasMsg.style.display = '';
                        hasMeasContent.style.display = 'none';
                        measureLink.style.display = '';
                        badge.textContent = 'Not measured';
                        badge.className = 'badge badge-warning';
                        pickerContainer.innerHTML = '';
                        addBtn.style.display = 'none';
                        if (typeof feather !== 'undefined') feather.replace();
                    }
                })
                .catch(() => {
                    panel.style.display = 'none';
                    propertyMeasurements = null;
                    pickerContainer.innerHTML = '';
                    addBtn.style.display = 'none';
                });
        }

        function buildServicePicker(measurements, rulesByGroup) {
            const container = document.getElementById('servicePickerContainer');
            const addBtn = document.getElementById('addSelectedServicesBtn');
            container.innerHTML = '';

            const frequencyLabels = {
                'daily': 'Daily',
                '7_day': '7-day',
                '14_day': '14-day',
                '21_day': '21-day',
                'monthly': 'Monthly',
                'seasonal': 'Seasonal',
                'one_off': 'One-off'
            };

            let hasRules = false;

            Object.entries(measurements).forEach(([groupKey, m]) => {
                const rules = rulesByGroup[groupKey];
                if (!rules || rules.length === 0) return;

                hasRules = true;
                const totalUnits = groupKey === 'hedge_linear' ? m.linear_ft : m.sqft;
                const unitLabel = groupKey === 'hedge_linear' ? 'lin ft' : 'sq ft';

                const groupDiv = document.createElement('div');
                groupDiv.className = 'mw-service-picker-group';

                let rulesHtml = '';
                rules.forEach(rule => {
                    const price = calculatePreviewPrice(rule, totalUnits);
                    const freq = frequencyLabels[rule.default_frequency] || rule.default_frequency;
                    const rateInfo = rule.pricing_model === 'flat'
                        ? 'Flat rate'
                        : '$' + parseFloat(rule.price_per_unit).toFixed(4) + '/' + (rule.unit || 'sqft');

                    rulesHtml += `
                        <label class="mw-service-option">
                            <input type="checkbox" name="service_rule" value="${rule.id}" data-group="${groupKey}">
                            <div class="mw-service-option-details">
                                <span class="mw-service-option-name">${rule.product_name}</span>
                                <span class="mw-service-option-meta">${freq} &middot; ${rateInfo}</span>
                            </div>
                            <span class="mw-service-option-price">${formatCurrency(price)}</span>
                        </label>
                    `;
                });

                groupDiv.innerHTML = rulesHtml;
                container.appendChild(groupDiv);
            });

            if (hasRules) {
                addBtn.style.display = '';
                // Update button state on checkbox change
                container.addEventListener('change', updateAddButtonState);
                updateAddButtonState();
            } else {
                addBtn.style.display = 'none';
            }
        }

        function calculatePreviewPrice(rule, totalUnits) {
            const model = rule.pricing_model;
            const perUnit = parseFloat(rule.price_per_unit) || 0;
            const minPrice = parseFloat(rule.minimum_price) || 0;
            const included = parseFloat(rule.included_units) || 0;
            const basePrice = parseFloat(rule.base_price) || 0;

            let price = 0;
            switch (model) {
                case 'flat':
                    price = basePrice;
                    break;
                case 'per_sqft':
                case 'per_linear_ft':
                    price = totalUnits * perUnit;
                    if (minPrice > 0 && price < minPrice) price = minPrice;
                    break;
                case 'min_plus_sqft':
                case 'min_plus_linear_ft':
                    price = minPrice + (Math.max(0, totalUnits - included) * perUnit);
                    break;
            }
            return Math.round(price * 100) / 100;
        }

        function updateAddButtonState() {
            const checked = document.querySelectorAll('#servicePickerContainer input[type="checkbox"]:checked');
            const btn = document.getElementById('addSelectedServicesBtn');
            btn.disabled = checked.length === 0;
            btn.textContent = checked.length === 0
                ? 'Select services above'
                : 'Add ' + checked.length + ' Selected Service' + (checked.length !== 1 ? 's' : '');
        }

        function addSelectedServices() {
            const propertyId = propertySelect.value;
            if (!propertyId) return;

            const checked = document.querySelectorAll('#servicePickerContainer input[type="checkbox"]:checked');
            if (checked.length === 0) {
                alert('Please select at least one service.');
                return;
            }

            const selectedRuleIds = Array.from(checked).map(cb => parseInt(cb.value));

            const formData = new FormData();
            formData.append('action', 'auto-fill');
            formData.append('property_id', propertyId);
            formData.append('selected_rules', JSON.stringify(selectedRuleIds));

            fetch('../api/quote-autofill.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.items.length > 0) {
                    data.items.forEach(item => {
                        lineItems.push({
                            id: ++itemIdCounter,
                            product_id: item.product_id || null,
                            pricing_rule_id: item.pricing_rule_id || null,
                            measurement_group_key: item.measurement_group_key || null,
                            service_type: item.service_type || '',
                            description: item.description || '',
                            quantity: item.quantity || 1,
                            unit_type: item.unit_type || 'each',
                            unit_price: item.unit_price || 0,
                            line_total: item.line_total || 0,
                            is_optional: item.is_optional || false,
                            units_used: item.units_used || null,
                            price_per_unit: item.price_per_unit || null,
                            minimum_applied: item.minimum_applied || 0,
                            included_units: item.included_units || null,
                            pricing_snapshot: item.pricing_snapshot || null,
                            bundle_id: item.bundle_id || null,
                            is_upsell: item.is_upsell || 0,
                        });
                    });
                    renderLineItems();

                    // Uncheck all after adding
                    document.querySelectorAll('#servicePickerContainer input[type="checkbox"]').forEach(cb => cb.checked = false);
                    updateAddButtonState();

                    if (data.warnings && data.warnings.length > 0) {
                        alert('Services added with warnings:\n' + data.warnings.join('\n'));
                    }
                } else if (data.warnings && data.warnings.length > 0) {
                    alert('Could not add services:\n' + data.warnings.join('\n'));
                } else {
                    alert('No pricing rules matched. Check product pricing configuration.');
                }
            })
            .catch(err => alert('Error: ' + err.message));
        }

        // ── Bundle Picker ─────────────────────────────────────────────────────────
        const BUNDLE_PREVIEW_URL = '../api/quote-autofill.php';
        const PRODUCTS_API_URL   = '../products/api-products.php';

        async function openBundlePicker() {
            $('#addBundleModal').modal('show');
            const grid = document.getElementById('bundlePickerGrid');
            grid.innerHTML = '<p class="text-muted text-center py-3">Loading bundles…</p>';

            try {
                const res  = await fetch(PRODUCTS_API_URL + '?action=get-bundles');
                const data = await res.json();

                if (!data.success || !data.bundles || data.bundles.length === 0) {
                    grid.innerHTML = '<p class="text-muted text-center">No bundles available. Create one in <a href="../products/bundles.php" target="_blank">Service Bundles</a>.</p>';
                    return;
                }

                const hasProperty = !!(propertySelect && propertySelect.value);
                let html = '';

                if (!hasProperty) {
                    html += '<div class="alert alert-info py-2 mb-3" style="font-size:13px;">'
                          + '<i data-feather="info" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>'
                          + 'No property selected — prices shown are base rates. Select a property with measurements for auto-calculated pricing.'
                          + '</div>';
                }

                data.bundles.forEach(b => {
                    const tierColors = { good: '#16a34a', better: '#2563eb', best: '#9333ea', custom: '#64748b' };
                    const tierColor  = tierColors[b.tier] || '#64748b';
                    const itemCount  = b.items ? b.items.length : 0;

                    // Calculate display price from items
                    let listTotal = 0;
                    let itemHtml  = '';
                    if (b.items && b.items.length) {
                        b.items.forEach(it => {
                            const price = parseFloat(it.override_price || it.base_price || 0);
                            listTotal += price;
                            itemHtml += '<div class="mw-bp-item">'
                                + '<span>' + escapeHtml(it.product_name) + '</span>'
                                + '<span>' + formatCurrency(price) + '</span>'
                                + '</div>';
                        });
                    }

                    let discountDisplay = '';
                    if (parseFloat(b.discount_value) > 0) {
                        discountDisplay = b.discount_type === 'percentage'
                            ? '−' + parseFloat(b.discount_value).toFixed(0) + '%'
                            : '−$' + parseFloat(b.discount_value).toFixed(2);
                    }

                    html += '<div class="mw-bp-card">'
                        + '<div class="mw-bp-header">'
                            + '<div>'
                                + '<span class="mw-bp-tier" style="background:' + tierColor + '">' + escapeHtml(b.tier.charAt(0).toUpperCase() + b.tier.slice(1)) + '</span>'
                                + '<strong class="ml-2">' + escapeHtml(b.bundle_name) + '</strong>'
                            + '</div>'
                            + (discountDisplay ? '<span class="mw-bp-discount">' + discountDisplay + ' off</span>' : '')
                        + '</div>'
                        + (b.description ? '<div class="mw-bp-desc">' + escapeHtml(b.description) + '</div>' : '')
                        + '<div class="mw-bp-items">' + itemHtml + '</div>'
                        + (hasProperty
                            ? '<button type="button" class="btn btn-sm btn-primary btn-block mt-2" onclick="addBundleToQuote(' + b.id + ', \'' + escapeHtml(b.bundle_name).replace(/'/g,"&#39;") + '\')" data-bundle-id="' + b.id + '">'
                              + 'Add to Quote (calculating…)'
                              + '</button>'
                            : '<button type="button" class="btn btn-sm btn-primary btn-block mt-2" onclick="addBundleToQuote(' + b.id + ', \'' + escapeHtml(b.bundle_name).replace(/'/g,"&#39;") + '\')">'
                              + 'Add to Quote (base prices)'
                              + '</button>')
                        + '</div>';
                });

                grid.innerHTML = html;
                hydrateFeatherIcons(grid);

                // If property is selected, pre-calculate prices for each bundle
                if (hasProperty) {
                    data.bundles.forEach(b => {
                        previewBundlePrice(b.id, propertySelect.value);
                    });
                }

            } catch (err) {
                grid.innerHTML = '<p class="text-danger">Error loading bundles. Please try again.</p>';
                console.error('[Bundle Picker]', err);
            }
        }

        async function previewBundlePrice(bundleId, propertyId) {
            try {
                const params = new URLSearchParams({ action: 'preview-bundle', bundle_id: bundleId, property_id: propertyId });
                const res  = await fetch(BUNDLE_PREVIEW_URL, { method: 'POST', body: params });
                const data = await res.json();
                if (!data.success) return;

                // Sum all item totals
                let total = 0;
                data.items.forEach(it => { total += parseFloat(it.line_total || 0); });

                // Update the button label
                const btn = document.querySelector('#bundlePickerGrid button[data-bundle-id="' + bundleId + '"]');
                if (btn) btn.textContent = 'Add to Quote — ' + formatCurrency(total);
            } catch (e) { /* silent */ }
        }

        async function addBundleToQuote(bundleId, bundleName) {
            const propId = propertySelect ? (propertySelect.value || 0) : 0;

            const btn = document.querySelector('#bundlePickerGrid button[data-bundle-id="' + bundleId + '"], #bundlePickerGrid button[onclick*="addBundleToQuote(' + bundleId + '"]');
            if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }

            try {
                const params = new URLSearchParams({ action: 'preview-bundle', bundle_id: bundleId, property_id: propId });
                const res  = await fetch(BUNDLE_PREVIEW_URL, { method: 'POST', body: params });
                const data = await res.json();

                if (!data.success) {
                    alert('Could not load bundle: ' + (data.error || 'Unknown error'));
                    if (btn) { btn.disabled = false; btn.textContent = 'Add to Quote'; }
                    return;
                }

                // Store bundle name for group header
                bundleNames[bundleId] = bundleName;

                // Push items into quote line items
                data.items.forEach(item => {
                    lineItems.push({
                        id:                    ++itemIdCounter,
                        product_id:            item.product_id             || null,
                        pricing_rule_id:       item.pricing_rule_id        || null,
                        measurement_group_key: item.measurement_group_key  || null,
                        service_type:          item.service_type           || '',
                        description:           item.description            || '',
                        quantity:              parseFloat(item.quantity    ?? 1),
                        unit_type:             item.unit_type              || 'each',
                        unit_price:            parseFloat(item.unit_price  ?? 0),
                        line_total:            parseFloat(item.line_total  ?? 0),
                        is_optional:           item.is_optional            || false,
                        units_used:            item.units_used             || null,
                        price_per_unit:        item.price_per_unit         || null,
                        minimum_applied:       item.minimum_applied        || 0,
                        included_units:        item.included_units         || null,
                        pricing_snapshot:      item.pricing_snapshot       || null,
                        bundle_id:             parseInt(bundleId),
                        is_upsell:             item.is_upsell              || 0,
                    });
                });

                $('#addBundleModal').modal('hide');
                renderLineItems();

                if (data.warnings && data.warnings.length) {
                    const msg = document.getElementById('bundleWarningMsg');
                    if (msg) {
                        msg.textContent = '⚠ ' + data.warnings.join(' · ');
                        msg.style.display = 'block';
                        setTimeout(() => { msg.style.display = 'none'; }, 6000);
                    }
                }
            } catch (err) {
                alert('Error adding bundle: ' + err.message);
                console.error('[addBundleToQuote]', err);
                if (btn) { btn.disabled = false; btn.textContent = 'Add to Quote'; }
            }
        }

        // Initialize
        if (lineItems.length === 0) {
            // Add empty line for new quotes
        }

        // Restore bundle names from existing line items (edit mode)
        lineItems.forEach(item => {
            if (item.bundle_id && !bundleNames[item.bundle_id]) {
                bundleNames[item.bundle_id] = 'Bundle #' + item.bundle_id;
            }
        });

        renderLineItems();

        // If a client is pre-selected (edit mode / from request), trigger property change
        if (selectedClientIdInput.value && propertySelect.value) {
            propertySelect.dispatchEvent(new Event('change'));
        }

        // Form submission
        document.getElementById('quoteForm').addEventListener('submit', function() {
            updateFormInput();
        });

        // ── Property Location Map ──────────────────────────────
        var propertyMap = null;
        var propertyMarker = null;
        var geocoder = null;
        var CSRF_TOKEN = '<?php echo $csrfToken; ?>';

        function showPropertyMap(property) {
            var mapCard = document.getElementById('propertyMapCard');
            var mapEl = document.getElementById('propertyMap');
            var geocodeActions = document.getElementById('geocodeActions');
            var badge = document.getElementById('geocodeStatusBadge');

            if (!property) {
                mapCard.style.display = 'none';
                return;
            }

            mapCard.style.display = '';

            var lat = parseFloat(property.latitude);
            var lng = parseFloat(property.longitude);
            var hasCoords = lat && lng && lat !== 0 && lng !== 0;

            if (hasCoords) {
                badge.textContent = 'Geocoded';
                badge.className = 'badge badge-success';
                geocodeActions.style.display = 'none';
                mapEl.style.display = 'block';

                var pos = { lat: lat, lng: lng };

                if (!propertyMap) {
                    propertyMap = new google.maps.Map(mapEl, {
                        center: pos,
                        zoom: 17,
                        mapTypeId: 'hybrid',
                        mapTypeControl: false,
                        streetViewControl: false,
                        fullscreenControl: true,
                        zoomControl: true
                    });
                    propertyMarker = new google.maps.Marker({
                        position: pos,
                        map: propertyMap,
                        title: property.address
                    });
                } else {
                    propertyMap.setCenter(pos);
                    propertyMap.setZoom(17);
                    propertyMarker.setPosition(pos);
                    propertyMarker.setTitle(property.address);
                }

                // Force map resize after card becomes visible
                setTimeout(function() {
                    google.maps.event.trigger(propertyMap, 'resize');
                    propertyMap.setCenter(pos);
                }, 100);

            } else {
                badge.textContent = 'Not Geocoded';
                badge.className = 'badge badge-warning';
                geocodeActions.style.display = '';
                mapEl.style.display = 'none';
                feather.replace();
            }
        }

        function geocodeSelectedProperty() {
            var propId = propertySelect.value;
            var prop = loadedProperties.find(function(p) { return p.id == propId; });
            if (!prop) return;

            if (!geocoder) geocoder = new google.maps.Geocoder();

            var btn = document.getElementById('geocodeBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:14px;height:14px;"></span> Geocoding...';

            var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
            var address = parts.join(', ');

            geocoder.geocode({ address: address }, function(results, status) {
                if (status === 'OK' && results[0]) {
                    var loc = results[0].geometry.location;
                    var lat = loc.lat();
                    var lng = loc.lng();

                    // Save to DB
                    fetch('../api/geocode-save.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            property_id: parseInt(propId),
                            lat: lat,
                            lng: lng,
                            csrf_token: CSRF_TOKEN
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // Update local data so the map shows immediately
                            prop.latitude = lat;
                            prop.longitude = lng;
                            showPropertyMap(prop);
                        } else {
                            alert('Failed to save coordinates: ' + (data.error || 'Unknown error'));
                        }
                        btn.disabled = false;
                        btn.innerHTML = '<i data-feather="crosshair" style="width:14px;height:14px;"></i> Geocode Address';
                        feather.replace();
                    })
                    .catch(function(err) {
                        alert('Error saving: ' + err.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i data-feather="crosshair" style="width:14px;height:14px;"></i> Geocode Address';
                        feather.replace();
                    });
                } else {
                    alert('Geocoding failed for "' + address + '": ' + status);
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="crosshair" style="width:14px;height:14px;"></i> Geocode Address';
                    feather.replace();
                }
            });
        }

        // Hook into property selection change to show/hide map
        var origPropertyChangeHandler = propertySelect.onchange;
        propertySelect.addEventListener('change', function() {
            var selected = loadedProperties.find(function(p) { return p.id == this.value; }.bind(this));
            showPropertyMap(selected || null);
        });

        // On page load, if a property is already selected, show the map
        if (propertySelect.value) {
            var initialProp = loadedProperties.find(function(p) { return p.id == propertySelect.value; });
            if (initialProp) {
                // Wait for Google Maps API to load
                function initMapWhenReady() {
                    if (typeof google !== 'undefined' && google.maps) {
                        showPropertyMap(initialProp);
                    } else {
                        setTimeout(initMapWhenReady, 200);
                    }
                }
                initMapWhenReady();
            }
        }

        // ── Save to Products Library ──────────────────────────────────────────
        var _saveLibCategories = null;

        async function _loadSaveLibCategories() {
            if (_saveLibCategories) return _saveLibCategories;
            try {
                const resp = await fetch('/crm/products/api-products.php?action=get-categories');
                const data = await resp.json();
                _saveLibCategories = (data.success && data.categories) ? data.categories : [];
            } catch (e) {
                _saveLibCategories = [];
            }
            return _saveLibCategories;
        }

        async function openSaveToLibraryPopover(index) {
            const item = lineItems[index];
            document.getElementById('saveLibLineIndex').value = index;
            document.getElementById('saveLibName').value = item.service_type || '';
            document.getElementById('saveLibPrice').value = parseFloat(item.unit_price || 0).toFixed(2);
            document.getElementById('saveLibDescription').value = item.description || '';
            document.getElementById('saveLibStatus').textContent = '';
            document.getElementById('saveLibStatus').className = 'small text-muted mr-auto';
            document.getElementById('saveLibSubmitBtn').disabled = false;

            // Populate category dropdown
            const cats = await _loadSaveLibCategories();
            const sel = document.getElementById('saveLibCategory');
            sel.innerHTML = '<option value="">— select a category —</option>';
            cats.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                sel.appendChild(opt);
            });

            $('#saveToLibraryModal').modal('show');
            setTimeout(() => { if (typeof feather !== 'undefined') feather.replace(); }, 50);
        }

        async function saveItemToLibrary() {
            const index = parseInt(document.getElementById('saveLibLineIndex').value);
            const name = document.getElementById('saveLibName').value.trim();
            const categoryId = document.getElementById('saveLibCategory').value;
            const price = parseFloat(document.getElementById('saveLibPrice').value) || 0;
            const description = document.getElementById('saveLibDescription').value.trim();
            const statusEl = document.getElementById('saveLibStatus');

            if (!name) {
                statusEl.textContent = 'Service name is required.';
                statusEl.className = 'small text-danger mr-auto';
                document.getElementById('saveLibName').focus();
                return;
            }
            if (!categoryId) {
                statusEl.textContent = 'Please select a category.';
                statusEl.className = 'small text-danger mr-auto';
                document.getElementById('saveLibCategory').focus();
                return;
            }

            statusEl.textContent = 'Saving…';
            statusEl.className = 'small text-muted mr-auto';

            const submitBtn = document.getElementById('saveLibSubmitBtn');
            submitBtn.disabled = true;

            try {
                const resp = await fetch('/crm/products/api-products.php?action=save-product', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: name,
                        category_id: parseInt(categoryId),
                        base_price: price,
                        description: description || null,
                        active: 1,
                        taxable: 1,
                        uses_cost_calculator: 0,
                        featured: 0,
                        track_inventory: 0
                    })
                });
                const data = await resp.json();

                if (data.success) {
                    // Mark line item as linked to this product so button disappears
                    lineItems[index].product_id = data.id || true;
                    statusEl.textContent = '✓ Saved!';
                    statusEl.className = 'small text-success mr-auto';
                    setTimeout(() => {
                        $('#saveToLibraryModal').modal('hide');
                        renderLineItems();
                        showMwToast('Product saved to library — it\'s now available in Add from Template.');
                    }, 700);
                } else {
                    statusEl.textContent = data.error || data.message || 'Save failed.';
                    statusEl.className = 'small text-danger mr-auto';
                    submitBtn.disabled = false;
                }
            } catch (e) {
                statusEl.textContent = 'Network error — please try again.';
                statusEl.className = 'small text-danger mr-auto';
                submitBtn.disabled = false;
            }
        }

        function showMwToast(message) {
            let toast = document.getElementById('mwToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'mwToast';
                toast.className = 'mw-toast';
                document.body.appendChild(toast);
            }
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3500);
        }
    </script>

<!-- ── Bundle Picker Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="addBundleModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i data-feather="package" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i>
          Add Service Bundle
        </h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="bundlePickerGrid" class="mw-bp-grid">
          <p class="text-muted text-center py-3">Loading…</p>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <small class="text-muted">
          <a href="<?php echo htmlspecialchars(dirname($_SERVER['PHP_SELF']) . '/../products/bundles.php'); ?>" target="_blank">
            Manage Bundles <i data-feather="external-link" style="width:11px;height:11px;vertical-align:middle;"></i>
          </a>
        </small>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Bundle warning toast (shown below line items area) -->
<div id="bundleWarningMsg" class="alert alert-warning py-2 mt-2" style="display:none;font-size:13px;"></div>

<!-- ── Save to Products Library Modal ─────────────────────────────────── -->
<div class="modal fade" id="saveToLibraryModal" tabindex="-1" role="dialog" aria-labelledby="saveToLibraryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-header mw-save-lib-header">
        <h6 class="modal-title" id="saveToLibraryModalLabel">
          <i data-feather="bookmark" style="width:14px;height:14px;vertical-align:middle;margin-right:5px;"></i>
          Save to Products Library
        </h6>
        <button type="button" class="close mw-save-lib-close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" style="padding:16px;">
        <input type="hidden" id="saveLibLineIndex" value="">
        <div class="form-group mb-3">
          <label class="small font-weight-bold mb-1">Service Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="saveLibName" placeholder="e.g. Lawn Mowing">
        </div>
        <div class="form-group mb-3">
          <label class="small font-weight-bold mb-1">Category <span class="text-danger">*</span></label>
          <select class="form-control form-control-sm" id="saveLibCategory">
            <option value="">— select a category —</option>
          </select>
        </div>
        <div class="form-group mb-3">
          <label class="small font-weight-bold mb-1">Base Price</label>
          <div class="input-group input-group-sm">
            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
            <input type="number" class="form-control" id="saveLibPrice" min="0" step="0.01" placeholder="0.00">
          </div>
        </div>
        <div class="form-group mb-0">
          <label class="small font-weight-bold mb-1">Description <span class="text-muted font-weight-normal">(optional)</span></label>
          <textarea class="form-control form-control-sm" id="saveLibDescription" rows="2" placeholder="Brief description…"></textarea>
        </div>
      </div>
      <div class="modal-footer" style="padding:10px 16px;">
        <span id="saveLibStatus" class="small text-muted mr-auto"></span>
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm mw-save-lib-submit" id="saveLibSubmitBtn" onclick="saveItemToLibrary()">
          <i data-feather="bookmark" style="width:12px;height:12px;vertical-align:middle;margin-right:3px;"></i>
          Save to Library
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($quoteId): ?>
<!-- ── Client Preview Drawer ──────────────────────────────────────────── -->
<div id="previewDrawerBackdrop" class="mw-preview-backdrop" onclick="closePreviewDrawer()"></div>
<div id="previewDrawer" class="mw-preview-drawer">
    <div class="mw-preview-drawer-header">
        <div class="mw-preview-drawer-title">
            <i data-feather="eye" style="width:16px;height:16px;"></i>
            Client Preview
            <span class="mw-preview-badge">How your client sees this</span>
        </div>
        <div class="mw-preview-drawer-actions">
            <button type="button" class="btn btn-sm btn-outline-light" onclick="refreshPreview()" title="Reload preview (after saving changes)">
                <i data-feather="refresh-cw" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>Refresh
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="closePreviewDrawer()" title="Close preview">
                <i data-feather="x" style="width:15px;height:15px;"></i>
            </button>
        </div>
    </div>
    <div class="mw-preview-drawer-body">
        <iframe id="previewIframe" class="mw-preview-iframe" src="" title="Client quote preview"></iframe>
    </div>
</div>

<script>
function openPreviewDrawer() {
    const drawer = document.getElementById('previewDrawer');
    const backdrop = document.getElementById('previewDrawerBackdrop');
    const iframe = document.getElementById('previewIframe');
    const previewUrl = '/customer/quote.php?_preview=<?php echo $quoteId; ?>';
    if (!iframe.src || iframe.src === window.location.href || iframe.getAttribute('data-loaded') !== '1') {
        iframe.src = previewUrl;
        iframe.setAttribute('data-loaded', '1');
    }
    drawer.classList.add('open');
    backdrop.classList.add('open');
    if (typeof feather !== 'undefined') feather.replace();
}

function closePreviewDrawer() {
    document.getElementById('previewDrawer').classList.remove('open');
    document.getElementById('previewDrawerBackdrop').classList.remove('open');
}

function refreshPreview() {
    const iframe = document.getElementById('previewIframe');
    iframe.src = '/customer/quote.php?_preview=<?php echo $quoteId; ?>&_t=' + Date.now();
    const btn = document.querySelector('#previewDrawer .btn-outline-light');
    if (btn) {
        btn.textContent = 'Refreshing…';
        iframe.onload = () => {
            btn.innerHTML = '<i data-feather="refresh-cw" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>Refresh';
            if (typeof feather !== 'undefined') feather.replace();
        };
    }
}

// Keyboard shortcut: Escape closes drawer
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closePreviewDrawer();
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
