<?php
/**
 * Contract Setup Wizard
 *
 * Guides admin through creating multiple plans for a large contract property.
 * Step 1: Select property → Step 2: Define services → Step 3: Billing & review → Create.
 *
 * One service with multiple days selected = one plan per day.
 * All plans created in a single batch via createJobPlan().
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();

$db   = getDB();
$csrf = generateCSRFToken();

/* ─── POST: Create all plans ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }

    $raw = json_decode($_POST['contract_data'] ?? '', true);
    if (!$raw || empty($raw['property_id']) || empty($raw['services'])) {
        http_response_code(400);
        die('Invalid contract data.');
    }

    $propertyId   = (int)$raw['property_id'];
    $startDate    = $raw['start_date']    ?? date('Y-m-d');
    $endDate      = !empty($raw['end_date']) ? $raw['end_date'] : null;
    $horizonDays  = (int)($raw['horizon_days'] ?? 90);
    $pricingModel = $raw['pricing_model'] ?? 'monthly_flat';
    $monthlyRate  = (float)($raw['monthly_rate'] ?? 0);
    $billingIdx   = (int)($raw['billing_service_index'] ?? 0);  // which service gets billing
    $services     = $raw['services'];

    $DAY_NAMES = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    $createdPlans = [];
    $errors       = [];
    $globalIdx    = 0; // tracks which service index is the billing one across multi-day expansions

    foreach ($services as $svcIdx => $svc) {
        $days      = array_map('intval', $svc['days'] ?? [0]);
        $multiDay  = count($days) > 1;
        $recurrence        = $svc['recurrence']        ?? 'weekly';
        $recurrInterval    = (int)($svc['recurrence_interval'] ?? 1);
        $recurrUnit        = $svc['recurrence_interval_unit']  ?? 'weeks';
        $crewId            = !empty($svc['crew_id']) ? (int)$svc['crew_id'] : null;
        $duration          = (int)($svc['duration'] ?? 60);
        $timeStart         = $svc['time_start'] ?? null;
        $timeEnd           = $svc['time_end']   ?? null;
        $description       = trim($svc['description'] ?? '');

        foreach ($days as $dayNum) {
            $dayName   = $DAY_NAMES[$dayNum] ?? '';
            $planTitle = $svc['title'];
            if ($multiDay) $planTitle .= " ({$dayName})";

            // Billing: primary service gets the rate; all others $0
            $planPricing = [
                'pricing_model'      => $pricingModel,
                'monthly_flat_price' => null,
                'price_per_visit'    => null,
                'estimated_amount'   => null,
            ];
            if ($pricingModel === 'monthly_flat') {
                $isMainBillingPlan = ($svcIdx === $billingIdx && $dayNum === ($days[0] ?? $dayNum));
                if ($isMainBillingPlan) {
                    $planPricing['monthly_flat_price'] = $monthlyRate;
                    $planPricing['estimated_amount']   = $monthlyRate;
                } else {
                    $planPricing['monthly_flat_price'] = 0.00;
                    $planPricing['estimated_amount']   = 0.00;
                    $note = 'Included in monthly flat contract (see primary plan for billing).';
                    $description = $description ? $description . "\n\n" . $note : $note;
                }
            } elseif ($pricingModel === 'per_visit') {
                $planPricing['price_per_visit']  = $monthlyRate;
                $planPricing['estimated_amount'] = $monthlyRate;
            }

            $planData = array_merge([
                'property_id'                => $propertyId,
                'title'                      => $planTitle,
                'description'                => $description,
                'service_type'               => $svc['type'],
                'is_recurring'               => 1,
                'recurrence_pattern'         => $recurrence,
                'recurrence_interval'        => $recurrInterval,
                'recurrence_interval_unit'   => $recurrUnit,
                'recurrence_day_of_week'     => $dayNum,
                'plan_start_date'            => $startDate,
                'plan_end_date'              => $endDate,
                'default_crew_id'            => $crewId,
                'default_crew_size'          => 1,
                'estimated_duration_minutes' => $duration,
                'default_time_start'         => $timeStart ?: null,
                'default_time_end'           => $timeEnd   ?: null,
                'horizon_days'               => $horizonDays,
                'gps_enforcement'            => 'optional',
            ], $planPricing);

            $result = createJobPlan($planData, (int)$user['id']);

            if ($result['success']) {
                $createdPlans[] = [
                    'id'     => $result['plan_id'],
                    'number' => $result['plan_number'],
                    'title'  => $planTitle,
                ];
                // Generate visits immediately
                generateVisits($result['plan_id'], $horizonDays);
            } else {
                $errors[] = implode(', ', $result['errors']) . " (plan: {$planTitle})";
            }
        }
    }

    if (!empty($errors) && empty($createdPlans)) {
        $errMsg = urlencode(implode(' | ', $errors));
        header("Location: /crm/jobs/new-contract.php?error={$errMsg}");
        exit;
    }

    $planIds = implode(',', array_column($createdPlans, 'id'));
    header("Location: /crm/jobs/new-contract.php?contract_created=1&plans={$planIds}&count=" . count($createdPlans));
    exit;
}

/* ─── GET: Fetch data for wizard ──────────────────────────────────────── */

