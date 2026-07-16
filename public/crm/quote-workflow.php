<?php
/**
 * Quote Workflow Page
 * Split-screen: quote request details + quote form (left) | territory map + measure tool (right)
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

// Load MeasurementService for shared save logic
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
if (defined('APP_ROOT')) {
    require_once APP_ROOT . '/Services/MeasurementService.php';
}

requireLogin();
$user = getCurrentUser();
$db = getDB();
$error = '';
$success = '';

// Accept request_id OR contact_id + property_id for direct entry
$requestId = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

// Direct entry: auto-create a quote request from contact + property
if (!$requestId && !empty($_GET['contact_id']) && !empty($_GET['property_id'])) {
    $directContactId = intval($_GET['contact_id']);
    $directPropertyId = intval($_GET['property_id']);

    // Verify contact and property exist
    $contactCheck = $db->prepare("SELECT id FROM contacts WHERE id = ?");
    $contactCheck->execute([$directContactId]);
    $propertyCheck = $db->prepare("SELECT id FROM properties WHERE id = ?");
    $propertyCheck->execute([$directPropertyId]);

    if ($contactCheck->fetch() && $propertyCheck->fetch()) {
        // Check for existing open (non-quoted) request for this contact+property
        $existingStmt = $db->prepare("
            SELECT id FROM quote_requests
            WHERE contact_id = ? AND property_id = ? AND status IN ('new', 'reviewing')
            ORDER BY created_at DESC LIMIT 1
        ");
        $existingStmt->execute([$directContactId, $directPropertyId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Reuse existing open request
            $requestId = (int)$existing['id'];
        } else {
            // Auto-create a quote request
            $createStmt = $db->prepare("
                INSERT INTO quote_requests
                    (contact_id, property_id, service_types, urgency, project_description,
                     status, source)
                VALUES (?, ?, '', 'inquiring', '', 'reviewing', 'crm-direct')
            ");
            $createStmt->execute([$directContactId, $directPropertyId]);
            $requestId = (int)$db->lastInsertId();
        }

        // Redirect to canonical URL with request_id
        header("Location: quote-workflow.php?request_id={$requestId}");
        exit;
    }
}

if (!$requestId) {
    header('Location: dashboard_appstack.php');
    exit;
}

// Handle POST actions before loading page data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // AJAX: Save measurements (using shared MeasurementService)
    if ($_POST['action'] === 'save_measurements') {
        header('Content-Type: application/json');

        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            exit;
        }

        $propId = (int)($_POST['property_id'] ?? 0);
        $measurements = json_decode($_POST['measurements'] ?? '[]', true);

        if (!$propId || empty($measurements)) {
            echo json_encode(['success' => false, 'error' => 'Missing property ID or measurements']);
            exit;
        }

        if (function_exists('saveMeasurementsForProperty')) {
            $result = saveMeasurementsForProperty($propId, $measurements, $user['id']);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'error' => 'MeasurementService not available']);
        }
        exit;
    }

    // Save quote
    if ($_POST['action'] === 'save_quote' || $_POST['action'] === 'save_send') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid request. Please try again.';
        } else {
            $propertyId = intval($_POST['property_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $allowedServiceTypes = ['landscaping', 'lawn_care', 'snow_removal', 'garden_maintenance', 'seasonal_cleanup'];
            $serviceType = in_array($_POST['service_type'] ?? '', $allowedServiceTypes) ? $_POST['service_type'] : 'landscaping';
            $validUntil = $_POST['valid_until'] ?? null;
            $terms = trim($_POST['terms'] ?? '');
            $notesCustomer = trim($_POST['notes_customer'] ?? '');
            $notesInternal = trim($_POST['notes_internal'] ?? '');

            $lineItemsData = json_decode($_POST['line_items'] ?? '[]', true) ?: [];

            if (!$propertyId) {
                $error = 'No property associated with this request.';
            } elseif (empty($lineItemsData)) {
                $error = 'Please add at least one line item.';
            } else {
                try {
                    $db->beginTransaction();

                    $quoteNumber = generateQuoteNumber();
                    $accessToken = generateAccessToken();
                    $totals = calculateQuoteTotals($lineItemsData);
                    $status = ($_POST['action'] === 'save_send') ? 'sent' : 'draft';

                    $stmt = $db->prepare("
                        INSERT INTO quotes (
                            quote_number, property_id, title, service_type, amount,
                            subtotal, tax_rate, tax_amount, valid_until, terms,
                            notes_customer, notes_internal, access_token,
                            token_expires_at, created_by, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)
                    ");
                    $stmt->execute([
                        $quoteNumber, $propertyId, $title, $serviceType, $totals['total'],
                        $totals['subtotal'], $totals['tax_rate'], $totals['tax_amount'],
                        $validUntil ?: null, $terms, $notesCustomer, $notesInternal,
                        $accessToken, $user['id'], $status
                    ]);
                    $quoteId = $db->lastInsertId();

                    // Insert line items (extended with pricing snapshot columns)
                    $liStmt = $db->prepare("
                        INSERT INTO quote_line_items (
                            quote_id, product_id, pricing_rule_id, measurement_group_key,
                            service_type, description, quantity, unit_type,
                            unit_price, line_total, sort_order, is_optional,
                            units_used, price_per_unit, minimum_applied, included_units,
                            pricing_snapshot, bundle_id, is_upsell
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($lineItemsData as $index => $item) {
                        $liStmt->execute([
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
                        ]);
                    }

                    // Update quote request status
                    $db->prepare("
                        UPDATE quote_requests SET status = 'quoted', quote_id = ?, converted_at = NOW()
                        WHERE id = ?
                    ")->execute([$quoteId, $requestId]);

                    logActivityExtended($user['id'], 'Quote created', "Quote {$quoteNumber} created from request #{$requestId}", null, null, $quoteId);

                    $db->commit();

                    header("Location: quotes/view.php?id={$quoteId}&saved=1");
                    exit;

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Quote save error: " . $e->getMessage());
                    $error = 'Error saving quote. Please try again.';
                }
            }
        }
    }

    // Update quote request status (decline, etc.)
    if ($_POST['action'] === 'update_status') {
        if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $newStatus = $_POST['new_status'] ?? '';
            $allowed = ['reviewing', 'declined', 'spam'];
            if (in_array($newStatus, $allowed)) {
                $db->prepare("UPDATE quote_requests SET status = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$newStatus, $requestId]);
                if ($newStatus === 'declined' || $newStatus === 'spam') {
                    header('Location: dashboard_appstack.php');
                    exit;
                }
            }
        }
    }
}

// Load quote request with contact + property
// Use conditional column selection to handle databases with/without measurement columns
$stmt = $db->prepare("
    SELECT
        qr.*,
        c.id AS contact_id, c.first_name, c.last_name, c.email, c.phone,
        c.preferred_contact_method,
        p.id AS property_id, p.property_name, p.property_type, p.address, p.city,
        p.postal_code, p.latitude, p.longitude,
        COALESCE(p.total_lawn_sqft, 0) AS total_lawn_sqft,
        COALESCE(p.total_driveway_sqft, 0) AS total_driveway_sqft
    FROM quote_requests qr
    LEFT JOIN contacts c ON qr.contact_id = c.id
    LEFT JOIN properties p ON qr.property_id = p.id
    WHERE qr.id = ?
");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

// If query fails due to missing columns, try without them
if ($request === false && $db->errorInfo()[0] !== '00000') {
    $stmt = $db->prepare("
        SELECT
            qr.*,
            c.id AS contact_id, c.first_name, c.last_name, c.email, c.phone,
            c.preferred_contact_method,
            p.id AS property_id, p.property_name, p.property_type, p.address, p.city,
            p.postal_code, p.latitude, p.longitude
        FROM quote_requests qr
        LEFT JOIN contacts c ON qr.contact_id = c.id
        LEFT JOIN properties p ON qr.property_id = p.id
        WHERE qr.id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($request) {
        $request['total_lawn_sqft'] = 0;
        $request['total_driveway_sqft'] = 0;
    }
}

if (!$request) {
    header('Location: dashboard_appstack.php');
    exit;
}

// Load existing measurements for this property
$measurements = [];
if ($request['property_id']) {
    $stmt = $db->prepare("
        SELECT * FROM property_measurements
        WHERE property_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$request['property_id']]);
    $measurements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check arrival border status for this property
$hasArrivalBorder = false;
if ($request['property_id']) {
    try {
        $abStmt = $db->prepare("SELECT COUNT(*) FROM job_geofences WHERE property_id = ? AND zone_type = 'arrival_border'");
        $abStmt->execute([(int)$request['property_id']]);
        $hasArrivalBorder = (int)$abStmt->fetchColumn() > 0;
    } catch (Exception $e) { /* table may not exist yet */ }
}

