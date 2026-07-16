<?php
/**
 * Property Detail / Edit Page
 * ───────────────────────────
 * Single page that covers both viewing and editing a property.
 *
 * Before this file existed, properties could only be edited inline on
 * the client detail page (clients_appstack.php?action=view_contact),
 * which meant the company Properties tab had nowhere to link. This
 * page is the canonical destination.
 *
 * URL: /crm/properties/view.php?id=<int>
 *
 * GET  — render the form pre-filled with the current row
 * POST — validate, save, redirect back with a flash
 *
 * Gated by clients.view (read) and clients.edit or billing.edit (write).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$canEdit = userHasPermission('clients.edit') || userHasPermission('billing.edit');

$db        = getDB();
$propertyId = (int)($_GET['id'] ?? 0);
$error     = '';
$returnTo  = isset($_GET['return_to']) ? (string)$_GET['return_to'] : '';

if ($propertyId < 1) {
    header('Location: /crm/clients_appstack.php');
    exit;
}

// ── Load property ────────────────────────────────────────────────────────────
// SELECT * handles schema drift — memory note says production may have
// extra columns (total_lawn_sqft etc.) not in the base migration.
$stmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
$stmt->execute([$propertyId]);
$property = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$property) {
    header('Location: /crm/clients_appstack.php');
    exit;
}

// Does this build have billing_entity_name? Migration 1011 adds it.
$hasBillingEntity   = array_key_exists('billing_entity_name',   $property);
$hasPropertyManager = array_key_exists('property_manager_id',   $property);

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        $error = 'You do not have permission to edit properties.';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $propertyName = trim($_POST['property_name'] ?? '');
        $propertyType = trim($_POST['property_type'] ?? 'single_family');
        $address      = trim($_POST['address']       ?? '');
        $city         = trim($_POST['city']          ?? '');
        $province     = trim($_POST['province']      ?? '');
        $postalCode   = trim($_POST['postal_code']   ?? '');
        $siteContactId     = (int)($_POST['site_contact_id']     ?? 0) ?: null;
        $propertyManagerId = (int)($_POST['property_manager_id'] ?? 0) ?: null;
        $lotSize      = $_POST['lot_size_sqft']  !== '' ? (float)$_POST['lot_size_sqft']  : null;
        $lawnSize     = $_POST['lawn_size_sqft'] !== '' ? (float)$_POST['lawn_size_sqft'] : null;
        $notes        = trim($_POST['notes']     ?? '');
        $status       = trim($_POST['status']    ?? 'active');
        $billingEntityName = trim($_POST['billing_entity_name'] ?? '');
        if ($billingEntityName === '') $billingEntityName = null;

        // Standardize on the clients spine: derive the canonical client_id from
        // the chosen PM company (its backfilled client via legacy_company_id), so
        // the property stores the account link the invoice billing flow reads.
        // Only set it when we resolve one — never wipe an existing client_id.
        $hasClientId = array_key_exists('client_id', $property);
        $derivedClientId = null;
        if ($hasClientId && $propertyManagerId) {
            try {
                $cs = $db->prepare("SELECT id FROM clients WHERE legacy_company_id = ? ORDER BY id LIMIT 1");
                $cs->execute([$propertyManagerId]);
                $derivedClientId = (int)$cs->fetchColumn() ?: null;
            } catch (PDOException $e) {
                $derivedClientId = null;
            }
        }

        $allowedTypes  = ['single_family','townhouse','condo','commercial','strata','multi_unit'];
        if (!in_array($propertyType, $allowedTypes, true)) $propertyType = 'single_family';
        $allowedStatus = ['active','inactive','archived'];
        if (!in_array($status, $allowedStatus, true)) $status = 'active';

        if ($address === '') {
            $error = 'Address is required.';
        } else {
            try {
                // Build the UPDATE SET clause dynamically so schema drift
                // (billing_entity_name, total_lawn_sqft etc.) is tolerated.
                $set = [
                    'property_name = ?',
                    'property_type = ?',
                    'address       = ?',
                    'city          = ?',
                    'province      = ?',
                    'postal_code   = ?',
                    'site_contact_id = ?',
                    'lot_size_sqft = ?',
                    'lawn_size_sqft= ?',
                    'notes         = ?',
                    'status        = ?',
                ];
                $params = [
                    $propertyName !== '' ? $propertyName : null,
                    $propertyType,
                    $address,
                    $city,
                    $province,
                    $postalCode,
                    $siteContactId,
                    $lotSize,
                    $lawnSize,
                    $notes,
                    $status,
                ];
                if ($hasBillingEntity) {
                    $set[]    = 'billing_entity_name = ?';
                    $params[] = $billingEntityName;
                }
                if ($hasPropertyManager) {
                    $set[]    = 'property_manager_id = ?';
                    $params[] = $propertyManagerId;
                }
                // Canonical account link (clients spine). Only when resolved, so
                // a manually-set client_id (e.g. retrofitted) is never wiped.
                if ($hasClientId && $derivedClientId !== null) {
                    $set[]    = 'client_id = ?';
                    $params[] = $derivedClientId;
                }
                $params[] = $propertyId;
                $sql = "UPDATE properties SET " . implode(', ', $set) . " WHERE id = ?";
                $db->prepare($sql)->execute($params);

                try {
                    logActivityExtended(
                        $user['id'],
                        'Property updated',
                        'Property #' . $propertyId . ' (' . ($propertyName ?: $address) . ') updated',
                        null, null, null, null, null, null
                    );
                } catch (Throwable $e) { /* non-critical */ }

                header('Location: view.php?id=' . $propertyId . '&updated=1' . ($returnTo ? '&return_to=' . urlencode($returnTo) : ''));
                exit;
            } catch (Throwable $e) {
                error_log('Property edit error: ' . $e->getMessage());
                $error = 'Error saving property. Please try again.';
            }
        }
    }
}