// All active properties with contact
$properties = $db->query("
    SELECT p.id, p.address, p.city, p.province, p.postal_code,
           p.property_type, p.latitude, p.longitude,
           TRIM(CONCAT(c.first_name, ' ', COALESCE(c.last_name, ''))) AS contact_name,
           c.phone AS contact_phone,
           c.email AS contact_email,
           c.id    AS contact_id
    FROM   properties p
    LEFT JOIN contacts c ON c.id = p.site_contact_id
    WHERE  p.status = 'active'
    ORDER  BY p.city, p.address
")->fetchAll();

// Active crew members
$crewMembers = $db->query("
    SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name
")->fetchAll();

// Success: load created plan details
$successPlans = [];
if (!empty($_GET['contract_created']) && !empty($_GET['plans'])) {
    $planIdList = array_map('intval', explode(',', $_GET['plans']));
    if ($planIdList) {
        $placeholders = implode(',', array_fill(0, count($planIdList), '?'));
        $stmt = $db->prepare("
            SELECT p.id, p.plan_number, p.title, p.service_type, p.recurrence_day_of_week,
                   prop.address, prop.city
            FROM   job_plans p
            JOIN   properties prop ON prop.id = p.property_id
            WHERE  p.id IN ({$placeholders})
            ORDER  BY p.id
        ");
        $stmt->execute($planIdList);
        $successPlans = $stmt->fetchAll();
    }
}

$pageTitle  = 'New Contract Setup';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<?php if (!empty($successPlans)): ?>
<!-- ══════════════ SUCCESS STATE ══════════════ -->
<div class="mw-cw-success-wrap">
    <div class="card mw-cw-success-card">
        <div class="card-body text-center py-5">
            <div class="mw-cw-success-icon">
                <i data-feather="check-circle"></i>
            </div>
            <h2 class="mt-3 mb-1">Contract Created</h2>
            <p class="text-muted mb-4">
                <?= count($successPlans) ?> plan<?= count($successPlans) !== 1 ? 's' : '' ?> created and scheduled.
                Visits have been generated based on the horizon you set.
            </p>
            <div class="mw-cw-plan-list mb-4">
                <?php foreach ($successPlans as $sp):
                    $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    $day = $sp['recurrence_day_of_week'] !== null ? $dayNames[(int)$sp['recurrence_day_of_week']] : '—';
                ?>
                <div class="mw-cw-plan-row">
                    <span class="mw-cw-plan-num badge badge-secondary"><?= htmlspecialchars($sp['plan_number']) ?></span>
                    <span class="mw-cw-plan-title"><?= htmlspecialchars($sp['title']) ?></span>
                    <span class="mw-cw-plan-day text-muted small">Weekly <?= $day ?></span>
                    <a href="/crm/jobs/view.php?id=<?= $sp['id'] ?>" class="btn btn-sm btn-outline-success">View Plan</a>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="/crm/jobs/new-contract.php" class="btn btn-outline-secondary mr-2">New Contract</a>
            <a href="/crm/jobs/index.php" class="btn btn-success">Back to Plans</a>
        </div>
    </div>
</div>

<?php elseif (!empty($_GET['error'])): ?>
<!-- Error state -->
<div class="alert alert-danger mx-4 mt-4">
    <strong>Error creating plans:</strong> <?= htmlspecialchars($_GET['error']) ?>
</div>
<a href="/crm/jobs/new-contract.php" class="btn btn-outline-secondary ml-4">Try Again</a>

<?php else: ?>
<!-- ══════════════ WIZARD ══════════════ -->

<div class="mw-cw-wrap">

    <!-- ── Page Header ──────────────────────────────────────── -->
    <div class="mw-cw-header">
        <div class="mw-cw-header-left">
            <a href="/crm/jobs/index.php" class="btn btn-sm btn-outline-secondary mr-2">
                <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Plans
            </a>
            <div>
                <h1 class="mw-cw-title">New Contract Setup</h1>
                <p class="mw-cw-subtitle">Create all service plans for a property in one guided flow.</p>
            </div>
        </div>
    </div>

    <!-- ── Step Indicator ───────────────────────────────────── -->
    <div class="mw-cw-steps">
        <div class="mw-cw-step active" id="step-ind-1">
            <div class="mw-cw-step-num">1</div>
            <div class="mw-cw-step-label">Property</div>
        </div>
        <div class="mw-cw-step-connector"></div>
        <div class="mw-cw-step" id="step-ind-2">
            <div class="mw-cw-step-num">2</div>
            <div class="mw-cw-step-label">Services</div>
        </div>
        <div class="mw-cw-step-connector"></div>
        <div class="mw-cw-step" id="step-ind-3">
            <div class="mw-cw-step-num">3</div>
            <div class="mw-cw-step-label">Review & Create</div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════
         STEP 1 — Property Selection
         ════════════════════════════════════════════════════════ -->
    <div class="mw-cw-step-content" id="step-1">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Select Property</h5>
            </div>
            <div class="card-body">
                <div class="mw-cw-search-wrap mb-3">
                    <i data-feather="search" class="mw-cw-search-icon"></i>
                    <input type="text" id="propSearch" class="form-control mw-cw-search-input"
                           placeholder="Search by address, city, or contact name…">
                </div>
                <div class="mw-cw-prop-grid" id="propGrid">
                    <?php foreach ($properties as $prop): ?>
                    <div class="mw-cw-prop-card"
                         data-id="<?= $prop['id'] ?>"
                         data-address="<?= htmlspecialchars($prop['address']) ?>"
                         data-city="<?= htmlspecialchars($prop['city']) ?>"
                         data-province="<?= htmlspecialchars($prop['province']) ?>"
                         data-contact="<?= htmlspecialchars($prop['contact_name'] ?? '') ?>"
                         data-phone="<?= htmlspecialchars($prop['contact_phone'] ?? '') ?>"
                         data-email="<?= htmlspecialchars($prop['contact_email'] ?? '') ?>"
                         data-search="<?= strtolower(htmlspecialchars($prop['address'] . ' ' . $prop['city'] . ' ' . $prop['contact_name'])) ?>"
                         onclick="selectProperty(this)">
                        <div class="mw-cw-prop-addr"><?= htmlspecialchars($prop['address']) ?></div>
                        <div class="mw-cw-prop-city text-muted small"><?= htmlspecialchars($prop['city']) ?>, <?= htmlspecialchars($prop['province']) ?></div>
                        <?php if ($prop['contact_name']): ?>
                        <div class="mw-cw-prop-contact mt-1">
                            <i data-feather="user" style="width:11px;height:11px;"></i>
                            <?= htmlspecialchars(trim($prop['contact_name'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($properties)): ?>
                    <p class="text-muted">No active properties found. <a href="/crm/clients_appstack.php">Add a property first.</a></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Selected property banner (hidden until selection) -->
        <div class="mw-cw-selected-prop" id="selectedPropBanner" style="display:none;">
            <i data-feather="map-pin" style="width:16px;height:16px;color:var(--mw-green);"></i>
            <div class="mw-cw-selected-prop-info">
                <strong id="selPropAddr">—</strong>
                <span id="selPropContact" class="text-muted ml-2"></span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="clearProperty()">Change</button>
        </div>

        <div class="mw-cw-nav mt-3">
            <div></div>
            <button class="btn btn-success" onclick="goStep(2)" id="step1Next" disabled>
                Next: Define Services <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
            </button>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════
         STEP 2 — Services
         ════════════════════════════════════════════════════════ -->
    <div class="mw-cw-step-content" id="step-2" style="display:none;">

        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0">Service Plans</h5>
                <button type="button" class="btn btn-sm btn-success" onclick="addServiceRow()">
                    <i data-feather="plus" style="width:14px;height:14px;"></i> Add Service
                </button>
            </div>
            <div class="card-body p-0">
                <div id="serviceRows">
                    <!-- Service rows injected by JS -->
                </div>
                <div class="mw-cw-empty-services" id="emptyServices">
                    <i data-feather="layers" style="width:32px;height:32px;color:#adb5bd;"></i>
                    <p class="text-muted mt-2 mb-0">Click <strong>Add Service</strong> to define the services included in this contract.</p>
                </div>
            </div>
        </div>

        <!-- Plans preview -->
        <div class="mw-cw-preview-card card" id="previewCard" style="display:none;">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i data-feather="eye" style="width:14px;height:14px;"></i>
                    Plans to be created: <span id="planCount" class="badge badge-success ml-1">0</span>
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>Plan</th><th>Service</th><th>Day</th><th>Recurrence</th><th>Duration</th><th>Crew</th>
                    </tr></thead>
                    <tbody id="planPreviewBody"></tbody>
                </table>
            </div>
        </div>

        <div class="mw-cw-nav mt-3">
            <button class="btn btn-outline-secondary" onclick="goStep(1)">
                <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back
            </button>
            <button class="btn btn-success" onclick="goStep(3)" id="step2Next" disabled>
                Next: Review & Create <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
            </button>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════
         STEP 3 — Billing & Review
         ════════════════════════════════════════════════════════ -->
    <div class="mw-cw-step-content" id="step-3" style="display:none;">

        <div class="row">
            <!-- Left: Billing & Schedule -->
            <div class="col-md-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Contract Billing</h5></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="font-weight-600">Pricing Model</label>
                            <select class="form-control" id="pricingModel" onchange="updateReview()">
                                <option value="monthly_flat">Monthly Flat Rate</option>
                                <option value="per_visit">Per Visit</option>
                            </select>
                        </div>
                        <div class="form-group" id="monthlyRateGroup">
                            <label class="font-weight-600">Monthly Rate ($)</label>
                            <input type="number" id="monthlyRate" class="form-control" min="0" step="0.01"
                                   placeholder="0.00" oninput="updateReview()">
                        </div>
                        <div class="form-group" id="billingPlanGroup">
                            <label class="font-weight-600">Assign Billing To</label>
                            <select class="form-control" id="billingServiceIndex" onchange="updateReview()">
                                <!-- populated by JS -->
                            </select>
                            <small class="text-muted">This plan carries the monthly rate; other plans are $0 (included).</small>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Schedule</h5></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="font-weight-600">Start Date <span class="text-danger">*</span></label>
                            <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#startDate" aria-haspopup="true" aria-expanded="false">
                                <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span class="mw-datepicker-date" data-mw-dp-label></span>
                                <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <input type="date" id="startDate" class="form-control" hidden
                                   value="<?= date('Y-m-d') ?>" oninput="updateReview()">
                        </div>
                        <div class="form-group">
                            <label class="font-weight-600">End Date <small class="text-muted">(leave blank = ongoing)</small></label>
                            <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#endDate" aria-haspopup="true" aria-expanded="false">
                                <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span class="mw-datepicker-date" data-mw-dp-label></span>
                                <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <input type="date" id="endDate" class="form-control" hidden oninput="updateReview()">
                        </div>
                        <div class="form-group mb-0">
                            <label class="font-weight-600">Visit Horizon</label>
                            <select class="form-control" id="horizonDays" onchange="updateReview()">
                                <option value="30">30 days</option>
                                <option value="60">60 days</option>
                                <option value="90" selected>90 days</option>
                                <option value="120">120 days</option>
                                <option value="180">180 days</option>
                            </select>
                            <small class="text-muted">How far ahead to pre-generate visits.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Plan Summary -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Review: Plans to Create</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table mb-0">
                            <thead><tr>
                                <th>Service</th><th>Day</th><th>Recurrence</th><th>Duration</th><th>Billing</th>
                            </tr></thead>
                            <tbody id="reviewBody"></tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span id="reviewPlanCount" class="font-weight-600">0 plans</span>
                                <span class="text-muted ml-2" id="reviewVisitCadence"></span>
                            </div>
                            <div class="text-right">
                                <div class="text-muted small">Monthly contract value</div>
                                <div class="h4 mb-0 text-success" id="reviewMonthlyTotal">$0.00</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create button -->
        <form id="contractForm" method="POST" action="/crm/jobs/new-contract.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="contract_data" id="contractDataInput">
        </form>

        <div class="mw-cw-nav mt-3">
            <button class="btn btn-outline-secondary" onclick="goStep(2)">
                <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back
            </button>
            <button class="btn btn-success btn-lg" onclick="submitContract()" id="createBtn">
                <i data-feather="check-circle" style="width:16px;height:16px;"></i>
                Create Contract
            </button>
        </div>
    </div>

</div><!-- /.mw-cw-wrap -->

<?php endif; ?>

<!-- ─── Crew data for JS ───────────────────────────────────────────── -->
<script>
const MW_CREW = <?= json_encode(array_map(fn($c) => [
    'id'   => (int)$c['id'],
    'name' => $c['full_name'],
    'role' => $c['role'],
], $crewMembers), JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
/* ════════════════════════════════════════════════════════
   Contract Setup Wizard — JavaScript
   ════════════════════════════════════════════════════════ */
'use strict';

const SERVICE_TYPES = {
    lawn_care:           { label: 'Lawn Care',               duration: 120, icon: 'scissors' },
    garden_maintenance:  { label: 'Garden Maintenance',       duration: 90,  icon: 'feather'  },
    hedge_trimming:      { label: 'Hedge & Tree Trimming',    duration: 60,  icon: 'git-branch'},
    fertilizer_program:  { label: 'Fertilizer Program',       duration: 45,  icon: 'droplet'  },
    seasonal_cleanup:    { label: 'Seasonal Cleanup',         duration: 180, icon: 'wind'     },
    snow_removal:        { label: 'Snow Removal',             duration: 60,  icon: 'cloud-snow'},
    custom:              { label: 'Custom Service',           duration: 60,  icon: 'tool'     },
};

const DAY_NAMES  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const DAY_LABELS = ['Su','Mo','Tu','We','Th','Fr','Sa'];

let selectedProperty = null;
let serviceRows      = [];       // array of service config objects
let rowIdCounter     = 0;

/* ── Property selection ─────────────────────────────────── */
function selectProperty(el) {
    // Deselect previous
    document.querySelectorAll('.mw-cw-prop-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedProperty = {
        id:      el.dataset.id,
        address: el.dataset.address,
        city:    el.dataset.city,
        contact: el.dataset.contact,
        phone:   el.dataset.phone,
        email:   el.dataset.email,
    };
    document.getElementById('selPropAddr').textContent    = selectedProperty.address + ', ' + selectedProperty.city;
    document.getElementById('selPropContact').textContent = selectedProperty.contact ? '(' + selectedProperty.contact + ')' : '';
    document.getElementById('selectedPropBanner').style.display = '';
    document.getElementById('propGrid').style.display    = 'none';
    document.getElementById('propSearch').style.display  = 'none';
    document.getElementById('step1Next').disabled        = false;
    feather.replace();
}

function clearProperty() {
    selectedProperty = null;
    document.querySelectorAll('.mw-cw-prop-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('selectedPropBanner').style.display = 'none';
    document.getElementById('propGrid').style.display           = '';
    document.getElementById('propSearch').style.display         = '';
    document.getElementById('step1Next').disabled               = true;
}

// Property search filter
document.getElementById('propSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.mw-cw-prop-card').forEach(card => {
        card.style.display = (!q || card.dataset.search.includes(q)) ? '' : 'none';
    });
});

/* ── Step navigation ────────────────────────────────────── */
function goStep(n) {
    if (n === 2 && !selectedProperty) { alert('Please select a property first.'); return; }
    if (n === 3 && serviceRows.length === 0) { alert('Please add at least one service.'); return; }

    [1,2,3].forEach(i => {
        document.getElementById('step-' + i).style.display    = (i === n) ? '' : 'none';
        const ind = document.getElementById('step-ind-' + i);
        ind.classList.toggle('active',    i <= n);
        ind.classList.toggle('completed', i < n);
    });

    if (n === 3) buildReview();
    feather.replace();
}

/* ── Service rows ───────────────────────────────────────── */
function addServiceRow(defaults = {}) {
    const id   = ++rowIdCounter;
    const type = defaults.type || 'lawn_care';
    const svc  = SERVICE_TYPES[type] || SERVICE_TYPES.lawn_care;

    const row = {
        id,
        type,
        title:    defaults.title    || svc.label,
        days:     defaults.days     || [1],  // Monday default
        recurrence:          defaults.recurrence          || 'weekly',
        recurrence_interval: defaults.recurrence_interval || 1,
        recurrence_interval_unit: defaults.recurrence_interval_unit || 'weeks',
        duration: defaults.duration || svc.duration,
        crew_id:  defaults.crew_id  || '',
        time_start: defaults.time_start || '09:00',
        time_end:   defaults.time_end   || '',
        description: defaults.description || '',
    };
    serviceRows.push(row);

    renderServiceRow(row);
    updateEmptyState();
    updateStep2Next();
    updatePreview();
}

function renderServiceRow(row) {
    const container = document.getElementById('serviceRows');
    const el        = document.createElement('div');
    el.className    = 'mw-cw-svc-row';
    el.id           = 'svc-row-' + row.id;

    // Build crew options
    const crewOptions = MW_CREW.map(c =>
        `<option value="${c.id}" ${row.crew_id == c.id ? 'selected' : ''}>${c.name}</option>`
    ).join('');

    // Build service type options
    const typeOptions = Object.entries(SERVICE_TYPES).map(([k, v]) =>
        `<option value="${k}" ${row.type === k ? 'selected' : ''}>${v.label}</option>`
    ).join('');

    // Build day buttons
    const dayBtns = DAY_LABELS.map((lbl, i) =>
        `<button type="button" class="mw-cw-day-btn ${row.days.includes(i) ? 'active' : ''}"
                data-day="${i}" onclick="toggleDay(${row.id}, ${i})">${lbl}</button>`
    ).join('');

    el.innerHTML = `
        <div class="mw-cw-svc-header">
            <select class="form-control form-control-sm mw-cw-type-sel" style="width:200px;"
                    onchange="updateServiceType(${row.id}, this.value)">${typeOptions}</select>
            <input type="text" class="form-control form-control-sm ml-2 flex-grow-1"
                   placeholder="Plan title" value="${escHtml(row.title)}"
                   oninput="updateField(${row.id}, 'title', this.value)">
            <button type="button" class="btn btn-sm btn-link text-danger ml-2"
                    onclick="removeService(${row.id})">
                <i data-feather="trash-2" style="width:14px;height:14px;"></i>
            </button>
        </div>
        <div class="mw-cw-svc-body">
            <div class="mw-cw-svc-field">
                <label class="mw-cw-field-label">Days of Week</label>
                <div class="mw-cw-days">${dayBtns}</div>
                <small class="text-muted d-block mt-1" id="dayHint-${row.id}"></small>
            </div>
            <div class="mw-cw-svc-field">
                <label class="mw-cw-field-label">Recurrence</label>
                <select class="form-control form-control-sm" onchange="updateField(${row.id}, 'recurrence', this.value)">
                    <option value="weekly"   ${row.recurrence === 'weekly'   ? 'selected' : ''}>Weekly</option>
                    <option value="biweekly" ${row.recurrence === 'biweekly' ? 'selected' : ''}>Bi-weekly</option>
                    <option value="monthly"  ${row.recurrence === 'monthly'  ? 'selected' : ''}>Monthly</option>
                </select>
            </div>
            <div class="mw-cw-svc-field">
                <label class="mw-cw-field-label">Duration (min)</label>
                <input type="number" class="form-control form-control-sm" min="15" step="15" style="width:80px;"
                       value="${row.duration}" oninput="updateField(${row.id}, 'duration', +this.value)">
            </div>
            <div class="mw-cw-svc-field">
                <label class="mw-cw-field-label">Start Time</label>
                <input type="time" class="form-control form-control-sm" style="width:100px;"
                       value="${row.time_start}" oninput="updateField(${row.id}, 'time_start', this.value)">
            </div>
            <div class="mw-cw-svc-field">
                <label class="mw-cw-field-label">Crew Lead</label>
                <select class="form-control form-control-sm" style="width:160px;"
                        onchange="updateField(${row.id}, 'crew_id', this.value)">
                    <option value="">— Unassigned —</option>
                    ${crewOptions}
                </select>
            </div>
        </div>
        <div class="mw-cw-svc-notes px-3 pb-3">
            <input type="text" class="form-control form-control-sm" placeholder="Notes (optional)"
                   value="${escHtml(row.description)}"
                   oninput="updateField(${row.id}, 'description', this.value)">
        </div>
    `;
    container.appendChild(el);
    feather.replace();
    updateDayHint(row.id);
}

function updateServiceType(rowId, type) {
    const row = serviceRows.find(r => r.id === rowId);
    if (!row) return;
    const svc = SERVICE_TYPES[type] || {};
    row.type     = type;
    row.title    = svc.label || type;
    row.duration = svc.duration || 60;
    // Update title input
    const el = document.getElementById('svc-row-' + rowId);
    if (el) {
        el.querySelector('input[type=text]').value = row.title;
        el.querySelector('input[type=number]').value = row.duration;
    }
    updatePreview();
}

function updateField(rowId, field, value) {
    const row = serviceRows.find(r => r.id === rowId);
    if (row) { row[field] = value; updatePreview(); }
}

function toggleDay(rowId, dayNum) {
    const row = serviceRows.find(r => r.id === rowId);
    if (!row) return;
    const idx = row.days.indexOf(dayNum);
    if (idx >= 0) {
        if (row.days.length === 1) return; // keep at least 1 day
        row.days.splice(idx, 1);
    } else {
        row.days.push(dayNum);
        row.days.sort((a, b) => a - b);
    }
    // Update buttons
    const el = document.getElementById('svc-row-' + rowId);
    el.querySelectorAll('.mw-cw-day-btn').forEach(btn => {
        btn.classList.toggle('active', row.days.includes(+btn.dataset.day));
    });
    updateDayHint(rowId);
    updatePreview();
}

function updateDayHint(rowId) {
    const row  = serviceRows.find(r => r.id === rowId);
    const hint = document.getElementById('dayHint-' + rowId);
    if (!hint || !row) return;
    if (row.days.length > 1) {
        hint.textContent = `Multiple days selected → ${row.days.length} plans will be created for this service.`;
    } else {
        hint.textContent = '';
    }
}

function removeService(rowId) {
    serviceRows = serviceRows.filter(r => r.id !== rowId);
    const el    = document.getElementById('svc-row-' + rowId);
    if (el) el.remove();
    updateEmptyState();
    updateStep2Next();
    updatePreview();
}

function updateEmptyState() {
    document.getElementById('emptyServices').style.display = serviceRows.length === 0 ? '' : 'none';
}

function updateStep2Next() {
    document.getElementById('step2Next').disabled = serviceRows.length === 0;
}

/* ── Preview (Step 2 bottom table) ─────────────────────── */
function updatePreview() {
    const plans  = expandToPlanList();
    const card   = document.getElementById('previewCard');
    const body   = document.getElementById('planPreviewBody');
    const count  = document.getElementById('planCount');

    if (plans.length === 0) { card.style.display = 'none'; return; }
    card.style.display = '';
    count.textContent  = plans.length;
    body.innerHTML     = plans.map(p => `
        <tr>
            <td><strong>${escHtml(p.title)}</strong></td>
            <td class="text-muted small">${escHtml(SERVICE_TYPES[p.type]?.label || p.type)}</td>
            <td>${DAY_NAMES[p.day]}</td>
            <td class="text-capitalize">${p.recurrence}</td>
            <td>${p.duration} min</td>
            <td>${escHtml(crewName(p.crew_id))}</td>
        </tr>
    `).join('');
}

/* ── Expand service rows into flat plan list ──────────── */
function expandToPlanList() {
    const plans = [];
    serviceRows.forEach((row, svcIdx) => {
        row.days.forEach(day => {
            plans.push({
                svcIdx,
                type:       row.type,
                title:      row.days.length > 1 ? row.title + ' (' + DAY_NAMES[day] + ')' : row.title,
                day,
                recurrence: row.recurrence,
                duration:   row.duration,
                crew_id:    row.crew_id,
            });
        });
    });
    return plans;
}

/* ── Review (Step 3) ──────────────────────────────────── */
function buildReview() {
    const plans        = expandToPlanList();
    const billingModel = document.getElementById('pricingModel').value;
    const rate         = parseFloat(document.getElementById('monthlyRate').value) || 0;
    const billingIdx   = parseInt(document.getElementById('billingServiceIndex').value) || 0;

    // Populate billing plan selector
    const sel = document.getElementById('billingServiceIndex');
    const prevVal = sel.value;
    sel.innerHTML = serviceRows.map((row, i) => `<option value="${i}">${escHtml(row.title)}</option>`).join('');
    if (prevVal !== '' && parseInt(prevVal) < serviceRows.length) sel.value = prevVal;

    // Show/hide per-visit label
    const monthlyGroup = document.getElementById('monthlyRateGroup');
    const billingGroup = document.getElementById('billingPlanGroup');
    monthlyGroup.querySelector('label').textContent = billingModel === 'per_visit' ? 'Price per Visit ($)' : 'Monthly Rate ($)';
    billingGroup.style.display = billingModel === 'monthly_flat' ? '' : 'none';

    updateReview();
}

function updateReview() {
    const plans      = expandToPlanList();
    const model      = document.getElementById('pricingModel').value;
    const rate       = parseFloat(document.getElementById('monthlyRate').value) || 0;
    const billingIdx = parseInt(document.getElementById('billingServiceIndex').value) || 0;
    const body       = document.getElementById('reviewBody');
    const isFlat     = model === 'monthly_flat';

    body.innerHTML = plans.map((p, i) => {
        const isMain    = isFlat && p.svcIdx === billingIdx && i === plans.findIndex(pp => pp.svcIdx === p.svcIdx);
        const billingCell = isFlat
            ? (isMain ? `<span class="badge badge-success">$${rate.toFixed(2)}/mo</span>` : '<span class="text-muted small">Included</span>')
            : `<span class="text-muted small">$${rate.toFixed(2)}/visit</span>`;
        return `
            <tr>
                <td><strong>${escHtml(p.title)}</strong>
                    <div class="text-muted small text-capitalize">${SERVICE_TYPES[p.type]?.label || p.type}</div>
                </td>
                <td>${DAY_NAMES[p.day]}</td>
                <td class="text-capitalize">${p.recurrence}</td>
                <td>${p.duration} min</td>
                <td>${billingCell}</td>
            </tr>
        `;
    }).join('');

    // Cadence summary
    const weeklyVisits = plans.filter(p => p.recurrence === 'weekly').length
                       + plans.filter(p => p.recurrence === 'biweekly').length * 0.5;
    document.getElementById('reviewPlanCount').textContent    = plans.length + ' plan' + (plans.length !== 1 ? 's' : '');
    document.getElementById('reviewVisitCadence').textContent = weeklyVisits ? `· ~${weeklyVisits}/week` : '';
    document.getElementById('reviewMonthlyTotal').textContent = '$' + rate.toFixed(2);
}

/* ── Submit ───────────────────────────────────────────── */
function submitContract() {
    if (!selectedProperty) { alert('No property selected.'); return; }
    if (serviceRows.length === 0) { alert('No services defined.'); return; }

    const startDate = document.getElementById('startDate').value;
    if (!startDate) { alert('Please set a start date.'); return; }

    const payload = {
        property_id:           selectedProperty.id,
        start_date:            startDate,
        end_date:              document.getElementById('endDate').value || null,
        horizon_days:          parseInt(document.getElementById('horizonDays').value) || 90,
        pricing_model:         document.getElementById('pricingModel').value,
        monthly_rate:          parseFloat(document.getElementById('monthlyRate').value) || 0,
        billing_service_index: parseInt(document.getElementById('billingServiceIndex').value) || 0,
        services:              serviceRows.map(row => ({
            type:                    row.type,
            title:                   row.title,
            days:                    row.days,
            recurrence:              row.recurrence,
            recurrence_interval:     row.recurrence_interval || 1,
            recurrence_interval_unit: row.recurrence_interval_unit || 'weeks',
            duration:                row.duration,
            crew_id:                 row.crew_id || null,
            time_start:              row.time_start || null,
            time_end:                row.time_end   || null,
            description:             row.description || '',
        })),
    };

    document.getElementById('contractDataInput').value = JSON.stringify(payload);

    const btn = document.getElementById('createBtn');
    btn.disabled     = true;
    btn.innerHTML    = '<span class="spinner-border spinner-border-sm mr-1"></span> Creating…';
    document.getElementById('contractForm').submit();
}

/* ── Helpers ──────────────────────────────────────────── */
function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function crewName(id) {
    const c = MW_CREW.find(m => m.id == id);
    return c ? c.name : '—';
}

/* ── Init: add a default row for new page loads ─────── */
document.addEventListener('DOMContentLoaded', function() {
    // pricingModel change
    document.getElementById('pricingModel')?.addEventListener('change', function() {
        const isFlat = this.value === 'monthly_flat';
        document.getElementById('billingPlanGroup').style.display = isFlat ? '' : 'none';
        updateReview();
    });
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