// Load products from catalog for "From Template" dropdown
$products = [];
try {
    $products = $db->query("
        SELECT p.id, p.name, p.description, p.base_price, p.min_price,
               c.name as category_name, u.abbreviation as unit_abbreviation, u.name as unit_name
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.id
        LEFT JOIN unit_types u ON p.unit_type_id = u.id
        WHERE p.is_archived = 0 AND p.active = 1
        ORDER BY c.name, p.display_order, p.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Products table may not exist yet
}

// Load measurement groups for dynamic area type dropdown
$wfMeasurementGroups = [];
try {
    $wfMeasurementGroups = $db->query("
        SELECT id, group_key, group_label, measurement_types, unit, sort_order
        FROM measurement_groups WHERE is_active = 1 ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

$wfAreaTypeOptions = [];
foreach ($wfMeasurementGroups as $g) {
    $types = array_filter(array_map('trim', explode(',', $g['measurement_types'])));
    foreach ($types as $t) {
        $wfAreaTypeOptions[] = ['value' => $t, 'label' => ucfirst(str_replace('_', ' ', $t))];
    }
}

// Auto-update status to 'reviewing' if currently 'new'
if ($request['status'] === 'new') {
    $db->prepare("UPDATE quote_requests SET status = 'reviewing', reviewed_at = NOW() WHERE id = ? AND status = 'new'")
       ->execute([$requestId]);
    $request['status'] = 'reviewing';
}

$csrfToken = generateCSRFToken();

// Prepare contact name
$contactName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
if (empty($contactName)) $contactName = 'Unknown Contact';

// Format services
$servicesList = formatServiceTypes($request['service_types'] ?? '');
$servicesStr = !empty($servicesList) ? implode(', ', $servicesList) : 'Not specified';

// Property address
$fullAddress = $request['address'] ?? '';
if ($request['city']) $fullAddress .= ', ' . $request['city'];

// ─── Contact Intelligence ───────────────────────────────
$contactBI = ['total_quotes' => 0, 'accepted_quotes' => 0, 'total_value' => 0, 'active_plans' => 0, 'avg_quote' => 0, 'acceptance_rate' => 0, 'last_quote_date' => null];
if ($request['contact_id']) {
    $cid = (int)$request['contact_id'];
    // Quote history for this contact (via properties they own)
    try {
        $biStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_quotes,
                SUM(CASE WHEN q.status IN ('accepted', 'approved_verbal') THEN 1 ELSE 0 END) AS accepted_quotes,
                SUM(CASE WHEN q.status IN ('accepted', 'approved_verbal') THEN q.amount ELSE 0 END) AS total_value,
                AVG(q.amount) AS avg_quote,
                MAX(q.created_at) AS last_quote_date
            FROM quotes q
            JOIN properties p ON q.property_id = p.id
            WHERE p.site_contact_id = ?
        ");
        $biStmt->execute([$cid]);
        $biRow = $biStmt->fetch(PDO::FETCH_ASSOC);
        if ($biRow) {
            $contactBI['total_quotes'] = (int)$biRow['total_quotes'];
            $contactBI['accepted_quotes'] = (int)$biRow['accepted_quotes'];
            $contactBI['total_value'] = (float)$biRow['total_value'];
            $contactBI['avg_quote'] = (float)$biRow['avg_quote'];
            $contactBI['last_quote_date'] = $biRow['last_quote_date'];
            $contactBI['acceptance_rate'] = $contactBI['total_quotes'] > 0
                ? round(($contactBI['accepted_quotes'] / $contactBI['total_quotes']) * 100)
                : 0;
        }
    } catch (Exception $e) { /* table may not exist */ }

    // Active job plans
    try {
        $planStmt = $db->prepare("
            SELECT COUNT(*) AS cnt FROM job_plans jp
            JOIN properties p ON jp.property_id = p.id
            WHERE p.site_contact_id = ? AND jp.status = 'active'
        ");
        $planStmt->execute([$cid]);
        $contactBI['active_plans'] = (int)$planStmt->fetchColumn();
    } catch (Exception $e) { /* table may not exist */ }
}

// ─── Property Intelligence ──────────────────────────────
$propertyBI = ['nearby_quotes' => 0, 'nearby_avg' => 0, 'nearby_properties' => 0];
if ($request['latitude'] && $request['longitude']) {
    $lat = (float)$request['latitude'];
    $lng = (float)$request['longitude'];
    // Find quotes for properties within ~2km radius
    try {
        $nearbyStmt = $db->prepare("
            SELECT COUNT(*) AS cnt, AVG(q.amount) AS avg_amt, COUNT(DISTINCT p.id) AS prop_cnt
            FROM quotes q
            JOIN properties p ON q.property_id = p.id
            WHERE p.id != ? AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
              AND ABS(p.latitude - ?) < 0.02 AND ABS(p.longitude - ?) < 0.02
              AND q.status IN ('accepted', 'approved_verbal', 'sent', 'draft')
        ");
        $nearbyStmt->execute([(int)$request['property_id'], $lat, $lng]);
        $nearbyRow = $nearbyStmt->fetch(PDO::FETCH_ASSOC);
        if ($nearbyRow) {
            $propertyBI['nearby_quotes'] = (int)$nearbyRow['cnt'];
            $propertyBI['nearby_avg'] = (float)$nearbyRow['avg_amt'];
            $propertyBI['nearby_properties'] = (int)$nearbyRow['prop_cnt'];
        }
    } catch (Exception $e) { /* ok */ }
}

$pageTitle = 'Quote Workflow - ' . htmlspecialchars($contactName);
$activePage = 'quotes';
$mdtVer    = @filemtime(__DIR__ . '/js/map-draw/map-draw-tool.js') ?: '1';
$extraHead = '<script src="/crm/js/map-draw/map-draw-tool.js?v=' . $mdtVer . '"></script>'
           . '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars(GOOGLE_MAPS_API_KEY, ENT_QUOTES, 'UTF-8') . '&libraries=geometry&loading=async&callback=initMaps" async defer></script>';
?>
<?php include 'includes/appstack_head.php'; ?>

                    <!-- Page Header -->
                    <div class="mw-workflow-header">
                        <div>
                            <a href="dashboard_appstack.php" class="text-muted" style="font-size: 0.85rem; text-decoration: none;">
                                <i class="align-middle" data-feather="arrow-left" style="width:14px;height:14px;"></i> Dashboard
                            </a>
                            <h3 class="mt-1">Quote Workflow — <?php echo h($contactName); ?></h3>
                            <span class="text-muted">Request #<?php echo $requestId; ?> | <?php echo h(timeAgo($request['created_at'])); ?></span>
                        </div>
                        <div class="mw-panel-toggle btn-group" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePanel('collapsed')" title="Hide right panel">
                                <i class="align-middle" data-feather="sidebar" style="width:14px;height:14px;"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary active" onclick="togglePanel('normal')" title="Normal view">
                                <i class="align-middle" data-feather="columns" style="width:14px;height:14px;"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePanel('expanded')" title="Expand right panel">
                                <i class="align-middle" data-feather="maximize-2" style="width:14px;height:14px;"></i>
                            </button>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo h($error); ?></div>
                    <?php endif; ?>

                    <?php if (!$hasArrivalBorder && $request['property_id'] && floatval($request['latitude'] ?? 0) != 0): ?>
                    <div class="alert mw-wf-border-alert mb-2" id="borderAlert">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <i data-feather="alert-triangle" style="width:16px;height:16px;" class="mr-2 text-warning"></i>
                                <span>
                                    <strong>No arrival border drawn.</strong>
                                    Draw one for accurate auto-clock-in on dense routes.
                                    <a href="#" onclick="wfDrawArrivalBorder(); return false;" class="mw-wf-border-link">
                                        Draw arrival border with the Measure Tool &rarr;
                                    </a>
                                </span>
                            </div>
                            <button type="button" class="close ml-2" onclick="document.getElementById('borderAlert').style.display='none'" aria-label="Dismiss">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row" id="workflowContainer">
                        <!-- LEFT PANEL: Request Details + Quote Form -->
                        <div class="col-lg-7" id="leftPanel">

                            <!-- Request Details (collapsible) -->
                            <div class="card mw-wf-request-card">
                                <div class="card-header d-flex justify-content-between align-items-center py-2 mw-wf-collapse-toggle" onclick="toggleRequestDetails()" style="cursor: pointer;">
                                    <div class="d-flex align-items-center">
                                        <i class="align-middle mr-2 mw-wf-chevron" data-feather="chevron-right" style="width:14px;height:14px;transition:transform 0.2s;" id="requestChevron"></i>
                                        <h6 class="card-title mb-0"><?php echo h($contactName); ?></h6>
                                        <span class="text-muted ml-2 small"><?php echo h($fullAddress ?: 'No address'); ?></span>
                                    </div>
                                    <div>
                                        <?php if ($request['email']): ?>
                                            <a href="mailto:<?php echo h($request['email']); ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="event.stopPropagation();" title="<?php echo h($request['email']); ?>">
                                                <i class="align-middle" data-feather="mail" style="width:12px;height:12px;"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($request['phone']): ?>
                                            <a href="tel:<?php echo h($request['phone']); ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="event.stopPropagation();" title="<?php echo h($request['phone']); ?>">
                                                <i class="align-middle" data-feather="phone" style="width:12px;height:12px;"></i>
                                            </a>
                                        <?php endif; ?>
                                        <span class="mw-urgency-badge mw-urgency-<?php echo h($request['urgency'] ?? 'inquiring'); ?> ml-1">
                                            <?php echo h(ucfirst($request['urgency'] ?? 'inquiring')); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body py-2" id="requestDetailsBody" style="display: none;">
                                    <div class="mw-detail-grid mb-2" style="grid-template-columns: 1fr 1fr 1fr;">
                                        <div class="mw-detail-item">
                                            <label>Email</label>
                                            <div class="value"><?php echo h($request['email'] ?? 'N/A'); ?></div>
                                        </div>
                                        <div class="mw-detail-item">
                                            <label>Phone</label>
                                            <div class="value"><?php echo h($request['phone'] ?? 'N/A'); ?></div>
                                        </div>
                                        <div class="mw-detail-item">
                                            <label>Property Type</label>
                                            <div class="value"><?php echo h(ucfirst($request['property_type'] ?? 'N/A')); ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($servicesList)): ?>
                                        <div class="mb-2">
                                            <?php foreach ($servicesList as $svc): ?>
                                                <span class="mw-service-badge"><?php echo h($svc); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($request['project_description']): ?>
                                        <div class="mb-2" style="background: #f8fafc; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 0.85rem;">
                                            <?php echo nl2br(h($request['project_description'])); ?>
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="new_status" value="declined">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Decline this request?')">
                                            <i class="align-middle mr-1" data-feather="x-circle" style="width:12px;height:12px;"></i> Decline
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Contact & Property Intelligence -->
                            <?php if ($contactBI['total_quotes'] > 0 || $propertyBI['nearby_quotes'] > 0 || $contactBI['active_plans'] > 0): ?>
                            <div class="mw-wf-intel-bar mb-2">
                                <?php if ($contactBI['total_quotes'] > 0): ?>
                                    <div class="mw-wf-intel-chip" title="<?php echo $contactBI['accepted_quotes']; ?> of <?php echo $contactBI['total_quotes']; ?> quotes accepted">
                                        <i data-feather="user" style="width:12px;height:12px;"></i>
                                        <?php echo $contactBI['acceptance_rate']; ?>% accept rate
                                        <span class="mw-wf-intel-sub">(<?php echo $contactBI['accepted_quotes']; ?>/<?php echo $contactBI['total_quotes']; ?>)</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($contactBI['total_value'] > 0): ?>
                                    <div class="mw-wf-intel-chip" title="Total value of accepted quotes">
                                        <i data-feather="dollar-sign" style="width:12px;height:12px;"></i>
                                        $<?php echo number_format($contactBI['total_value'], 0); ?> lifetime
                                    </div>
                                <?php endif; ?>
                                <?php if ($contactBI['avg_quote'] > 0): ?>
                                    <div class="mw-wf-intel-chip" title="Average quote amount for this contact">
                                        <i data-feather="bar-chart-2" style="width:12px;height:12px;"></i>
                                        $<?php echo number_format($contactBI['avg_quote'], 0); ?> avg quote
                                    </div>
                                <?php endif; ?>
                                <?php if ($contactBI['active_plans'] > 0): ?>
                                    <div class="mw-wf-intel-chip mw-wf-intel-active" title="Active recurring plans">
                                        <i data-feather="repeat" style="width:12px;height:12px;"></i>
                                        <?php echo $contactBI['active_plans']; ?> active plan<?php echo $contactBI['active_plans'] !== 1 ? 's' : ''; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($propertyBI['nearby_quotes'] > 0): ?>
                                    <div class="mw-wf-intel-chip" title="<?php echo $propertyBI['nearby_properties']; ?> properties within ~2km">
                                        <i data-feather="map-pin" style="width:12px;height:12px;"></i>
                                        <?php echo $propertyBI['nearby_properties']; ?> nearby · $<?php echo number_format($propertyBI['nearby_avg'], 0); ?> avg
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Quote Creation Form -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Create Quote</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="quoteForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="save_quote" id="quoteAction">
                                        <input type="hidden" name="property_id" value="<?php echo (int)$request['property_id']; ?>">
                                        <input type="hidden" name="line_items" id="lineItemsInput" value="[]">

                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Quote Title</label>
                                                <input type="text" name="title" class="form-control" placeholder="e.g., Spring Lawn Care" value="<?php
                                                    $titleParts = [];
                                                    if ($servicesStr !== 'Not specified') $titleParts[] = $servicesStr;
                                                    if (!empty($request['address'])) $titleParts[] = $request['address'];
                                                    echo h(implode(' - ', $titleParts) ?: '');
                                                ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Service Type</label>
                                                <select name="service_type" class="form-control">
                                                    <option value="landscaping">Landscaping</option>
                                                    <option value="lawn_care">Lawn Care</option>
                                                    <option value="snow_removal">Snow Removal</option>
                                                    <option value="garden_maintenance">Garden Maintenance</option>
                                                    <option value="seasonal_cleanup">Seasonal Cleanup</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Valid Until</label>
                                                <input type="date" name="valid_until" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                            </div>
                                        </div>

                                        <!-- Measurement Auto-Fill + Service Picker -->
                                        <div class="card mb-2 mw-measurement-summary" id="wfMeasurementPanel" style="display:none;">
                                            <div class="card-header d-flex justify-content-between align-items-center py-2">
                                                <strong class="small mb-0">Property Measurements</strong>
                                                <span class="badge badge-info small" id="wfMeasurementBadge"></span>
                                            </div>
                                            <div class="card-body py-2">
                                                <!-- No measurements message -->
                                                <div id="wfNoMeasurementsMsg" style="display:none;" class="text-center py-2">
                                                    <p class="mb-1 text-muted small">This property hasn't been measured yet.</p>
                                                    <p class="mb-0 small text-muted">Use the Measure Tool on the right to draw areas, then click "Save Measurements to Property".</p>
                                                </div>
                                                <!-- Has measurements content -->
                                                <div id="wfHasMeasurementsContent" style="display:none;">
                                                    <div id="wfMeasurementSummary" class="small text-muted mb-2"></div>
                                                    <div id="wfServicePickerContainer"></div>
                                                    <button type="button" class="btn btn-sm btn-success mw-autofill-btn" id="wfAddSelectedServicesBtn" onclick="wfAddSelectedServices()" style="display:none;">
                                                        Select services above
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Line Items -->
                                        <h6 class="mb-2" style="font-weight: 600;">Services & Pricing</h6>
                                        <div class="mw-line-items-header">
                                            <div>Service</div>
                                            <div>Description</div>
                                            <div>Qty</div>
                                            <div>Price</div>
                                            <div>Total</div>
                                            <div></div>
                                        </div>
                                        <div id="lineItemsContainer"></div>

                                        <div class="d-flex mt-2 mb-3" style="gap: 0.5rem;">
                                            <?php if (!empty($products)): ?>
                                                <div class="dropdown">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown">
                                                        + From Template
                                                    </button>
                                                    <div class="dropdown-menu" style="max-height: 400px; overflow-y: auto;">
                                                        <?php
                                                        $currentCategory = null;
                                                        foreach ($products as $prod):
                                                            if ($prod['category_name'] !== $currentCategory):
                                                                $currentCategory = $prod['category_name'];
                                                        ?>
                                                            <h6 class="dropdown-header"><?php echo h($currentCategory ?? 'Uncategorized'); ?></h6>
                                                        <?php endif; ?>
                                                            <a class="dropdown-item" href="#" onclick="addProductLine(<?php echo htmlspecialchars(json_encode($prod), ENT_QUOTES); ?>); return false;">
                                                                <?php echo h($prod['name']); ?> — <?php echo formatCurrency($prod['base_price']); ?>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLine()">
                                                + Add Custom Line
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="addBundleBtn" onclick="openBundlePicker()">
                                                <i data-feather="package" style="width:13px;height:13px;vertical-align:middle;margin-right:3px;"></i>+ Add Bundle
                                            </button>
                                        </div>

                                        <div class="mw-totals">
                                            <div class="mw-total-row">
                                                <span>Subtotal</span>
                                                <span id="subtotalDisplay">$0.00</span>
                                            </div>
                                            <div class="mw-total-row">
                                                <span>GST (5%)</span>
                                                <span id="taxDisplay">$0.00</span>
                                            </div>
                                            <div class="mw-total-row mw-grand">
                                                <span>Total</span>
                                                <span id="totalDisplay">$0.00</span>
                                            </div>
                                        </div>

                                        <!-- Terms & Notes -->
                                        <div class="row mt-3">
                                            <div class="col-md-12 mb-2">
                                                <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Terms & Conditions</label>
                                                <textarea name="terms" class="form-control" rows="3">Payment due within 30 days of service completion.
All prices include GST.
Work to be completed weather permitting.</textarea>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Notes for Customer</label>
                                                <textarea name="notes_customer" class="form-control" rows="2" placeholder="Visible to customer..."></textarea>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Internal Notes</label>
                                                <textarea name="notes_internal" class="form-control" rows="2" placeholder="Not shown to customer..."></textarea>
                                            </div>
                                        </div>

                                        <div class="d-flex mt-3" style="gap: 0.5rem;">
                                            <button type="submit" class="btn btn-primary" onclick="document.getElementById('quoteAction').value='save_quote';">
                                                <i class="align-middle mr-1" data-feather="save" style="width:14px;height:14px;"></i> Save Draft
                                            </button>
                                            <button type="submit" class="btn btn-mowology" onclick="document.getElementById('quoteAction').value='save_send';">
                                                <i class="align-middle mr-1" data-feather="send" style="width:14px;height:14px;"></i> Save & Send
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                        </div><!-- /leftPanel -->

                        <!-- RIGHT PANEL: Territory Map + Measure Tool -->
                        <div class="col-lg-5" id="rightPanel">

                            <!-- Territory Map -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="align-middle mr-1" data-feather="map-pin" style="width:16px;height:16px;"></i>
                                        Property Location
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="mw-map-container" id="territoryMap"></div>
                                </div>
                            </div>

                            <!-- Measure Tool -->
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="align-middle mr-1" data-feather="maximize" style="width:16px;height:16px;"></i>
                                        Measure Tool
                                    </h5>
                                    <select id="mapTypeSelector" class="form-control form-control-sm" style="width: auto;">
                                        <option value="satellite" selected>Satellite</option>
                                        <option value="hybrid">Hybrid</option>
                                        <option value="roadmap">Roadmap</option>
                                    </select>
                                </div>
                                <div class="card-body">
                                    <div class="mw-measure-map-container" id="measureMap"></div>

                                    <!-- Drawing Tools -->
                                    <div class="mw-measure-tools">
                                        <button class="btn btn-sm btn-outline-secondary" id="drawPolygonBtn" onclick="startDrawing('polygon')" disabled>
                                            <i class="align-middle" data-feather="layers" style="width:14px;height:14px;"></i> Polygon
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" id="drawRectangleBtn" onclick="startDrawing('rectangle')" disabled>
                                            <i class="align-middle" data-feather="square" style="width:14px;height:14px;"></i> Rectangle
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="clearCurrentDrawing()">
                                            <i class="align-middle" data-feather="trash-2" style="width:14px;height:14px;"></i> Clear
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="clearAllAreas()">
                                            Clear All
                                        </button>
                                    </div>

                                    <!-- Arrival Border (drawn inline with the same tool) -->
                                    <div class="mw-measure-zone-tools mt-2">
                                        <button class="btn btn-sm mw-zone-btn-border btn-block" id="drawBorderBtn" onclick="wfDrawArrivalBorder()">
                                            <i class="align-middle" data-feather="navigation" style="width:14px;height:14px;"></i>
                                            <span id="wfBorderBtnLabel">Draw Arrival Border</span>
                                        </button>
                                        <p class="text-muted mb-0 mt-1" style="font-size:0.72rem;">
                                            One boundary around the whole property. Crew entering it auto-clocks in.
                                        </p>
                                    </div>

                                    <!-- Current Measurement -->
                                    <div id="currentMeasurement" style="display: none;">
                                        <div class="mw-measurement-display mb-2">
                                            <div class="mw-measurement-row">
                                                <span class="mw-measurement-label">Area (sq ft)</span>
                                                <span class="mw-measurement-value mw-measurement-large" id="currentSqFt">0</span>
                                            </div>
                                            <div class="mw-measurement-row">
                                                <span class="mw-measurement-label">Perimeter</span>
                                                <span class="mw-measurement-value" id="currentPerimeter">0 ft</span>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-7">
                                                <input type="text" id="areaName" class="form-control form-control-sm" list="areaNameSuggestions" placeholder="Area name (e.g., Front Lawn)">
                                                <datalist id="areaNameSuggestions">
                                                    <option value="Front Lawn">
                                                    <option value="Back Lawn">
                                                    <option value="Side Left">
                                                    <option value="Side Right">
                                                    <option value="Boulevard Front">
                                                    <option value="Boulevard Side">
                                                    <option value="Lane">
                                                    <option value="Garden Bed Front">
                                                    <option value="Garden Bed Back">
                                                    <option value="Driveway">
                                                    <option value="Walkway">
                                                    <option value="Patio">
                                                </datalist>
                                            </div>
                                            <div class="col-5">
                                                <select id="areaType" class="form-control form-control-sm">
                                                    <?php if (!empty($wfAreaTypeOptions)): ?>
                                                        <?php foreach ($wfAreaTypeOptions as $opt): ?>
                                                            <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <option value="lawn">Lawn</option>
                                                        <option value="garden">Garden</option>
                                                        <option value="driveway">Driveway</option>
                                                        <option value="walkway">Walkway</option>
                                                        <option value="patio">Patio</option>
                                                        <option value="parking">Parking</option>
                                                        <option value="hedge">Hedge</option>
                                                        <option value="other">Other</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-primary btn-block" onclick="saveArea()">Save Area</button>
                                    </div>

                                    <!-- Saved Areas -->
                                    <div class="mt-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong style="font-size: 0.9rem;">Measured Areas (<span id="areaCount">0</span>)</strong>
                                            <span class="text-muted" style="font-size: 0.85rem;">Total: <strong id="totalArea">0 sq ft</strong></span>
                                        </div>
                                        <div id="areasList" style="max-height: 200px; overflow-y: auto;">
                                            <p class="text-muted text-center" style="font-size: 0.85rem; padding: 1rem 0;">No areas measured yet</p>
                                        </div>
                                    </div>

                                    <!-- Save Measurements -->
                                    <?php if ($request['property_id']): ?>
                                        <button class="btn btn-sm btn-outline-secondary btn-block mt-2" onclick="saveMeasurementsToDb()">
                                            <i class="align-middle mr-1" data-feather="database" style="width:14px;height:14px;"></i> Save Measurements to Property
                                        </button>
                                        <div id="saveStatus" class="mt-1" style="display: none; font-size: 0.85rem;"></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div><!-- /rightPanel -->
                    </div><!-- /workflowContainer -->

                    <!-- Toast notification -->
                    <div class="mw-toast mw-toast-hidden" id="mwToast"></div>

            <script>
    // ─── Configuration ──────────────────────────────────────
    var propertyLat = <?php echo $request['latitude'] ? floatval($request['latitude']) : 'null'; ?>;
    var propertyLng = <?php echo $request['longitude'] ? floatval($request['longitude']) : 'null'; ?>;
    var propertyAddress = <?php echo json_encode($fullAddress); ?>;
    var propertyId = <?php echo (int)$request['property_id']; ?>;
    var existingMeasurements = <?php echo json_encode($measurements); ?>;

    // ─── Request Details Toggle ────────────────────────────
    function toggleRequestDetails() {
        var body = document.getElementById('requestDetailsBody');
        var chevron = document.getElementById('requestChevron');
        var isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : '';
        chevron.style.transform = isOpen ? '' : 'rotate(90deg)';
    }

    // ─── Line Items Management ──────────────────────────────
    var lineItems = [];
    var itemIdCounter = 0;

    function formatCurrencyJS(amount) {
        return '$' + parseFloat(amount).toFixed(2);
    }

    function calculateTotals() {
        var subtotal = 0;
        lineItems.forEach(function(item) {
            if (!item.is_optional) {
                subtotal += parseFloat(item.line_total) || 0;
            }
        });

        var tax = subtotal * 0.05;
        var total = subtotal + tax;

        document.getElementById('subtotalDisplay').textContent = formatCurrencyJS(subtotal);
        document.getElementById('taxDisplay').textContent = formatCurrencyJS(tax);
        document.getElementById('totalDisplay').textContent = formatCurrencyJS(total);
    }

    function renderLineItems() {
        var container = document.getElementById('lineItemsContainer');
        container.innerHTML = '';

        lineItems.forEach(function(item, index) {
            var row = document.createElement('div');
            row.className = 'mw-line-item' + (item.fromMeasurement ? ' mw-from-measurement' : '');
            row.innerHTML =
                '<input type="text" value="' + escapeHtml(item.service_type || '') + '" placeholder="Service" onchange="updateLineItem(' + index + ', \'service_type\', this.value)">' +
                '<input type="text" value="' + escapeHtml(item.description || '') + '" placeholder="Description" onchange="updateLineItem(' + index + ', \'description\', this.value)">' +
                '<input type="number" value="' + (item.quantity || 1) + '" min="0" step="any" onchange="updateLineItem(' + index + ', \'quantity\', this.value); recalcLine(' + index + ')">' +
                '<input type="number" value="' + (item.unit_price || 0) + '" min="0" step="any" onchange="updateLineItem(' + index + ', \'unit_price\', this.value); recalcLine(' + index + ')">' +
                '<div class="mw-line-total">' + formatCurrencyJS(item.line_total || 0) + '</div>' +
                '<button type="button" class="mw-remove-btn" onclick="removeLine(' + index + ')">&times;</button>';
            container.appendChild(row);
        });

        calculateTotals();
        updateFormInput();
    }

    function updateLineItem(index, field, value) {
        lineItems[index][field] = value;
        updateFormInput();
    }

    function recalcLine(index) {
        var qty = parseFloat(lineItems[index].quantity) || 0;
        var price = parseFloat(lineItems[index].unit_price) || 0;
        lineItems[index].line_total = qty * price;
        renderLineItems();
    }

    function removeLine(index) {
        lineItems.splice(index, 1);
        renderLineItems();
    }

    function addLine(data) {
        var newItem = {
            id: ++itemIdCounter,
            service_type: (data && data.name) ? data.name : '',
            description: (data && data.description) ? data.description : '',
            quantity: (data && data.quantity) ? data.quantity : 1,
            unit_type: (data && data.unit_type) ? data.unit_type : 'each',
            unit_price: (data && data.default_price) ? data.default_price : 0,
            line_total: (data && data.default_price) ? (data.default_price * ((data && data.quantity) ? data.quantity : 1)) : 0,
            is_optional: false,
            fromMeasurement: (data && data.fromMeasurement) ? true : false
        };

        lineItems.push(newItem);
        renderLineItems();
    }

    function addLineFromTemplate(template) {
        addLine(template);
    }

    function addProductLine(product) {
        addLine({
            name: product.name,
            description: product.description || '',
            quantity: 1,
            unit_type: product.unit_abbreviation || product.unit_name || 'each',
            default_price: parseFloat(product.base_price) || 0
        });
    }

    function updateFormInput() {
        document.getElementById('lineItemsInput').value = JSON.stringify(lineItems);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ─── Panel Toggle ───────────────────────────────────────
    function togglePanel(mode) {
        var left = document.getElementById('leftPanel');
        var right = document.getElementById('rightPanel');
        var btns = document.querySelectorAll('.mw-panel-toggle .btn');

        btns.forEach(function(b) { b.classList.remove('active'); });
        if (event && event.currentTarget) {
            event.currentTarget.classList.add('active');
        }

        // Remove all layout classes
        left.className = '';
        right.className = '';

        if (mode === 'collapsed') {
            left.className = 'col-12';
            right.className = 'col-12 mw-right-collapsed';
        } else if (mode === 'expanded') {
            left.className = 'col-lg-5';
            right.className = 'col-lg-7 mw-right-expanded';
        } else {
            left.className = 'col-lg-7';
            right.className = 'col-lg-5';
        }

        // Trigger map resize after layout change
        setTimeout(function() {
            resizeMaps();
        }, 300);
    }

    function resizeMaps() {
        if (typeof google === 'undefined') return;
        if (territoryMapInstance) google.maps.event.trigger(territoryMapInstance, 'resize');
        if (measureTool) measureTool.resize();
    }

    // ─── Map + Measure Tool (shared MapDrawTool engine) ─────
    var territoryMapInstance = null;
    var measureTool = null;          // MapDrawTool instance — the single shared drawing engine
    var savedAreas = [];
    var areaCounter = 0;
    var mapsInited = false;
    var MEAS_API = '/crm/api/measurements.php';
    var GEOFENCE_API = '/crm/api/geofence.php';
    var WF_CSRF = '<?php echo $csrfToken; ?>';
    var wfHasArrivalBorder = <?php echo $hasArrivalBorder ? 'true' : 'false'; ?>;
    var wfDrawIntent = 'measure';    // 'measure' | 'arrival_border'

    var AREA_COLORS = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#6366f1'];
    function colorForArea(i) { return AREA_COLORS[i % AREA_COLORS.length]; }

    // Google Maps script callback (also re-invoked by the load fallback below).
    function initMaps() {
        if (mapsInited) return;
        if (typeof google === 'undefined' || !google.maps) return;
        mapsInited = true;

        var center = (propertyLat && propertyLng) ? { lat: propertyLat, lng: propertyLng } : null;

        // Territory overview map (read-only roadmap — not a drawing surface)
        var tEl = document.getElementById('territoryMap');
        if (tEl) {
            territoryMapInstance = new google.maps.Map(tEl, {
                center: center || { lat: 49.2827, lng: -123.1207 },
                zoom: center ? 16 : 12,
                mapTypeId: 'roadmap',
                mapTypeControl: false, streetViewControl: false, fullscreenControl: false, zoomControl: true, tilt: 0
            });
            if (center) {
                new google.maps.Marker({ map: territoryMapInstance, position: center, title: propertyAddress });
            }
        }

        // Measure tool — the single shared drawing engine
        measureTool = new MapDrawTool({
            mapContainer: 'measureMap',
            center: center,
            zoom: 19,
            address: propertyAddress,
            marker: true,
            mapTypeSelectorId: 'mapTypeSelector',
            onReady: function () {
                document.querySelectorAll('.mw-measure-tools .btn').forEach(function (b) { b.disabled = false; });
                if (wfHasArrivalBorder) {
                    var lbl = document.getElementById('wfBorderBtnLabel');
                    if (lbl) lbl.textContent = 'Redraw Arrival Border';
                }
                renderExistingMeasurements();
                renderExistingZones();
                hydrateFeatherIcons();
            },
            onDraw: function (m) {
                if (wfDrawIntent === 'measure') showCurrentMeasurement(m);
            },
            onComplete: function (m) {
                if (wfDrawIntent === 'arrival_border') wfFinishArrivalBorder(m);
            }
        });
        measureTool.init();

        setTimeout(resizeMaps, 400);
    }

    // Render measurement polygons saved on this property.
    function renderExistingMeasurements() {
        if (!existingMeasurements || !existingMeasurements.length || !measureTool) return;
        existingMeasurements.forEach(function (m) {
            var coords = MapDrawTool.parsePolygonCoords(m.polygon_coords);
            var color = colorForArea(areaCounter);
            var overlay = coords.length
                ? measureTool.renderShape(coords, { shapeType: m.measurement_shape || 'polygon', color: color })
                : null;
            savedAreas.push({
                id: ++areaCounter,
                name: m.measurement_name,
                type: m.measurement_type,
                sqFt: parseFloat(m.area_sqft) || 0,
                perimeter: parseFloat(m.perimeter_ft) || 0,
                coords: coords,
                overlay: overlay,
                color: color,
                fromDb: true
            });
        });
        updateAreasList();
    }

    // Render existing arrival border + work zones for context (read-only here).
    function renderExistingZones() {
        if (!propertyId || !measureTool) return;
        MapDrawTool.loadZones(GEOFENCE_API, propertyId).then(function (zones) {
            zones.forEach(function (z) {
                var isArrival = z.zone_type === 'arrival_border';
                measureTool.renderShape(z.ring, {
                    shapeType: 'polygon',
                    color: isArrival ? '#FFD700' : '#2D8659',
                    strokeColor: isArrival ? '#FFD700' : '#2D8659',
                    fillOpacity: isArrival ? 0.06 : 0.12
                });
            });
        });
    }

    // ─── Drawing Tools (measurements) ───────────────────────
    function startDrawing(type) {
        if (!measureTool || !measureTool.isReady()) return;
        wfDrawIntent = 'measure';
        document.querySelectorAll('.mw-measure-tools .btn').forEach(function (btn) { btn.classList.remove('mw-tool-active'); });
        var btn = document.getElementById(type === 'rectangle' ? 'drawRectangleBtn' : 'drawPolygonBtn');
        if (btn) btn.classList.add('mw-tool-active');
        measureTool.startDraw(type);
    }

    function clearCurrentDrawing() {
        if (measureTool) measureTool.clearCurrent();
        document.getElementById('currentMeasurement').style.display = 'none';
        document.querySelectorAll('.mw-measure-tools .btn').forEach(function (btn) { btn.classList.remove('mw-tool-active'); });
    }

    function showCurrentMeasurement(m) {
        if (!m) { document.getElementById('currentMeasurement').style.display = 'none'; return; }
        document.getElementById('currentSqFt').textContent = Math.round(m.sqFeet).toLocaleString();
        document.getElementById('currentPerimeter').textContent = Math.round(m.perimeterFeet).toLocaleString() + ' ft';
        document.getElementById('currentMeasurement').style.display = 'block';
    }

    function saveArea() {
        if (!measureTool || !measureTool.hasCurrent()) return;
        var m = measureTool.getCurrent();
        var areaName = document.getElementById('areaName').value || ('Area ' + (areaCounter + 1));
        var areaType = document.getElementById('areaType').value;
        var color = colorForArea(areaCounter);
        var adopted = measureTool.adoptCurrent({ color: color });

        savedAreas.push({
            id: ++areaCounter,
            name: areaName,
            type: areaType,
            sqFt: Math.round(m.sqFeet),
            perimeter: Math.round(m.perimeterFeet),
            coords: m.coords,
            overlay: adopted ? adopted.overlay : null,
            color: color
        });

        document.getElementById('currentMeasurement').style.display = 'none';
        document.getElementById('areaName').value = '';
        document.querySelectorAll('.mw-measure-tools .btn').forEach(function (btn) { btn.classList.remove('mw-tool-active'); });
        updateAreasList();
    }

    function updateAreasList() {
        var container = document.getElementById('areasList');

        if (savedAreas.length === 0) {
            container.innerHTML = '<p class="text-muted text-center" style="font-size: 0.85rem; padding: 1rem 0;">No areas measured yet</p>';
            document.getElementById('areaCount').textContent = '0';
            document.getElementById('totalArea').textContent = '0 sq ft';
            return;
        }

        var html = '';
        savedAreas.forEach(function(area) {
            html += '<div class="mw-area-item" style="border-left: 4px solid ' + (area.color || '#2D8659') + ';">' +
                '<div class="mw-area-item-info">' +
                    '<div class="mw-area-item-name">' + escapeHtml(area.name) + '</div>' +
                    '<div class="mw-area-item-detail">' + escapeHtml(area.type) + ' &mdash; ' + area.sqFt.toLocaleString() + ' sq ft</div>' +
                '</div>' +
                '<div class="mw-area-item-actions">' +
                    (area.overlay ? '<button onclick="zoomToArea(' + area.id + ')" title="Zoom">&#128269;</button>' : '') +
                    '<button onclick="deleteArea(' + area.id + ')" title="Delete">&#10005;</button>' +
                '</div>' +
            '</div>';
        });

        container.innerHTML = html;

        var totalSqFt = savedAreas.reduce(function(sum, a) { return sum + a.sqFt; }, 0);
        document.getElementById('areaCount').textContent = savedAreas.length;
        document.getElementById('totalArea').textContent = totalSqFt.toLocaleString() + ' sq ft';
    }

    function deleteArea(id) {
        var area = savedAreas.find(function(a) { return a.id === id; });
        if (area && area.overlay && measureTool) measureTool.removeOverlay(area.overlay);
        savedAreas = savedAreas.filter(function(a) { return a.id !== id; });
        updateAreasList();
    }

    function zoomToArea(id) {
        var area = savedAreas.find(function(a) { return a.id === id; });
        if (area && area.overlay && measureTool) measureTool.zoomTo(area.overlay);
    }

    function clearAllAreas() {
        if (!confirm('Clear all measured areas?')) return;
        savedAreas.forEach(function(area) {
            if (area.overlay && measureTool) measureTool.removeOverlay(area.overlay);
        });
        savedAreas = [];
        clearCurrentDrawing();
        updateAreasList();
    }

    // ─── Arrival Border (drawn inline via the same engine) ──
    function wfDrawArrivalBorder() {
        if (!measureTool || !measureTool.isReady()) { showToast('Map is still loading — try again in a moment.'); return; }
        if (!propertyId) { showToast('Save the property first to attach an arrival border.'); return; }
        wfDrawIntent = 'arrival_border';
        document.querySelectorAll('.mw-measure-tools .btn').forEach(function (btn) { btn.classList.remove('mw-tool-active'); });
        showToast('Click points around the whole property, then close the shape to save the arrival border.');
        measureTool.startDraw('polygon');
    }

    function wfFinishArrivalBorder(m) {
        wfDrawIntent = 'measure';
        if (!m || !m.coords || m.coords.length < 3) { if (measureTool) measureTool.clearCurrent(); return; }
        var adopted = measureTool.adoptCurrent({ color: '#FFD700', strokeColor: '#FFD700', fillOpacity: 0.08 });
        MapDrawTool.saveZone(GEOFENCE_API, {
            csrfToken: WF_CSRF,
            propertyId: propertyId,
            zoneType: 'arrival_border',
            coords: m.coords,
            label: 'Arrival Border'
        }).then(function (data) {
            if (data && data.success) {
                wfHasArrivalBorder = true;
                var alertEl = document.getElementById('borderAlert');
                if (alertEl) alertEl.style.display = 'none';
                var lbl = document.getElementById('wfBorderBtnLabel');
                if (lbl) lbl.textContent = 'Redraw Arrival Border';
                showToast('Arrival border saved.');
            } else {
                if (adopted) measureTool.removeOverlay(adopted.overlay);
                showToast('Could not save arrival border: ' + ((data && data.error) || 'error'));
            }
        }).catch(function (err) {
            if (adopted) measureTool.removeOverlay(adopted.overlay);
            showToast('Error saving arrival border: ' + err.message);
        });
    }

    // ─── Save Measurements to DB (shared MapDrawTool endpoint) ──
    function saveMeasurementsToDb() {
        if (savedAreas.length === 0) {
            alert('No areas to save. Please measure at least one area first.');
            return;
        }

        var statusDiv = document.getElementById('saveStatus');
        statusDiv.style.display = 'block';
        statusDiv.className = 'mt-1 alert alert-info';
        statusDiv.textContent = 'Saving measurements...';

        var measurements = savedAreas.map(function(area) {
            return {
                name: area.name,
                type: area.type,
                sqFt: area.sqFt,
                perimeter: area.perimeter || null,
                shape: 'polygon',
                coords: area.coords || null
            };
        });

        MapDrawTool.saveMeasurements(MEAS_API, propertyId, WF_CSRF, measurements)
        .then(function(data) {
            if (data.success) {
                statusDiv.className = 'mt-1 alert alert-success';
                statusDiv.textContent = data.message || 'Measurements saved.';
                // Refresh the service picker with the newly saved measurements
                wfFetchMeasurements();
            } else {
                statusDiv.className = 'mt-1 alert alert-danger';
                statusDiv.textContent = data.error || 'Failed to save';
            }
        })
        .catch(function(err) {
            statusDiv.className = 'mt-1 alert alert-danger';
            statusDiv.textContent = 'Error: ' + err.message;
        });
    }

    // ─── Toast ──────────────────────────────────────────────
    function showToast(message) {
        var toast = document.getElementById('mwToast');
        toast.textContent = message;
        toast.classList.remove('mw-toast-hidden');
        setTimeout(function() {
            toast.classList.add('mw-toast-hidden');
        }, 3000);
    }

    // ─── Form Submit ────────────────────────────────────────
    document.getElementById('quoteForm').addEventListener('submit', function() {
        updateFormInput();
    });

    // Initialize line items
    renderLineItems();

    // Ensure maps are properly resized after all resources load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // Call initMaps explicitly if Google Maps loaded but callback didn't fire
            if (typeof google !== 'undefined' && google.maps && !mapsInited) {
                initMaps();
            }
            setTimeout(resizeMaps, 500);
        });
    } else {
        // Document already loaded, call initMaps if needed
        if (typeof google !== 'undefined' && google.maps && !territoryMapInstance) {
            initMaps();
        }
        setTimeout(resizeMaps, 500);
    }

    // ─── Measurement Auto-Fill + Service Picker ────────────────
    var wfPropertyMeasurements = null;

    function wfFetchMeasurements() {
        if (!propertyId) return;

        var panel = document.getElementById('wfMeasurementPanel');
        var content = document.getElementById('wfMeasurementSummary');
        var badge = document.getElementById('wfMeasurementBadge');
        var noMeasMsg = document.getElementById('wfNoMeasurementsMsg');
        var hasMeasContent = document.getElementById('wfHasMeasurementsContent');
        var pickerContainer = document.getElementById('wfServicePickerContainer');
        var addBtn = document.getElementById('wfAddSelectedServicesBtn');

        var groupLabels = <?php
            $gl = ['lawn_area' => 'Lawn & Garden', 'hard_surface' => 'Hard Surface', 'hedge_linear' => 'Hedge / Edge', 'other_area' => 'Other'];
            foreach ($wfMeasurementGroups as $g) { $gl[$g['group_key']] = $g['group_label']; }
            echo json_encode($gl);
        ?>;

        // Always show the panel
        panel.style.display = '';

        fetch('api/quote-autofill.php?action=get-measurements&property_id=' + propertyId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.has_data) {
                    wfPropertyMeasurements = data;
                    noMeasMsg.style.display = 'none';
                    hasMeasContent.style.display = '';

                    var html = '';
                    var totalAreas = 0;

                    // Build group unit map from rules data
                    var groupUnits = {};
                    if (data.rules) {
                        Object.keys(data.rules).forEach(function(gk) {
                            if (data.rules[gk].length > 0) groupUnits[gk] = data.rules[gk][0].unit;
                        });
                    }

                    Object.keys(data.measurements).forEach(function(key) {
                        var m = data.measurements[key];
                        totalAreas += m.count;
                        var label = groupLabels[key] || key;
                        var isLinear = (groupUnits[key] === 'linear_ft') || (m.linear_ft > 0 && m.sqft === 0);
                        var value = isLinear
                            ? m.linear_ft.toLocaleString() + ' lin ft'
                            : m.sqft.toLocaleString() + ' sq ft';
                        html += '<div class="mb-1"><strong>' + label + ':</strong> ' + value + ' <span class="text-muted">(' + m.names.join(', ') + ')</span></div>';
                    });

                    content.innerHTML = html;
                    badge.textContent = totalAreas + ' area' + (totalAreas !== 1 ? 's' : '');
                    badge.className = 'badge badge-info small';

                    // Build service picker per measurement group
                    wfBuildServicePicker(data.measurements, data.rules);
                } else {
                    // No measurements yet
                    wfPropertyMeasurements = null;
                    noMeasMsg.style.display = '';
                    hasMeasContent.style.display = 'none';
                    badge.textContent = 'Not measured';
                    badge.className = 'badge badge-warning small';
                    pickerContainer.innerHTML = '';
                    addBtn.style.display = 'none';
                }
            })
            .catch(function() {
                panel.style.display = 'none';
                wfPropertyMeasurements = null;
            });
    }

    function wfBuildServicePicker(measurements, rulesByGroup) {
        var container = document.getElementById('wfServicePickerContainer');
        var addBtn = document.getElementById('wfAddSelectedServicesBtn');
        container.innerHTML = '';

        var frequencyLabels = {
            'daily': 'Daily', '7_day': '7-day', '14_day': '14-day', '21_day': '21-day',
            'monthly': 'Monthly', 'seasonal': 'Seasonal', 'one_off': 'One-off'
        };

        var hasRules = false;

        Object.keys(measurements).forEach(function(groupKey) {
            var m = measurements[groupKey];
            var rules = rulesByGroup[groupKey];
            if (!rules || rules.length === 0) return;

            hasRules = true;
            var totalUnits = groupKey === 'hedge_linear' ? m.linear_ft : m.sqft;

            var groupDiv = document.createElement('div');
            groupDiv.className = 'mw-service-picker-group';

            var rulesHtml = '';
            rules.forEach(function(rule) {
                var price = wfCalculatePreviewPrice(rule, totalUnits);
                var freq = frequencyLabels[rule.default_frequency] || rule.default_frequency;
                var rateInfo = rule.pricing_model === 'flat'
                    ? 'Flat rate'
                    : '$' + parseFloat(rule.price_per_unit).toFixed(4) + '/' + (rule.unit || 'sqft');

                rulesHtml +=
                    '<label class="mw-service-option">' +
                        '<input type="checkbox" name="wf_service_rule" value="' + rule.id + '" data-group="' + groupKey + '">' +
                        '<div class="mw-service-option-details">' +
                            '<span class="mw-service-option-name">' + escapeHtml(rule.product_name) + '</span>' +
                            '<span class="mw-service-option-meta">' + freq + ' &middot; ' + rateInfo + '</span>' +
                        '</div>' +
                        '<span class="mw-service-option-price">' + formatCurrencyJS(price) + '</span>' +
                    '</label>';
            });

            groupDiv.innerHTML = rulesHtml;
            container.appendChild(groupDiv);
        });

        if (hasRules) {
            addBtn.style.display = '';
            container.addEventListener('change', wfUpdateAddButtonState);
            wfUpdateAddButtonState();
        } else {
            addBtn.style.display = 'none';
        }
    }

    function wfCalculatePreviewPrice(rule, totalUnits) {
        var model = rule.pricing_model;
        var perUnit = parseFloat(rule.price_per_unit) || 0;
        var minPrice = parseFloat(rule.minimum_price) || 0;
        var included = parseFloat(rule.included_units) || 0;
        var basePrice = parseFloat(rule.base_price) || 0;

        var price = 0;
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

    function wfUpdateAddButtonState() {
        var checked = document.querySelectorAll('#wfServicePickerContainer input[type="checkbox"]:checked');
        var btn = document.getElementById('wfAddSelectedServicesBtn');
        btn.disabled = checked.length === 0;
        btn.textContent = checked.length === 0
            ? 'Select services above'
            : 'Add ' + checked.length + ' Selected Service' + (checked.length !== 1 ? 's' : '');
    }

    function wfAddSelectedServices() {
        if (!propertyId) return;

        var checked = document.querySelectorAll('#wfServicePickerContainer input[type="checkbox"]:checked');
        if (checked.length === 0) {
            alert('Please select at least one service.');
            return;
        }

        var selectedRuleIds = [];
        checked.forEach(function(cb) { selectedRuleIds.push(parseInt(cb.value)); });

        var formData = new FormData();
        formData.append('action', 'auto-fill');
        formData.append('property_id', propertyId);
        formData.append('selected_rules', JSON.stringify(selectedRuleIds));

        fetch('api/quote-autofill.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.items.length > 0) {
                    data.items.forEach(function(item) {
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
                            fromMeasurement: true
                        });
                    });
                    renderLineItems();

                    // Uncheck all after adding
                    document.querySelectorAll('#wfServicePickerContainer input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
                    wfUpdateAddButtonState();

                    showToast('Added ' + data.items.length + ' service(s) to quote');

                    if (data.warnings && data.warnings.length > 0) {
                        alert('Services added with warnings:\n' + data.warnings.join('\n'));
                    }
                } else if (data.warnings && data.warnings.length > 0) {
                    alert('Could not add services:\n' + data.warnings.join('\n'));
                } else {
                    alert('No pricing rules matched. Check product pricing configuration.');
                }
            })
            .catch(function(err) { alert('Error: ' + err.message); });
    }

    // Fetch measurements on page load
    wfFetchMeasurements();

    // ── Bundle Picker ─────────────────────────────────────────────────────────
    var bundleNames = {};

    async function openBundlePicker() {
        $('#addBundleModal').modal('show');
        var grid = document.getElementById('bundlePickerGrid');
        grid.innerHTML = '<p class="text-muted text-center py-3">Loading bundles…</p>';

        try {
            var res  = await fetch('products/api-products.php?action=get-bundles');
            var data = await res.json();

            if (!data.success || !data.bundles || data.bundles.length === 0) {
                grid.innerHTML = '<p class="text-muted text-center">No bundles available. Create one in <a href="products/bundles.php" target="_blank">Service Bundles</a>.</p>';
                return;
            }

            var hasProperty = !!propertyId;
            var html = '';

            if (!hasProperty) {
                html += '<div class="alert alert-info py-2 mb-3" style="font-size:13px;">'
                      + 'No property selected — prices shown are base rates.'
                      + '</div>';
            }

            data.bundles.forEach(function(b) {
                var tierColors = { good: '#16a34a', better: '#2563eb', best: '#9333ea', custom: '#64748b' };
                var tierColor  = tierColors[b.tier] || '#64748b';

                var listTotal = 0;
                var itemHtml  = '';
                if (b.items && b.items.length) {
                    b.items.forEach(function(it) {
                        var price = parseFloat(it.override_price || it.base_price || 0);
                        listTotal += price;
                        itemHtml += '<div class="mw-bp-item">'
                            + '<span>' + escapeHtml(it.product_name) + '</span>'
                            + '<span>' + formatCurrencyJS(price) + '</span>'
                            + '</div>';
                    });
                }

                var discountDisplay = '';
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
            if (typeof feather !== 'undefined') feather.replace();

            if (hasProperty) {
                data.bundles.forEach(function(b) {
                    previewBundlePrice(b.id, propertyId);
                });
            }

        } catch (err) {
            grid.innerHTML = '<p class="text-danger">Error loading bundles. Please try again.</p>';
            console.error('[Bundle Picker]', err);
        }
    }

    async function previewBundlePrice(bundleId, propId) {
        try {
            var params = new URLSearchParams({ action: 'preview-bundle', bundle_id: bundleId, property_id: propId });
            var res  = await fetch('api/quote-autofill.php', { method: 'POST', body: params });
            var data = await res.json();
            if (!data.success) return;

            var total = 0;
            data.items.forEach(function(it) { total += parseFloat(it.line_total || 0); });

            var btn = document.querySelector('#bundlePickerGrid button[data-bundle-id="' + bundleId + '"]');
            if (btn) btn.textContent = 'Add to Quote — ' + formatCurrencyJS(total);
        } catch (e) { /* silent */ }
    }

    async function addBundleToQuote(bundleId, bundleName) {
        var btn = document.querySelector('#bundlePickerGrid button[data-bundle-id="' + bundleId + '"], #bundlePickerGrid button[onclick*="addBundleToQuote(' + bundleId + '"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }

        try {
            var params = new URLSearchParams({ action: 'preview-bundle', bundle_id: bundleId, property_id: propertyId || 0 });
            var res  = await fetch('api/quote-autofill.php', { method: 'POST', body: params });
            var data = await res.json();

            if (!data.success) {
                alert('Could not load bundle: ' + (data.error || 'Unknown error'));
                if (btn) { btn.disabled = false; btn.textContent = 'Add to Quote'; }
                return;
            }

            bundleNames[bundleId] = bundleName;

            data.items.forEach(function(item) {
                lineItems.push({
                    id:                    ++itemIdCounter,
                    product_id:            item.product_id             || null,
                    pricing_rule_id:       item.pricing_rule_id        || null,
                    measurement_group_key: item.measurement_group_key  || null,
                    service_type:          item.service_type           || '',
                    description:           item.description            || '',
                    quantity:              parseFloat(item.quantity    || 1),
                    unit_type:             item.unit_type              || 'each',
                    unit_price:            parseFloat(item.unit_price  || 0),
                    line_total:            parseFloat(item.line_total  || 0),
                    is_optional:           item.is_optional            || false,
                    units_used:            item.units_used             || null,
                    price_per_unit:        item.price_per_unit         || null,
                    minimum_applied:       item.minimum_applied        || 0,
                    included_units:        item.included_units         || null,
                    pricing_snapshot:      item.pricing_snapshot       || null,
                    bundle_id:             parseInt(bundleId),
                    is_upsell:             item.is_upsell              || 0,
                    fromMeasurement:       true
                });
            });

            $('#addBundleModal').modal('hide');
            renderLineItems();

            if (data.warnings && data.warnings.length) {
                alert('Bundle added with warnings:\n' + data.warnings.join('\n'));
            }
        } catch (err) {
            alert('Error adding bundle: ' + err.message);
            console.error('[addBundleToQuote]', err);
            if (btn) { btn.disabled = false; btn.textContent = 'Add to Quote'; }
        }
    }
    </script>

<!-- Bundle Picker Modal -->
<div class="modal fade" id="addBundleModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i data-feather="package" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i>Add Service Bundle</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div id="bundlePickerGrid" class="mw-bp-grid">
          <p class="text-muted text-center py-3">Loading…</p>
        </div>
      </div>
      <div class="modal-footer py-2">
        <a href="products/bundles.php" target="_blank" class="mr-auto" style="font-size:13px;">
          Manage Bundles <i data-feather="external-link" style="width:11px;height:11px;vertical-align:middle;"></i>
        </a>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/appstack_footer.php'; ?>