// Site contact list for the dropdown
try {
    $contactsList = $db->query("
        SELECT id, first_name, last_name, email
        FROM contacts
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY last_name, first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $contactsList = [];
}

// Companies list for property manager dropdown
try {
    $companiesList = $db->query("
        SELECT id, company_name, company_type
        FROM companies
        WHERE account_status = 'active'
        ORDER BY company_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $companiesList = [];
}

// Resolve the current site contact's display name (for the breadcrumb)
$siteContactName = '';
if (!empty($property['site_contact_id'])) {
    $cStmt = $db->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, email FROM contacts WHERE id = ?");
    $cStmt->execute([(int)$property['site_contact_id']]);
    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    $siteContactName = $cRow['name'] ?? '';
}

// Inferred company (via site_contact → companies primary/billing)
$inferredCompany = null;
if (!empty($property['site_contact_id'])) {
    try {
        $cmp = $db->prepare("
            SELECT id, company_name
            FROM companies
            WHERE primary_contact_id = ? OR billing_contact_id = ?
            LIMIT 1
        ");
        $cmp->execute([(int)$property['site_contact_id'], (int)$property['site_contact_id']]);
        $inferredCompany = $cmp->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $inferredCompany = null; }
}

$csrfToken  = generateCSRFToken();
$justSaved  = isset($_GET['updated']);

$pageTitle  = $property['property_name'] ?: $property['address'];
$activePage = 'clients';
$apiKey     = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$extraHead  = '';
if ($apiKey) {
    $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=places&loading=async" defer></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <?php if ($justSaved): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Property saved.
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent p-0 mb-2">
                    <?php if ($returnTo !== ''): ?>
                        <li class="breadcrumb-item"><a href="<?= htmlspecialchars($returnTo) ?>">← Back</a></li>
                    <?php elseif ($inferredCompany): ?>
                        <li class="breadcrumb-item"><a href="/crm/companies/view.php?id=<?= (int)$inferredCompany['id'] ?>">← <?= htmlspecialchars($inferredCompany['company_name']) ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="/crm/clients_appstack.php">← Clients</a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active">Property</li>
                </ol>
            </nav>

            <h1 class="h3 mb-1"><?= htmlspecialchars($property['property_name'] ?: $property['address']) ?></h1>
            <p class="text-muted mb-4">
                <?= htmlspecialchars($property['address']) ?>
                <?php if (!empty($property['city'])): ?> &bull; <?= htmlspecialchars($property['city']) ?><?php endif; ?>
                <?php if ($siteContactName): ?> &bull; Property manager: <?= htmlspecialchars($siteContactName) ?><?php endif; ?>
                <?php if ($inferredCompany): ?>
                    &bull; Managed by <a href="/crm/companies/view.php?id=<?= (int)$inferredCompany['id'] ?>"><?= htmlspecialchars($inferredCompany['company_name']) ?></a>
                <?php endif; ?>
            </p>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Property Details</h3>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="form-label">Property Name <span class="text-muted" style="font-weight:400;">(friendly label)</span></label>
                                <input type="text" name="property_name" class="form-control" placeholder='e.g. "Smith Residence"' value="<?= htmlspecialchars($property['property_name'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="form-label">
                                    Billing Entity Name
                                    <span class="text-muted" style="font-weight:400;">(legal / strata name for contract invoices)</span>
                                </label>
                                <input type="text" name="billing_entity_name" class="form-control" placeholder="e.g. VR14-50"
                                       value="<?= htmlspecialchars($property['billing_entity_name'] ?? '') ?>"
                                       <?= ($canEdit && $hasBillingEntity) ? '' : 'readonly' ?>>
                                <small class="text-muted">
                                    The contract billing cron uses this as
                                    <code>{entity} C/O {company}</code> on the Bill To line
                                    (e.g. <code>VR14-50 C/O MACDONALD REALTY</code>).
                                    <?php if (!$hasBillingEntity): ?>
                                    <br><strong>Not available — run migration 1011 first.</strong>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label class="form-label">Type</label>
                                <select name="property_type" class="form-control" <?= $canEdit ? '' : 'disabled' ?>>
                                    <?php
                                        $types = [
                                            'single_family' => 'Single family',
                                            'townhouse'     => 'Townhouse',
                                            'condo'         => 'Condo',
                                            'commercial'    => 'Commercial',
                                            'strata'        => 'Strata',
                                            'multi_unit'    => 'Multi-unit',
                                        ];
                                        foreach ($types as $k => $label) {
                                            $sel = ($property['property_type'] ?? '') === $k ? ' selected' : '';
                                            echo '<option value="' . $k . '"' . $sel . '>' . $label . '</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="form-label">Property Manager <span class="text-muted" style="font-weight:400;">(person / on-site contact)</span></label>
                                <select name="site_contact_id" id="siteContactPicker" class="form-control mw-searchable" data-placeholder="Search contacts…" data-none-label="No contact" <?= $canEdit ? '' : 'disabled' ?>>
                                    <option value="">— none —</option>
                                    <?php foreach ($contactsList as $c):
                                        $label = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                                        if (!empty($c['email'])) $label .= ' · ' . $c['email'];
                                        $sel = (int)($property['site_contact_id'] ?? 0) === (int)$c['id'] ? ' selected' : '';
                                    ?>
                                    <option value="<?= (int)$c['id'] ?>"<?= $sel ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Drives the company auto-link on company pages.</small>
                            </div>
                            <?php if ($hasPropertyManager): ?>
                            <div class="form-group col-md-4">
                                <label class="form-label">Management Company <span class="text-muted" style="font-weight:400;">(strata / property management firm)</span></label>
                                <select name="property_manager_id" class="form-control mw-searchable" data-placeholder="Search companies…" data-none-label="No management company" <?= $canEdit ? '' : 'disabled' ?>>
                                    <option value="">— none —</option>
                                    <?php foreach ($companiesList as $co):
                                        $sel = (int)($property['property_manager_id'] ?? 0) === (int)$co['id'] ? ' selected' : '';
                                    ?>
                                    <option value="<?= (int)$co['id'] ?>"<?= $sel ?>><?= htmlspecialchars($co['company_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Strata manager or property management company.</small>
                            </div>
                            <?php endif; ?>
                            <div class="form-group col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control" <?= $canEdit ? '' : 'disabled' ?>>
                                    <?php
                                        $statuses = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
                                        foreach ($statuses as $k => $label) {
                                            $sel = ($property['status'] ?? 'active') === $k ? ' selected' : '';
                                            echo '<option value="' . $k . '"' . $sel . '>' . $label . '</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Address</h3>

                        <div class="form-group">
                            <label class="form-label">Street Address *</label>
                            <input type="text" name="address" id="propertyAddress" class="form-control" required
                                   placeholder="Start typing an address…" autocomplete="off"
                                   value="<?= htmlspecialchars($property['address'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-5">
                                <label class="form-label">City</label>
                                <input type="text" name="city" id="propertyCity" class="form-control" value="<?= htmlspecialchars($property['city'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="form-label">Province</label>
                                <select name="province" id="propertyProvince" class="form-control" <?= $canEdit ? '' : 'disabled' ?>>
                                    <?php
                                        $provinces = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];
                                        foreach ($provinces as $pp) {
                                            $sel = ($property['province'] ?? '') === $pp ? ' selected' : '';
                                            echo '<option value="' . $pp . '"' . $sel . '>' . $pp . '</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="form-label">Postal Code</label>
                                <input type="text" name="postal_code" id="propertyPostalCode" class="form-control" value="<?= htmlspecialchars($property['postal_code'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Measurements & Notes</h3>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="form-label">Lot Size (sqft)</label>
                                <input type="number" step="0.01" min="0" name="lot_size_sqft" class="form-control" value="<?= htmlspecialchars((string)($property['lot_size_sqft'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="form-label">Lawn Size (sqft)</label>
                                <input type="number" step="0.01" min="0" name="lawn_size_sqft" class="form-control" value="<?= htmlspecialchars((string)($property['lawn_size_sqft'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                                <small class="text-muted">Prefer measured totals from the Zone Editor for accuracy.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="4" <?= $canEdit ? '' : 'readonly' ?>><?= htmlspecialchars($property['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <?php if ($canEdit): ?>
                <div class="mw-form-actions mb-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <?php if ($returnTo !== ''): ?>
                        <a href="<?= htmlspecialchars($returnTo) ?>" class="btn btn-secondary">Cancel</a>
                    <?php elseif ($inferredCompany): ?>
                        <a href="/crm/companies/view.php?id=<?= (int)$inferredCompany['id'] ?>" class="btn btn-secondary">Cancel</a>
                    <?php else: ?>
                        <a href="/crm/clients_appstack.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                    <a href="/crm/jobs/zone-editor.php?property_id=<?= (int)$propertyId ?>&return_to=<?= urlencode('/crm/properties/view.php?id=' . $propertyId) ?>" class="btn btn-outline-secondary ml-2">
                        Zone Editor (measurements)
                    </a>
                </div>
                <?php endif; ?>
            </form>

<?php if ($canEdit && $apiKey): ?>
<script>
// Google Places autocomplete on the address input, same pattern as
// clients_appstack.php and companies/create.php. Restricted to Canadian
// addresses and auto-splits the postal code / city / province.
(function () {
    'use strict';
    function init() {
        if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
            return setTimeout(init, 400);
        }
        var input = document.getElementById('propertyAddress');
        if (!input) return;
        var ac = new google.maps.places.Autocomplete(input, {
            componentRestrictions: { country: ['ca'] },
            fields: ['address_components', 'formatted_address'],
            types: ['address']
        });
        ac.addListener('place_changed', function () {
            var place = ac.getPlace();
            if (!place.address_components) return;
            var parts = { street_number: '', route: '', locality: '', sublocality_level_1: '', administrative_area_level_1: '', postal_code: '' };
            place.address_components.forEach(function (c) {
                c.types.forEach(function (t) { if (parts.hasOwnProperty(t)) parts[t] = c.short_name; });
            });
            input.value = (parts.street_number + ' ' + parts.route).trim() || place.formatted_address;
            document.getElementById('propertyCity').value       = parts.locality || parts.sublocality_level_1 || '';
            document.getElementById('propertyProvince').value   = parts.administrative_area_level_1 || '';
            document.getElementById('propertyPostalCode').value = parts.postal_code || '';
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
</script>
<?php endif; ?>


<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
