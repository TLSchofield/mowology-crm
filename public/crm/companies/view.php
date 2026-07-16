<?php
/**
 * Companies - View Company Profile
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/../app_config/secrets.php';

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

// Primary contact portal token (for combined portal link)
$primaryContactToken = null;
if (!empty($company['primary_contact_id'])) {
    $ptStmt = $db->prepare("SELECT portal_token FROM contacts WHERE id = ? LIMIT 1");
    $ptStmt->execute([$company['primary_contact_id']]);
    $row = $ptStmt->fetch(PDO::FETCH_ASSOC);
    $primaryContactToken = $row['portal_token'] ?? null;
}

// Get related data
$companyContacts = getCompanyContacts($companyId);
$companyProperties = getCompanyProperties($companyId);

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

// Summary stats
$totalInvoiced = array_sum(array_map(function ($inv) {
    return floatval($inv['total'] ?? $inv['total_amount'] ?? $inv['subtotal'] ?? 0);
}, $invoices));
$activeJobCount = count(array_filter($jobs, function ($j) {
    return in_array($j['status'] ?? '', ['active', 'scheduled', 'in_progress']);
}));
$geocodedCount = count(array_filter($companyProperties, function ($p) {
    return !empty($p['latitude']) && !empty($p['longitude']);
}));

$typeLabels = ['individual' => 'Individual', 'business' => 'Business', 'strata' => 'Strata', 'property_manager' => 'Property Manager'];

$pageTitle = htmlspecialchars($company['company_name']);
$activePage = 'companies';
$stripePublishableKey = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
$extraHead = '<script src="https://js.stripe.com/v3/" defer></script>'
           . '<script src="https://maps.googleapis.com/maps/api/js?key=' . (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '') . '&libraries=places" defer></script>';
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
                    <?php if ($primaryContactToken): ?>
                        <button class="btn btn-outline-secondary mr-1" onclick="copyCombinedPortalLink()"
                                title="Copy combined personal + business portal link for this client">
                            <i data-feather="link" class="align-middle mr-1" style="width:14px;height:14px;"></i> Combined Portal
                        </button>
                        <input type="hidden" id="mw-combined-portal-url"
                               value="https://mowology.ca/customer/combined-portal.php?token=<?= htmlspecialchars($primaryContactToken, ENT_QUOTES) ?>">
                    <?php endif; ?>
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

            <!-- Tabs -->
            <ul class="nav nav-tabs mw-company-tabs mb-4" id="companyTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="overview-tab" data-toggle="tab" href="#overview" role="tab">Overview</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="contacts-tab" data-toggle="tab" href="#contacts" role="tab">
                        Contacts <span class="badge badge-light ml-1"><?= count($companyContacts) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="properties-tab" data-toggle="tab" href="#properties" role="tab">
                        Properties <span class="badge badge-light ml-1"><?= count($companyProperties) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="quotes-tab" data-toggle="tab" href="#quotes" role="tab">
                        Quotes <span class="badge badge-light ml-1"><?= count($quotes) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="jobs-tab" data-toggle="tab" href="#jobs" role="tab">
                        Jobs <span class="badge badge-light ml-1"><?= count($jobs) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="invoices-tab" data-toggle="tab" href="#invoices" role="tab">
                        Invoices <span class="badge badge-light ml-1"><?= count($invoices) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="activity-tab" data-toggle="tab" href="#activity" role="tab">Activity</a>
                </li>
            </ul>

            <div class="tab-content" id="companyTabContent">

                <!-- Overview Tab -->
                <div class="tab-pane fade show active" id="overview" role="tabpanel">

                    <!-- Summary stats -->
                    <div class="mw-stats-row mw-company-stats mb-4">
                        <div class="mw-stat-card properties">
                            <h4>Properties</h4>
                            <div class="value"><?= count($companyProperties) ?></div>
                            <?php if (count($companyProperties) > 0): ?>
                                <div class="mw-stat-sub"><?= $geocodedCount ?>/<?= count($companyProperties) ?> geocoded</div>
                            <?php endif; ?>
                        </div>
                        <div class="mw-stat-card scheduled">
                            <h4>Quotes</h4>
                            <div class="value"><?= count($quotes) ?></div>
                        </div>
                        <div class="mw-stat-card in-progress">
                            <h4>Active Jobs</h4>
                            <div class="value"><?= $activeJobCount ?></div>
                            <?php if (count($jobs) > $activeJobCount): ?>
                                <div class="mw-stat-sub"><?= count($jobs) ?> total</div>
                            <?php endif; ?>
                        </div>
                        <div class="mw-stat-card revenue">
                            <h4>Total Invoiced</h4>
                            <div class="value mw-stat-currency"><?= formatCurrency($totalInvoiced) ?></div>
                            <?php if ($outstandingBalance > 0): ?>
                                <div class="mw-stat-sub text-warning"><?= formatCurrency($outstandingBalance) ?> outstanding</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card mb-4">
                                <div class="card-header"><h5 class="card-title mb-0">Company Details</h5></div>
                                <div class="card-body p-0">
                                    <table class="table table-sm mb-0">
                                        <tr><td class="font-weight-bold text-muted" style="width:40%;"><span class="mw-icon-box"><i data-feather="briefcase" class="mw-detail-icon"></i></span> Name</td><td><?= htmlspecialchars($company['company_name']) ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="tag" class="mw-detail-icon"></i></span> Type</td><td><?= htmlspecialchars($typeLabels[$company['company_type']] ?? 'Individual') ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="activity" class="mw-detail-icon"></i></span> Status</td><td><span class="badge badge-<?= $statusColor ?>"><?= htmlspecialchars(ucfirst($company['account_status'])) ?></span></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="trending-up" class="mw-detail-icon"></i></span> Lifecycle Stage</td><td><?= htmlspecialchars(ucfirst($company['lifecycle_stage'] ?? 'prospect')) ?></td></tr>
                                        <tr>
                                            <td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="user" class="mw-detail-icon"></i></span> Primary Contact</td>
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
                                            <td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="user" class="mw-detail-icon"></i></span> Billing Contact</td>
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
                                            <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="message-square" class="mw-detail-icon"></i></span> Notes</td><td><?= nl2br(htmlspecialchars($company['notes'])) ?></td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($company['created_at'])): ?>
                                            <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="calendar" class="mw-detail-icon"></i></span> Member Since</td><td class="text-muted small"><?= formatDate($company['created_at']) ?></td></tr>
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
                                            <td class="font-weight-bold text-muted" style="width:40%;"><span class="mw-icon-box"><i data-feather="map-pin" class="mw-detail-icon"></i></span> Billing Address</td>
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
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="mail" class="mw-detail-icon"></i></span> Billing Email</td><td><?= $company['billing_email'] ? htmlspecialchars($company['billing_email']) : '<span class="text-muted">—</span>' ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="phone" class="mw-detail-icon"></i></span> Billing Phone</td><td><?= $company['billing_phone'] ? htmlspecialchars($company['billing_phone']) : '<span class="text-muted">—</span>' ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="clock" class="mw-detail-icon"></i></span> Payment Terms</td><td><?= htmlspecialchars($company['payment_terms'] ?? 'Net 30') ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="credit-card" class="mw-detail-icon"></i></span> Payment Method</td><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $company['payment_method'] ?? 'invoice'))) ?></td></tr>
                                        <tr><td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="send" class="mw-detail-icon"></i></span> Invoice Routing</td><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $company['invoice_routing_method'] ?? 'primary contact'))) ?></td></tr>
                                        <tr>
                                            <td class="font-weight-bold text-muted"><span class="mw-icon-box"><i data-feather="credit-card" class="mw-detail-icon"></i></span> Autopay Card</td>
                                            <td id="mw-company-card-cell">
                                                <?php if (!empty($company['stripe_card_last4'])): ?>
                                                    <span class="text-dark">
                                                        <?= htmlspecialchars(ucfirst($company['stripe_card_brand'] ?? 'Card')) ?>
                                                        &middot;&middot;&middot;&middot; <?= htmlspecialchars($company['stripe_card_last4']) ?>
                                                        &nbsp;<span class="text-muted small">exp <?= htmlspecialchars($company['stripe_card_exp'] ?? '') ?></span>
                                                    </span>
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ml-2" onclick="removeCompanyCard()" title="Remove card">
                                                        <i data-feather="x" style="width:13px;height:13px;"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-link text-primary p-0 ml-1" onclick="openCardModal()" title="Replace card">
                                                        <i data-feather="refresh-cw" style="width:13px;height:13px;"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">No card on file</span>
                                                    <button type="button" class="btn btn-sm btn-outline-success ml-2" onclick="openCardModal()">
                                                        <i data-feather="plus" style="width:12px;height:12px;"></i> Set Up Card
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
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
                </div>

                <!-- Contacts Tab -->
                <div class="tab-pane fade" id="contacts" role="tabpanel">
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
                                                        if (!empty($ct['contact_role'])) {
                                                            $roleLabels = [
                                                                'property_manager' => 'Property Manager',
                                                                'strata_rep'       => 'Strata Rep',
                                                                'owner'            => 'Owner',
                                                                'billing_contact'  => 'Billing Contact',
                                                                'site_supervisor'  => 'Site Supervisor',
                                                                'other'            => 'Other',
                                                            ];
                                                            $rLabel = $roleLabels[$ct['contact_role']] ?? ucfirst(str_replace('_', ' ', $ct['contact_role']));
                                                            $roles[] = '<span class="badge badge-success">' . htmlspecialchars($rLabel) . '</span>';
                                                        }
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
                </div>

                <!-- Properties Tab -->
                <div class="tab-pane fade" id="properties" role="tabpanel">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-end mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#linkPropertyModal">
                                    <i data-feather="link" style="width:13px;height:13px;margin-right:4px;"></i> Link Property
                                </button>
                            </div>
                            <?php if (empty($companyProperties)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i data-feather="map-pin" style="width:32px;height:32px;" class="mb-2"></i>
                                    <p>No properties linked to this company.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr><th>Address</th><th>City</th><th>Relationship</th><th>Primary</th><th>Location</th><th></th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($companyProperties as $prop): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($prop['address'] ?? '—') ?></td>
                                                    <td><?= htmlspecialchars($prop['city'] ?? '—') ?></td>
                                                    <td>
                                                        <span class="badge badge-light">
                                                            <?= htmlspecialchars(ucfirst($prop['relationship_type'] ?? 'owner')) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $prop['is_primary'] ? '<span class="text-success">Yes</span>' : '—' ?></td>
                                                    <td>
                                                        <?php if (!empty($prop['latitude']) && !empty($prop['longitude'])): ?>
                                                            <span class="mw-geocoded-yes" title="<?= htmlspecialchars($prop['latitude'] . ', ' . $prop['longitude']) ?>">
                                                                <i data-feather="map-pin" class="mw-geo-icon"></i> Geocoded
                                                            </span>
                                                            <a href="https://maps.google.com/?q=<?= urlencode($prop['latitude'] . ',' . $prop['longitude']) ?>" target="_blank" rel="noopener" class="mw-geo-map-link ml-1" title="Open in Google Maps">
                                                                <i data-feather="external-link" class="mw-geo-icon"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-secondary mw-geocode-btn"
                                                                    onclick="geocodeCompanyProp(<?= (int)$prop['id'] ?>, this)"
                                                                    title="Geocode this address">
                                                                <i data-feather="map-pin" style="width:13px;height:13px;"></i> Geocode
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                                onclick="unlinkProperty(<?= (int)$prop['id'] ?>)"
                                                                title="Unlink this property">
                                                            <i data-feather="x" style="width:12px;height:12px;"></i>
                                                        </button>
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

                <!-- Quotes Tab -->
                <div class="tab-pane fade" id="quotes" role="tabpanel">
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
                </div>

                <!-- Jobs Tab -->
                <div class="tab-pane fade" id="jobs" role="tabpanel">
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
                </div>

                <!-- Invoices Tab -->
                <div class="tab-pane fade" id="invoices" role="tabpanel">
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
                </div>

                <!-- Activity Tab -->
                <div class="tab-pane fade" id="activity" role="tabpanel">
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
                </div>

            </div>

            <!-- Card Setup Modal -->
            <div class="modal fade" id="companyCardModal" tabindex="-1" role="dialog" aria-labelledby="companyCardModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="companyCardModalLabel">
                                <i data-feather="credit-card" style="width:16px;height:16px;" class="mr-1"></i>
                                Business Card — <?= htmlspecialchars($company['company_name']) ?>
                            </h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">
                                This card will be charged automatically for all invoices linked to
                                <strong><?= htmlspecialchars($company['company_name']) ?></strong>.
                                Personal invoices will continue to use <?= !empty($company['primary_first_name']) ? htmlspecialchars($company['primary_first_name']) . "'s " : 'the contact\'s ' ?>card on file.
                            </p>
                            <div id="mw-card-element" style="padding:10px;border:1px solid #dee2e6;border-radius:4px;background:#fff;min-height:40px;"></div>
                            <div id="mw-card-errors" class="text-danger small mt-2" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-success" id="mw-save-card-btn" onclick="saveCompanyCard()">
                                <i data-feather="lock" style="width:13px;height:13px;" class="mr-1"></i>
                                Save Card
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            var mwStripe, mwCardElement;
            var mwCompanyId  = <?= $companyId ?>;
            var mwCsrfToken  = '<?= generateCSRFToken() ?>';
            var mwStripeKey  = '<?= htmlspecialchars($stripePublishableKey ?? '', ENT_QUOTES) ?>';

            // Property data for geocoder
            var mwCompanyProps = <?= json_encode(array_map(function($p) {
                return [
                    'id'          => (int)$p['id'],
                    'address'     => $p['address'] ?? '',
                    'city'        => $p['city'] ?? '',
                    'province'    => $p['province'] ?? 'BC',
                    'postal_code' => $p['postal_code'] ?? '',
                ];
            }, $companyProperties)) ?>;

            function geocodeCompanyProp(propId, btn) {
                var prop = mwCompanyProps.find(function(p) { return p.id === propId; });
                if (!prop) { alert('Property data not found.'); return; }
                var origHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
                var fullAddr = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada']
                    .filter(Boolean).join(', ');
                var geocoder = new google.maps.Geocoder();
                geocoder.geocode({ address: fullAddr }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                        var loc = results[0].geometry.location;
                        fetch('/crm/api/geocode-save.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                property_id: propId,
                                lat: loc.lat(),
                                lng: loc.lng(),
                                csrf_token: mwCsrfToken
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) { location.reload(); }
                            else { btn.disabled = false; btn.innerHTML = origHtml; alert(data.error || 'Save failed'); }
                        })
                        .catch(function() { btn.disabled = false; btn.innerHTML = origHtml; alert('Request failed'); });
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                        alert('Could not geocode: ' + status + '\nAddress tried: ' + fullAddr);
                    }
                });
            }

            function openCardModal() {
                $('#companyCardModal').modal('show');
                if (!mwStripe && mwStripeKey) {
                    mwStripe = Stripe(mwStripeKey);
                    var elements = mwStripe.elements();
                    mwCardElement = elements.create('card', {
                        style: {
                            base: { fontSize: '15px', color: '#343a40', '::placeholder': { color: '#adb5bd' } },
                            invalid: { color: '#dc3545' }
                        }
                    });
                    mwCardElement.mount('#mw-card-element');
                    mwCardElement.on('change', function(e) {
                        document.getElementById('mw-card-errors').textContent = e.error ? e.error.message : '';
                    });
                    if (window.feather) feather.replace();
                }
            }

            function saveCompanyCard() {
                if (!mwStripe || !mwCardElement) return;
                var btn = document.getElementById('mw-save-card-btn');
                btn.disabled = true;
                btn.textContent = 'Saving…';

                mwStripe.createPaymentMethod({ type: 'card', card: mwCardElement })
                .then(function(result) {
                    if (result.error) {
                        document.getElementById('mw-card-errors').textContent = result.error.message;
                        btn.disabled = false;
                        btn.textContent = 'Save Card';
                        return;
                    }
                    var fd = new FormData();
                    fd.append('company_id', mwCompanyId);
                    fd.append('payment_method_id', result.paymentMethod.id);
                    fd.append('csrf_token', mwCsrfToken);

                    fetch('api-save-company-card.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            $('#companyCardModal').modal('hide');
                            var brand = data.card_brand ? data.card_brand.charAt(0).toUpperCase() + data.card_brand.slice(1) : 'Card';
                            document.getElementById('mw-company-card-cell').innerHTML =
                                '<span class="text-dark">' + brand + ' &middot;&middot;&middot;&middot; ' + data.card_last4 +
                                ' &nbsp;<span class="text-muted small">exp ' + data.card_exp + '</span></span>' +
                                ' <button type="button" class="btn btn-sm btn-link text-danger p-0 ml-2" onclick="removeCompanyCard()" title="Remove card">' +
                                '<i data-feather="x" style="width:13px;height:13px;"></i></button>' +
                                ' <button type="button" class="btn btn-sm btn-link text-primary p-0 ml-1" onclick="openCardModal()" title="Replace card">' +
                                '<i data-feather="refresh-cw" style="width:13px;height:13px;"></i></button>';
                            if (window.feather) feather.replace();
                        } else {
                            document.getElementById('mw-card-errors').textContent = data.error || 'Save failed. Please try again.';
                            btn.disabled = false;
                            btn.textContent = 'Save Card';
                        }
                    })
                    .catch(function() {
                        document.getElementById('mw-card-errors').textContent = 'Network error. Please try again.';
                        btn.disabled = false;
                        btn.textContent = 'Save Card';
                    });
                });
            }

            function removeCompanyCard() {
                if (!confirm('Remove the business card on file for <?= htmlspecialchars(addslashes($company['company_name'])) ?>? Autopay will be disabled until a new card is added.')) return;
                var fd = new FormData();
                fd.append('company_id', mwCompanyId);
                fd.append('csrf_token', mwCsrfToken);
                fetch('api-save-company-card.php?action=remove', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) location.reload();
                    else alert(data.error || 'Remove failed');
                })
                .catch(function() { alert('Network error'); });
            }

            function copyCombinedPortalLink() {
                var url = document.getElementById('mw-combined-portal-url');
                if (!url) return;
                navigator.clipboard.writeText(url.value).then(function() {
                    var btn = event.currentTarget;
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<i data-feather="check" style="width:14px;height:14px;" class="align-middle mr-1"></i> Copied!';
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-outline-success');
                    if (window.feather) feather.replace();
                    setTimeout(function() {
                        btn.innerHTML = orig;
                        btn.classList.remove('btn-outline-success');
                        btn.classList.add('btn-outline-secondary');
                        if (window.feather) feather.replace();
                    }, 2500);
                });
            }

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

<!-- ── Link Property Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="linkPropertyModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i data-feather="link" style="width:15px;height:15px;vertical-align:-2px;margin-right:6px;"></i> Link Property</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Property</label>
                    <input type="hidden" id="linkPropHiddenId">
                    <div style="position:relative;">
                        <input type="text" id="linkPropSearch" class="form-control" autocomplete="off" placeholder="Search by address…">
                        <div id="linkPropResults" style="display:none;position:absolute;z-index:1060;width:100%;background:#fff;border:1px solid #dee2e6;border-radius:4px;max-height:200px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);top:100%;left:0;"></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="form-label">Relationship</label>
                            <select id="linkPropRelType" class="form-control">
                                <option value="manager" selected>Manager</option>
                                <option value="owner">Owner</option>
                                <option value="billing">Billing</option>
                                <option value="tenant">Tenant</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-6 d-flex align-items-end pb-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="linkPropIsPrimary" checked>
                            <label class="custom-control-label" for="linkPropIsPrimary">Mark as Primary</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="linkPropSaveBtn">
                    <i data-feather="link" style="width:13px;height:13px;"></i> Link Property
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var COMPANY_ID = <?= (int)$companyId ?>;
    var CSRF = '<?= generateCSRFToken() ?>';

    // ── Property search autocomplete ───────────────────────────────────
    var searchInput = document.getElementById('linkPropSearch');
    var hiddenId    = document.getElementById('linkPropHiddenId');
    var resultsBox  = document.getElementById('linkPropResults');
    var debounceTimer;

    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = this.value.trim();
        hiddenId.value = '';
        if (q.length < 2) { resultsBox.style.display = 'none'; return; }
        debounceTimer = setTimeout(function () {
            fetch('/crm/api/client-search.php?action=search&type=property&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    resultsBox.innerHTML = '';
                    var items = (data && data.results) ? data.results : [];
                    if (!items.length) {
                        // fallback: search all-contacts properties via different endpoint
                        return fetch('/crm/api/client-search.php?action=all-contacts')
                            .then(function(r2){return r2.json();})
                            .then(function(d2){
                                // Try direct property search
                                resultsBox.innerHTML = '<div style="padding:8px 12px;color:#6c757d;font-size:13px;">No results — try fewer words</div>';
                                resultsBox.style.display = 'block';
                            });
                    }
                    items.forEach(function (p) {
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;';
                        item.textContent = p.label + (p.sublabel ? ' — ' + p.sublabel : '');
                        item.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            searchInput.value = p.label;
                            hiddenId.value = p.id;
                            resultsBox.style.display = 'none';
                        });
                        item.addEventListener('mouseover', function () { this.style.background = '#f8f9fa'; });
                        item.addEventListener('mouseout',  function () { this.style.background = ''; });
                        resultsBox.appendChild(item);
                    });
                    resultsBox.style.display = 'block';
                })
                .catch(function () { resultsBox.style.display = 'none'; });
        }, 250);
    });
    searchInput.addEventListener('blur', function () {
        setTimeout(function () { resultsBox.style.display = 'none'; }, 150);
    });

    // ── Save ──────────────────────────────────────────────────────────
    document.getElementById('linkPropSaveBtn').addEventListener('click', function () {
        var propId = parseInt(hiddenId.value || '0', 10);
        if (!propId) { alert('Please select a property from the list.'); return; }
        var relType   = document.getElementById('linkPropRelType').value;
        var isPrimary = document.getElementById('linkPropIsPrimary').checked ? 1 : 0;

        fetch('/crm/companies/api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'link_property',
                company_id: COMPANY_ID,
                property_id: propId,
                relationship_type: relType,
                is_primary: isPrimary,
                csrf_token: CSRF
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                $('#linkPropertyModal').modal('hide');
                window.location.reload();
            } else {
                alert(d.error || 'Failed to link property.');
            }
        })
        .catch(function () { alert('Network error.'); });
    });

    // ── Unlink ────────────────────────────────────────────────────────
    window.unlinkProperty = function (propertyId) {
        if (!confirm('Remove this property from the company?')) return;
        fetch('/crm/companies/api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'unlink_property',
                company_id: COMPANY_ID,
                property_id: propertyId,
                csrf_token: CSRF
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) window.location.reload();
            else alert(d.error || 'Failed to unlink.');
        });
    };
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
