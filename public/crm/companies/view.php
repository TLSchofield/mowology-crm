<?php
/**
 * Companies - View Company Profile
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$db = getDB();

$companyId = (int)($_GET['id'] ?? 0);
if (!$companyId) {
    header('Location: index.php');
    exit;
}

$company = getCompanyById($companyId);
if (!$company) {
    header('Location: index.php');
    exit;
}

// Flash messages
$justCreated = isset($_GET['created']);
$justUpdated = isset($_GET['updated']);
$linkFlash = $_GET['linked'] ?? ($_GET['unlinked'] ?? '');
$linkError = '';

// ── Property link/unlink POST handler ────────────────────────────────────────
// Adds a row to company_properties (link) or removes one (unlink). Both
// actions require the CSRF token and billing.edit so only admins/office
// can change the billing hierarchy.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['link_property', 'unlink_property'], true)) {
        if (!userHasPermission('billing.edit')) {
            $linkError = 'You need the billing.edit permission to change company properties.';
        } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $linkError = 'Your session expired. Please reload and try again.';
        } elseif ($action === 'link_property') {
            $propId = (int)($_POST['property_id'] ?? 0);
            $rel    = trim($_POST['relationship_type'] ?? 'owner');
            $isPri  = !empty($_POST['is_primary']) ? 1 : 0;
            $allowedRel = ['owner', 'manager', 'billing', 'tenant'];
            if (!in_array($rel, $allowedRel, true)) $rel = 'owner';
            if ($propId > 0) {
                try {
                    // Idempotent — do nothing if already linked
                    $exists = $db->prepare("SELECT id FROM company_properties WHERE company_id = ? AND property_id = ?");
                    $exists->execute([$companyId, $propId]);
                    if (!$exists->fetchColumn()) {
                        $db->prepare("
                            INSERT INTO company_properties (company_id, property_id, relationship_type, is_primary)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$companyId, $propId, $rel, $isPri]);
                    }
                    header('Location: view.php?id=' . $companyId . '&linked=1#properties');
                    exit;
                } catch (Throwable $e) {
                    $linkError = 'Could not link property: ' . $e->getMessage();
                }
            } else {
                $linkError = 'Please pick a property to link.';
            }
        } elseif ($action === 'unlink_property') {
            $propId = (int)($_POST['property_id'] ?? 0);
            if ($propId > 0) {
                try {
                    $db->prepare("DELETE FROM company_properties WHERE company_id = ? AND property_id = ?")
                       ->execute([$companyId, $propId]);
                    header('Location: view.php?id=' . $companyId . '&unlinked=1#properties');
                    exit;
                } catch (Throwable $e) {
                    $linkError = 'Could not unlink property: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get related data
$companyContacts   = getCompanyContacts($companyId);
$companyProperties = getCompanyProperties($companyId);

// Unlinked properties (for the "+ Link Property" picker).
// Show every active property that isn't already attached to this company.
$linkedIds = array_map(fn($p) => (int)$p['id'], $companyProperties);
$unlinkedProps = [];
try {
    if (empty($linkedIds)) {
        $unlinkedStmt = $db->query("
            SELECT id, property_name, address, city, province, postal_code
            FROM properties
            WHERE COALESCE(status, 'active') = 'active'
            ORDER BY address ASC
            LIMIT 500
        ");
        $unlinkedProps = $unlinkedStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ph = implode(',', array_fill(0, count($linkedIds), '?'));
        $unlinkedStmt = $db->prepare("
            SELECT id, property_name, address, city, province, postal_code
            FROM properties
            WHERE COALESCE(status, 'active') = 'active'
              AND id NOT IN ({$ph})
            ORDER BY address ASC
            LIMIT 500
        ");
        $unlinkedStmt->execute($linkedIds);
        $unlinkedProps = $unlinkedStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $unlinkedProps = [];
}

$csrfToken = generateCSRFToken();

// Quotes
$quotesStmt = $db->prepare("
    SELECT q.id, q.quote_number, q.status, q.subtotal, q.total_amount, q.created_at
    FROM quotes q
    WHERE q.company_id = ?
    ORDER BY q.created_at DESC
    LIMIT 50
");
$quotesStmt->execute([$companyId]);
$quotes = $quotesStmt->fetchAll(PDO::FETCH_ASSOC);

// Job Plans
$jobsStmt = $db->prepare("
    SELECT jp.id, jp.plan_number, jp.title, jp.status, jp.recurrence_pattern, jp.created_at
    FROM job_plans jp
    WHERE jp.company_id = ?
    ORDER BY jp.created_at DESC
    LIMIT 50
");
$jobsStmt->execute([$companyId]);
$jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

// Invoices
$invoicesStmt = $db->prepare("
    SELECT i.*
    FROM invoices i
    WHERE i.company_id = ?
    ORDER BY i.created_at DESC
    LIMIT 50
");
$invoicesStmt->execute([$companyId]);
$invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

$outstandingBalance = 0;
foreach ($invoices as $inv) {
    if (in_array($inv['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
        $outstandingBalance += floatval($inv['balance_due'] ?? 0);
    }
}

// Activity log
$activityStmt = $db->prepare("
    SELECT a.*, u.full_name as user_name
    FROM activity_log a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.company_id = ?
    ORDER BY a.created_at DESC
    LIMIT 50
");
$activityStmt->execute([$companyId]);
$activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

// Badge helpers
$statusColors = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'warning'];
$statusColor = $statusColors[$company['account_status']] ?? 'secondary';

$typeLabels = ['individual' => 'Individual', 'business' => 'Business', 'strata' => 'Strata', 'property_manager' => 'Property Manager'];

$pageTitle = htmlspecialchars($company['company_name']);
$activePage = 'companies';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <?php if ($justCreated): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Company created successfully.
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
            <?php elseif ($justUpdated): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Company updated successfully.
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-2 bg-transparent p-0">
                            <li class="breadcrumb-item"><a href="index.php">Companies</a></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($company['company_name']) ?></li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-1"><?= htmlspecialchars($company['company_name']) ?></h1>
                    <div class="mt-1">
                        <span class="mw-company-type-badge <?= htmlspecialchars($company['company_type'] ?? 'individual') ?>">
                            <?= htmlspecialchars($typeLabels[$company['company_type']] ?? 'Individual') ?>
                        </span>
                        <span class="badge badge-<?= $statusColor ?> ml-1">
                            <?= htmlspecialchars(ucfirst($company['account_status'])) ?>
                        </span>
                        <span class="badge badge-outline-secondary ml-1">
                            <?= htmlspecialchars(ucfirst($company['lifecycle_stage'] ?? 'prospect')) ?>
                        </span>
                    </div>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="edit.php?id=<?= $companyId ?>" class="btn btn-outline-primary mr-1">
                        <i data-feather="edit-2" class="align-middle mr-1" style="width:14px;height:14px;"></i> Edit
                    </a>
                    <?php if ($company['account_status'] === 'active'): ?>
                        <button class="btn btn-outline-warning" onclick="archiveCompany(<?= $companyId ?>)">
                            <i data-feather="archive" class="align-middle mr-1" style="width:14px;height:14px;"></i> Archive
                        </button>
                    <?php elseif ($company['account_status'] === 'inactive'): ?>
                        <button class="btn btn-outline-success" onclick="restoreCompany(<?= $companyId ?>)">
                            <i data-feather="refresh-cw" class="align-middle mr-1" style="width:14px;height:14px;"></i> Restore
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-outline-danger ml-1" onclick="deleteCompany(<?= $companyId ?>, '<?= htmlspecialchars(addslashes($company['company_name'])) ?>')">
                        <i data-feather="trash-2" class="align-middle mr-1" style="width:14px;height:14px;"></i> Delete
                    </button>
                </div>
            </div>

            <!-- Section Jump Nav — sticky at top while scrolling. Replaces
                 the old Bootstrap nav-tabs with plain anchor links so every
                 section renders inline on the page. -->
            <div class="mw-company-jumpnav mb-4" style="position:sticky; top:0; z-index:10; background:#fff; padding:12px 0; border-bottom:1px solid #e5e7eb; display:flex; flex-wrap:wrap; gap:8px;">
                <a href="#overview"   class="btn btn-sm btn-outline-secondary">Overview</a>
                <a href="#contacts"   class="btn btn-sm btn-outline-secondary">Contacts <span class="badge badge-light ml-1"><?= count($companyContacts) ?></span></a>
                <a href="#properties" class="btn btn-sm btn-outline-secondary">Properties <span class="badge badge-light ml-1"><?= count($companyProperties) ?></span></a>
                <a href="#quotes"     class="btn btn-sm btn-outline-secondary">Quotes <span class="badge badge-light ml-1"><?= count($quotes) ?></span></a>
                <a href="#jobs"       class="btn btn-sm btn-outline-secondary">Jobs <span class="badge badge-light ml-1"><?= count($jobs) ?></span></a>
                <a href="#invoices"   class="btn btn-sm btn-outline-secondary">Invoices <span class="badge badge-light ml-1"><?= count($invoices) ?></span></a>
                <a href="#activity"   class="btn btn-sm btn-outline-secondary">Activity</a>
            </div>

            <style>
                /* Section wrapper — gives each block enough scroll margin
                   so sticky-nav anchor jumps don't hide the section title
                   behind the jump bar. */
                .mw-company-section { scroll-margin-top: 84px; margin-bottom: 32px; }
                .mw-company-section-title {
                    font-size: 13px;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.8px;
                    color: var(--mw-green, #2D8659);
                    margin-bottom: 12px;
                    padding-bottom: 8px;
                    border-bottom: 2px solid var(--mw-light, #E8F3F0);
                }
                .mw-company-section-title .count {
                    color: #9ca3af;
                    font-weight: 500;
                    margin-left: 6px;
                }
            </style>

            <div id="companyContent">

                <!-- Overview Section -->
                <section class="mw-company-section" id="overview">
                    <div class="mw-company-section-title">Overview</div>
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card mb-4">
                                <div class="card-header"><h5 class="card-title mb-0">Company Details</h5></div>
                                <div class="card-body p-0">
                                    <table class="table table-sm mb-0">
                                        <tr><td class="font-weight-bold text-muted" style="width:40%;">Name</td><td><?= htmlspecialchars($company['company_name']) ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Type</td><td><?= htmlspecialchars($typeLabels[$company['company_type']] ?? 'Individual') ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Status</td><td><span class="badge badge-<?= $statusColor ?>"><?= htmlspecialchars(ucfirst($company['account_status'])) ?></span></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Lifecycle Stage</td><td><?= htmlspecialchars(ucfirst($company['lifecycle_stage'] ?? 'prospect')) ?></td></tr>
                                        <tr>
                                            <td class="font-weight-bold text-muted">Primary Contact</td>
                                            <td>
                                                <?php if ($company['primary_first_name']): ?>
                                                    <a href="/crm/clients_appstack.php?action=view_contact&id=<?= $company['primary_contact_id'] ?>">
                                                        <?= htmlspecialchars(trim($company['primary_first_name'] . ' ' . $company['primary_last_name'])) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="font-weight-bold text-muted">Billing Contact</td>
                                            <td>
                                                <?php if ($company['billing_first_name']): ?>
                                                    <a href="/crm/clients_appstack.php?action=view_contact&id=<?= $company['billing_contact_id'] ?>">
                                                        <?= htmlspecialchars(trim($company['billing_first_name'] . ' ' . $company['billing_last_name'])) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if ($company['notes']): ?>
                                            <tr><td class="font-weight-bold text-muted">Notes</td><td><?= nl2br(htmlspecialchars($company['notes'])) ?></td></tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card mb-4">
                                <div class="card-header"><h5 class="card-title mb-0">Billing & Payment</h5></div>
                                <div class="card-body p-0">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <td class="font-weight-bold text-muted" style="width:40%;">Billing Address</td>
                                            <td>
                                                <?php
                                                $addrParts = array_filter([
                                                    $company['billing_address'],
                                                    $company['billing_city'],
                                                    $company['billing_province'],
                                                    $company['billing_postal_code']
                                                ]);
                                                echo $addrParts ? htmlspecialchars(implode(', ', $addrParts)) : '<span class="text-muted">—</span>';
                                                ?>
                                            </td>
                                        </tr>
                                        <tr><td class="font-weight-bold text-muted">Billing Email</td><td><?= $company['billing_email'] ? htmlspecialchars($company['billing_email']) : '<span class="text-muted">—</span>' ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Billing Phone</td><td><?= $company['billing_phone'] ? htmlspecialchars($company['billing_phone']) : '<span class="text-muted">—</span>' ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Payment Terms</td><td><?= htmlspecialchars($company['payment_terms'] ?? 'Net 30') ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Payment Method</td><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $company['payment_method'] ?? 'invoice'))) ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted">Invoice Routing</td><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $company['invoice_routing_method'] ?? 'primary contact'))) ?></td></tr>
                                    </table>
                                </div>
                            </div>

                            <?php if ($outstandingBalance > 0): ?>
                                <div class="mw-outstanding-balance">
                                    <div class="small text-muted mb-1">Outstanding Balance</div>
                                    <div class="amount"><?= formatCurrency($outstandingBalance) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Contacts Section -->
                <section class="mw-company-section" id="contacts">
                    <div class="mw-company-section-title">Contacts <span class="count">(<?= count($companyContacts) ?>)</span></div>
                    <div class="card">
                        <div class="card-body">
                            <?php if (empty($companyContacts)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="users" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p>No contacts linked to this company.</p>
                                    <a href="edit.php?id=<?= $companyId ?>" class="btn btn-sm btn-outline-primary">Add a contact</a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($companyContacts as $ct): ?>
                                                <tr>
                                                    <td>
                                                        <a href="/crm/clients_appstack.php?action=view_contact&id=<?= $ct['id'] ?>">
                                                            <?= htmlspecialchars(trim($ct['first_name'] . ' ' . $ct['last_name'])) ?>
                                                        </a>
                                                    </td>
                                                    <td><?= htmlspecialchars($ct['email'] ?? '—') ?></td>
                                                    <td><?= htmlspecialchars($ct['phone'] ?? '—') ?></td>
                                                    <td>
                                                        <?php
                                                        $roles = [];
                                                        if ($ct['id'] == $company['primary_contact_id']) $roles[] = '<span class="badge badge-primary">Primary</span>';
                                                        if ($ct['id'] == $company['billing_contact_id']) $roles[] = '<span class="badge badge-info">Billing</span>';
                                                        echo $roles ? implode(' ', $roles) : '<span class="text-muted">—</span>';
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Properties Section -->
                <section class="mw-company-section" id="properties">
                    <div class="mw-company-section-title">Properties <span class="count">(<?= count($companyProperties) ?>)</span></div>
                    <div class="card">
                        <div class="card-body">
                            <?php if ($linkFlash === '1'): ?>
                                <div class="alert alert-success py-2 mb-3">Property link updated.</div>
                            <?php endif; ?>
                            <?php if ($linkError): ?>
                                <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($linkError) ?></div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Linked Properties <span class="text-muted">(<?= count($companyProperties) ?>)</span></h5>
                                <?php if (userHasPermission('billing.edit')): ?>
                                <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#linkPropertyModal">
                                    <i data-feather="plus" class="mr-1"></i> Link Property
                                </button>
                                <?php endif; ?>
                            </div>

                            <?php if (empty($companyProperties)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="map-pin" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p class="mb-1">No properties linked to this company yet.</p>
                                    <p class="mb-0" style="font-size:.85rem;">
                                        Properties appear here automatically when their on-site contact is this
                                        company's primary or billing contact. Use <strong>Link Property</strong>
                                        above only if you need to attach a property whose on-site contact is
                                        someone else.
                                    </p>
                                </div>
                            <?php else: ?>
                                <?php
                                    $inferredCount = 0;
                                    foreach ($companyProperties as $__p) {
                                        if (($__p['link_source'] ?? '') === 'inferred') $inferredCount++;
                                    }
                                ?>
                                <?php if ($inferredCount > 0): ?>
                                <p class="text-muted mb-3" style="font-size:.85rem;">
                                    <i data-feather="info" style="width:13px;height:13px;vertical-align:-2px;"></i>
                                    <strong><?= $inferredCount ?></strong> propert<?= $inferredCount === 1 ? 'y is' : 'ies are' ?>
                                    linked automatically because <?= $inferredCount === 1 ? 'its' : 'their' ?>
                                    on-site contact is this company's primary or billing contact.
                                    Rows marked <span class="badge badge-light">Inferred</span> don't have an
                                    explicit link — they'll disappear if you change the on-site contact.
                                </p>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Property</th>
                                                <th>City</th>
                                                <th>Relationship</th>
                                                <th>Primary</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($companyProperties as $prop):
                                                $isInferred = ($prop['link_source'] ?? '') === 'inferred';
                                                $propHref   = '/crm/properties/view.php?id=' . (int)$prop['id']
                                                            . '&return_to=' . urlencode('/crm/companies/view.php?id=' . $companyId . '#properties');
                                            ?>
                                                <tr class="mw-row-link" data-href="<?= htmlspecialchars($propHref) ?>" style="cursor:pointer;">
                                                    <td>
                                                        <a href="<?= htmlspecialchars($propHref) ?>" class="text-dark" style="text-decoration:none;">
                                                        <?php if (!empty($prop['property_name'])): ?>
                                                            <div style="font-weight:600;"><?= htmlspecialchars($prop['property_name']) ?></div>
                                                            <div class="text-muted" style="font-size:.85rem;"><?= htmlspecialchars($prop['address'] ?? '—') ?></div>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars($prop['address'] ?? '—') ?>
                                                        <?php endif; ?>
                                                        <?php if ($isInferred && !empty($prop['linked_via_name'])): ?>
                                                            <div class="text-muted" style="font-size:.78rem;margin-top:2px;">
                                                                <i data-feather="link" style="width:11px;height:11px;vertical-align:-1px;"></i>
                                                                Linked via <?= htmlspecialchars($prop['linked_via_name']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        </a>
                                                    </td>
                                                    <td><?= htmlspecialchars($prop['city'] ?? '—') ?></td>
                                                    <td>
                                                        <span class="badge badge-light">
                                                            <?= htmlspecialchars(ucfirst($prop['relationship_type'] ?? 'owner')) ?>
                                                        </span>
                                                        <?php if ($isInferred): ?>
                                                        <span class="badge badge-info" title="Auto-linked via contact chain — not an explicit junction row">Inferred</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= $prop['is_primary'] ? '<span class="text-success">Yes</span>' : '—' ?></td>
                                                    <td class="text-right">
                                                        <?php if (!$isInferred && userHasPermission('billing.edit')): ?>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Unlink this property from <?= htmlspecialchars(addslashes($company['company_name'])) ?>?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="unlink_property">
                                                            <input type="hidden" name="property_id" value="<?= (int)$prop['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Unlink">
                                                                <i data-feather="x" style="width:14px;height:14px;"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Link Property Modal -->
                <?php if (userHasPermission('billing.edit')): ?>
                <div class="modal fade" id="linkPropertyModal" tabindex="-1" role="dialog" aria-labelledby="linkPropertyTitle" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <form method="POST" class="modal-content">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="link_property">
                            <div class="modal-header">
                                <h5 class="modal-title" id="linkPropertyTitle">Link a property to <?= htmlspecialchars($company['company_name']) ?></h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <?php if (empty($unlinkedProps)): ?>
                                    <p class="text-muted mb-0">Every active property in the system is already linked to this company, or no properties exist yet.</p>
                                <?php else: ?>
                                <div class="form-group">
                                    <label for="linkPropertyPicker">Property</label>
                                    <input type="text" class="form-control mb-2" id="linkPropertyFilter" placeholder="Filter by address or name&hellip;" autocomplete="off">
                                    <select name="property_id" id="linkPropertyPicker" class="form-control" size="8" required style="font-family:ui-monospace,SFMono-Regular,monospace;">
                                        <?php foreach ($unlinkedProps as $p):
                                            $label = $p['address'] ?? '';
                                            if (!empty($p['city'])) $label .= ', ' . $p['city'];
                                            if (!empty($p['property_name'])) $label = $p['property_name'] . ' — ' . $label;
                                        ?>
                                        <option value="<?= (int)$p['id'] ?>" data-search="<?= htmlspecialchars(strtolower(($p['property_name'] ?? '') . ' ' . ($p['address'] ?? '') . ' ' . ($p['city'] ?? ''))) ?>">
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">
                                        Don't see it? The property needs to exist first — create it from the Client page or Schedule.
                                        Tip: you can set a <strong>Property Name</strong> like "VR14-50" on the property itself so it's easier to recognise here.
                                    </small>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="linkRelType">Relationship</label>
                                        <select name="relationship_type" id="linkRelType" class="form-control">
                                            <option value="owner">Owner (strata / numbered company)</option>
                                            <option value="manager" selected>Manager (property management firm)</option>
                                            <option value="billing">Billing party only</option>
                                            <option value="tenant">Tenant</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-6 d-flex align-items-end">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="linkIsPrimary" name="is_primary" value="1">
                                            <label class="custom-control-label" for="linkIsPrimary">Mark as primary</label>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <?php if (!empty($unlinkedProps)): ?>
                                <button type="submit" class="btn btn-primary">Link Property</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                (function () {
                    var filter = document.getElementById('linkPropertyFilter');
                    var picker = document.getElementById('linkPropertyPicker');
                    if (filter && picker) {
                        filter.addEventListener('input', function () {
                            var q = filter.value.trim().toLowerCase();
                            Array.from(picker.options).forEach(function (opt) {
                                var hay = opt.getAttribute('data-search') || '';
                                opt.hidden = q !== '' && hay.indexOf(q) === -1;
                            });
                            // Auto-select first visible option for Enter-key friendliness
                            var firstVisible = Array.from(picker.options).find(function (o) { return !o.hidden; });
                            if (firstVisible) picker.value = firstVisible.value;
                        });
                    }
                    // Whole-row click → property page, but ignore clicks on
                    // the Unlink form (button / icon) so those still POST
                    // instead of navigating away.
                    document.querySelectorAll('.mw-row-link').forEach(function (row) {
                        row.addEventListener('click', function (e) {
                            if (e.target.closest('form, button, a')) return;
                            var href = row.getAttribute('data-href');
                            if (href) window.location.href = href;
                        });
                    });
                }());
                </script>
                <?php endif; ?>
                </section>

                <!-- Quotes Section -->
                <section class="mw-company-section" id="quotes">
                    <div class="mw-company-section-title">Quotes <span class="count">(<?= count($quotes) ?>)</span></div>
                    <div class="card">
                        <div class="card-body">
                            <?php if (empty($quotes)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="dollar-sign" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p>No quotes for this company yet.</p>
                                    <a href="/crm/quotes/create.php?company_id=<?= $companyId ?>" class="btn btn-sm btn-outline-primary">Create Quote</a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr><th>Quote #</th><th>Status</th><th>Total</th><th>Created</th><th></th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($quotes as $q): ?>
                                                <tr>
                                                    <td><a href="/crm/quotes/view.php?id=<?= $q['id'] ?>"><?= htmlspecialchars($q['quote_number'] ?? '#' . $q['id']) ?></a></td>
                                                    <td><?= getStatusBadge($q['status'], 'quote') ?></td>
                                                    <td><?= formatCurrency($q['total_amount'] ?? $q['subtotal'] ?? 0) ?></td>
                                                    <td><?= formatDate($q['created_at']) ?></td>
                                                    <td class="text-right">
                                                        <a href="/crm/quotes/view.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Jobs Section -->
                <section class="mw-company-section" id="jobs">
                    <div class="mw-company-section-title">Jobs <span class="count">(<?= count($jobs) ?>)</span></div>
                    <div class="card">
                        <div class="card-body">
                            <?php if (empty($jobs)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="briefcase" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p>No job plans for this company yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr><th>Plan #</th><th>Title</th><th>Frequency</th><th>Status</th><th>Created</th><th></th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($jobs as $j): ?>
                                                <tr>
                                                    <td><a href="/crm/jobs/view.php?id=<?= $j['id'] ?>"><?= htmlspecialchars($j['plan_number'] ?? '#' . $j['id']) ?></a></td>
                                                    <td><?= htmlspecialchars($j['title'] ?? '—') ?></td>
                                                    <td><?= htmlspecialchars(ucfirst($j['recurrence_pattern'] ?? '—')) ?></td>
                                                    <td><?= getStatusBadge($j['status'] ?? 'draft', 'job') ?></td>
                                                    <td><?= formatDate($j['created_at']) ?></td>
                                                    <td class="text-right">
                                                        <a href="/crm/jobs/view.php?id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Invoices Section -->
                <section class="mw-company-section" id="invoices">
                    <div class="mw-company-section-title">Invoices <span class="count">(<?= count($invoices) ?>)</span></div>
                    <?php if ($outstandingBalance > 0): ?>
                        <div class="mw-outstanding-balance mb-3">
                            <div class="small text-muted mb-1">Outstanding Balance</div>
                            <div class="amount"><?= formatCurrency($outstandingBalance) ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-body">
                            <?php if (empty($invoices)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="file-text" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p>No invoices for this company yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr><th>Invoice #</th><th>Status</th><th>Total</th><th>Balance Due</th><th>Due Date</th><th></th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($invoices as $inv): ?>
                                                <tr>
                                                    <td><a href="/crm/invoices/view.php?id=<?= $inv['id'] ?>"><?= htmlspecialchars($inv['invoice_number'] ?? '#' . $inv['id']) ?></a></td>
                                                    <td><?= getStatusBadge($inv['status'], 'invoice') ?></td>
                                                    <td><?= formatCurrency($inv['total'] ?? $inv['total_amount'] ?? $inv['subtotal'] ?? 0) ?></td>
                                                    <td><?= formatCurrency($inv['balance_due'] ?? 0) ?></td>
                                                    <td><?= $inv['due_date'] ? formatDate($inv['due_date']) : '—' ?></td>
                                                    <td class="text-right">
                                                        <a href="/crm/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Activity Section -->
                <section class="mw-company-section" id="activity">
                    <div class="mw-company-section-title">Activity</div>
                    <div class="card">
                        <div class="card-body">
                            <?php if (empty($activities)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="activity" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p>No activity recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="mw-activity-timeline">
                                    <?php foreach ($activities as $act): ?>
                                        <div class="d-flex mb-3 pb-3 border-bottom">
                                            <div class="mr-3">
                                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                                                    <i data-feather="activity" style="width:16px;height:16px;" class="text-muted"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="font-weight-bold"><?= htmlspecialchars($act['action'] ?? '') ?></div>
                                                <?php if (!empty($act['details'])): ?>
                                                    <div class="text-muted small"><?= htmlspecialchars($act['details']) ?></div>
                                                <?php endif; ?>
                                                <div class="text-muted small mt-1">
                                                    <?= htmlspecialchars($act['user_name'] ?? 'System') ?> &middot;
                                                    <?= timeAgo($act['created_at']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

            </div>

            <script>
            function archiveCompany(id) {
                if (!confirm('Archive this company? It will be hidden from the default list but can be restored.')) return;
                fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'archive', company_id: id, csrf_token: '<?= generateCSRFToken() ?>'})
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.error || 'Archive failed');
                })
                .catch(() => alert('Request failed'));
            }

            function restoreCompany(id) {
                if (!confirm('Restore this company to active status?')) return;
                fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'restore', company_id: id, csrf_token: '<?= generateCSRFToken() ?>'})
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.error || 'Restore failed');
                })
                .catch(() => alert('Request failed'));
            }

            function deleteCompany(id, name) {
                if (!confirm('Permanently delete "' + name + '"? This cannot be undone.')) return;
                if (!confirm('Are you sure? This will permanently remove the company record.')) return;
                fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'delete', company_id: id, csrf_token: '<?= generateCSRFToken() ?>'})
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) window.location.href = 'index.php';
                    else alert(data.error || 'Delete failed');
                })
                .catch(() => alert('Request failed'));
            }
            </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
