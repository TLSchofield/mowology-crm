<?php
/**
 * Create/Edit Quote
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();
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

// Get properties for dropdown
$properties = $db->query("
    SELECT DISTINCT p.id, p.address, p.city, p.property_type, c.company_name, c.id as company_id
    FROM properties p
    LEFT JOIN company_properties cp ON p.id = cp.property_id
    LEFT JOIN companies c ON cp.company_id = c.id
    ORDER BY c.company_name, p.address
")->fetchAll(PDO::FETCH_ASSOC);

// Get service templates
$templates = getServiceTemplates();

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
                        $description, $quoteId
                    ]);

                    // Delete old line items
                    $stmt = $db->prepare("DELETE FROM quote_line_items WHERE quote_id = ?");
                    $stmt->execute([$quoteId]);

                } else {
                    // Create new quote
                    $quoteNumber = generateQuoteNumber();
                    $accessToken = generateAccessToken();

                    $stmt = $db->prepare("
                        INSERT INTO quotes (
                            quote_number, property_id, title, service_type, amount,
                            subtotal, tax_rate, tax_amount, valid_until, terms,
                            notes_customer, notes_internal, description, access_token,
                            token_expires_at, created_by, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, 'draft')
                    ");
                    $stmt->execute([
                        $quoteNumber, $propertyId, $title, $serviceType, $totals['total'],
                        $totals['subtotal'], $totals['tax_rate'], $totals['tax_amount'],
                        $validUntil ?: null, $terms, $notesCustomer, $notesInternal,
                        $description, $accessToken, $user['id']
                    ]);
                    $quoteId = $db->lastInsertId();

                    // If created from a quote request, link them together
                    if ($quoteRequestId) {
                        $stmt = $db->prepare("UPDATE quote_requests SET quote_id = ?, status = 'quoted' WHERE id = ?");
                        $stmt->execute([$quoteId, $quoteRequestId]);
                    }

                    logActivityExtended($user['id'], 'Quote created', "Quote {$quoteNumber} created", null, null, $quoteId);
                }

                // Insert line items
                $stmt = $db->prepare("
                    INSERT INTO quote_line_items (
                        quote_id, service_type, description, quantity, unit_type,
                        unit_price, line_total, sort_order, is_optional
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($newLineItems as $index => $item) {
                    $stmt->execute([
                        $quoteId,
                        $item['service_type'] ?? 'Service',
                        $item['description'] ?? '',
                        floatval($item['quantity'] ?? 1),
                        $item['unit_type'] ?? 'each',
                        floatval($item['unit_price'] ?? 0),
                        floatval($item['line_total'] ?? 0),
                        $index,
                        $item['is_optional'] ?? false
                    ]);
                }

                $db->commit();

                header("Location: view.php?id={$quoteId}&saved=1");
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                error_log("Quote save error: " . $e->getMessage());
                $error = 'Error saving quote. Please try again.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle = $quoteId ? 'Edit Quote' : ($quoteRequestId ? 'Create Quote from Request' : 'Create Quote');
$activePage = 'quotes';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

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
                        <!-- Property Selection -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Customer &amp; Property</h5>
                            </div>
                            <div class="card-body">
                                <div class="mw-form-group">
                                    <label class="form-label">Select Property *</label>
                                    <select name="property_id" id="propertySelect" class="form-control" required>
                                        <option value="">Choose a property...</option>
                                        <?php foreach ($properties as $prop): ?>
                                            <option value="<?php echo $prop['id']; ?>"
                                                <?php echo (($quote && $quote['property_id'] == $prop['id']) || ($prefilledPropertyId && $prefilledPropertyId == $prop['id'])) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($prop['company_name'] . ' - ' . $prop['address'] . ', ' . $prop['city']); ?>
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

                                <div class="mw-form-group mb-0">
                                    <label class="form-label">Service Type</label>
                                    <select name="service_type" class="form-control">
                                        <option value="landscaping" <?php echo ($quote['service_type'] ?? '') === 'landscaping' ? 'selected' : ''; ?>>Landscaping</option>
                                        <option value="lawn_care" <?php echo ($quote['service_type'] ?? '') === 'lawn_care' ? 'selected' : ''; ?>>Lawn Care</option>
                                        <option value="snow_removal" <?php echo ($quote['service_type'] ?? '') === 'snow_removal' ? 'selected' : ''; ?>>Snow Removal</option>
                                        <option value="garden_maintenance" <?php echo ($quote['service_type'] ?? '') === 'garden_maintenance' ? 'selected' : ''; ?>>Garden Maintenance</option>
                                        <option value="seasonal_cleanup" <?php echo ($quote['service_type'] ?? '') === 'seasonal_cleanup' ? 'selected' : ''; ?>>Seasonal Cleanup</option>
                                    </select>
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

                                <div class="d-flex mt-3" style="gap: 12px;">
                                    <div class="mw-template-dropdown">
                                        <button type="button" class="btn btn-secondary" id="addFromTemplateBtn">
                                            + Add from Template
                                        </button>
                                        <div class="mw-template-menu" id="templateMenu">
                                            <?php foreach ($templates as $template): ?>
                                                <div class="mw-template-item" data-template='<?php echo json_encode($template); ?>'>
                                                    <div class="mw-template-name"><?php echo htmlspecialchars($template['name']); ?></div>
                                                    <div class="mw-template-price"><?php echo formatCurrency($template['default_price']); ?> / <?php echo htmlspecialchars($template['unit_type']); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary" id="addCustomLineBtn">
                                        + Add Custom Line
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
                                    <button type="submit" name="action" value="save" class="btn btn-primary" style="flex: 1;">
                                        Save Quote
                                    </button>
                                </div>

                                <p class="mt-3 mb-0 text-muted" style="font-size: 13px;">
                                    After saving, you can send this quote to the customer for approval.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

    <script>
        // Line items management
        let lineItems = <?php echo json_encode($lineItems); ?> || [];
        let itemIdCounter = lineItems.length;

        const container = document.getElementById('lineItemsContainer');
        const templates = <?php echo json_encode($templates); ?>;

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

            lineItems.forEach((item, index) => {
                const row = document.createElement('div');
                row.className = 'mw-line-item';
                row.dataset.index = index;

                row.innerHTML = `
                    <input type="text" value="${item.service_type || ''}" placeholder="Service name"
                           onchange="updateLineItem(${index}, 'service_type', this.value)">
                    <input type="text" value="${item.description || ''}" placeholder="Description"
                           onchange="updateLineItem(${index}, 'description', this.value)">
                    <input type="number" value="${item.quantity || 1}" min="0" step="any"
                           onchange="updateLineItem(${index}, 'quantity', this.value); recalculateLineTotal(${index})">
                    <input type="number" value="${item.unit_price || 0}" min="0" step="any"
                           onchange="updateLineItem(${index}, 'unit_price', this.value); recalculateLineTotal(${index})">
                    <div class="mw-line-total">${formatCurrency(item.line_total || 0)}</div>
                    <button type="button" class="mw-remove-btn" onclick="removeLine(${index})">&times;</button>
                `;

                container.appendChild(row);
            });

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
            const newItem = {
                id: ++itemIdCounter,
                service_type: templateData?.name || '',
                description: templateData?.description || '',
                quantity: 1,
                unit_type: templateData?.unit_type || 'each',
                unit_price: templateData?.default_price || 0,
                line_total: templateData?.default_price || 0,
                is_optional: false
            };

            lineItems.push(newItem);
            renderLineItems();
        }

        function updateFormInput() {
            document.getElementById('lineItemsInput').value = JSON.stringify(lineItems);
        }

        // Template dropdown
        const templateBtn = document.getElementById('addFromTemplateBtn');
        const templateMenu = document.getElementById('templateMenu');

        templateBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            templateMenu.classList.toggle('show');
        });

        document.querySelectorAll('.mw-template-item').forEach(item => {
            item.addEventListener('click', () => {
                const template = JSON.parse(item.dataset.template);
                addLine(template);
                templateMenu.classList.remove('show');
            });
        });

        document.addEventListener('click', () => {
            templateMenu.classList.remove('show');
        });

        document.getElementById('addCustomLineBtn').addEventListener('click', () => addLine());

        // Property selection summary
        const propertySelect = document.getElementById('propertySelect');
        const propertySummary = document.getElementById('propertySummary');
        const properties = <?php echo json_encode($properties); ?>;

        propertySelect.addEventListener('change', function() {
            const selected = properties.find(p => p.id == this.value);
            if (selected) {
                propertySummary.innerHTML = `
                    <strong>${selected.company_name}</strong><br>
                    ${selected.address}<br>
                    ${selected.city}<br>
                    <span style="opacity: 0.7">${selected.property_type}</span>
                `;
            } else {
                propertySummary.textContent = 'Select a property to see details';
            }
        });

        // Initialize
        if (lineItems.length === 0) {
            // Add empty line for new quotes
        }
        renderLineItems();
        propertySelect.dispatchEvent(new Event('change'));

        // Form submission
        document.getElementById('quoteForm').addEventListener('submit', function() {
            updateFormInput();
        });
    </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
