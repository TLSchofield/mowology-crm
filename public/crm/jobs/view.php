<?php
/**
 * Job Plan View & Management
 * Shows plan details, stats, visits list, and notes.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$planId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$planId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// ── POST Handlers ────────────────────────────────────────────────────

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Pause plan
    if ($action === 'pause_plan') {
        $reason = trim($_POST['pause_reason'] ?? '');
        if (pausePlan($planId, $user['id'], $reason)) {
            $message = 'Plan paused. Future scheduled visits have been cancelled.';
            $messageType = 'success';
        } else {
            $message = 'Could not pause this plan. It may already be paused.';
            $messageType = 'error';
        }
    }

    // Resume plan
    if ($action === 'resume_plan') {
        if (resumePlan($planId, $user['id'])) {
            $message = 'Plan resumed. Visits have been regenerated.';
            $messageType = 'success';
        } else {
            $message = 'Could not resume this plan. It may already be active.';
            $messageType = 'error';
        }
    }

    // Start visit
    if ($action === 'start_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        if ($visitId && updateVisitStatus($visitId, 'in_progress', $user['id'])) {
            header("Location: view.php?id={$planId}&visit_started=1");
            exit;
        }
        $message = 'Could not start visit.';
        $messageType = 'error';
    }

    // Complete visit
    if ($action === 'complete_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        $notes = trim($_POST['completion_notes'] ?? '');
        $actualAmount = isset($_POST['actual_amount']) && $_POST['actual_amount'] !== ''
            ? floatval($_POST['actual_amount'])
            : null;

        if ($visitId && updateVisitStatus($visitId, 'completed', $user['id'], $notes ?: null)) {
            // Update actual_amount if provided
            if ($actualAmount !== null) {
                $stmt = $db->prepare("UPDATE job_visits SET actual_amount = ? WHERE id = ?");
                $stmt->execute([$actualAmount, $visitId]);
            }
            header("Location: view.php?id={$planId}&visit_completed=1");
            exit;
        }
        $message = 'Could not complete visit.';
        $messageType = 'error';
    }

    // Skip visit
    if ($action === 'skip_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        $reason = trim($_POST['skip_reason'] ?? '');
        if ($visitId && updateVisitStatus($visitId, 'skipped', $user['id'], $reason ?: null)) {
            header("Location: view.php?id={$planId}&visit_skipped=1");
            exit;
        }
        $message = 'Could not skip visit.';
        $messageType = 'error';
    }

    // Weather visit
    if ($action === 'weather_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        $reason = trim($_POST['weather_reason'] ?? '');
        if ($visitId && updateVisitStatus($visitId, 'weather', $user['id'], $reason ?: null)) {
            header("Location: view.php?id={$planId}&visit_weather=1");
            exit;
        }
        $message = 'Could not mark visit as weather delay.';
        $messageType = 'error';
    }

    // Update tracking overrides
    if ($action === 'update_tracking') {
        $trackingLevel = $_POST['tracking_level_override'] ?? 'inherit';
        $autoClockIn = $_POST['auto_clock_in_override'] ?? 'inherit';
        $clockIn = $_POST['require_clock_in_override'] ?? 'inherit';
        $gps = $_POST['require_gps_override'] ?? 'inherit';
        $photos = $_POST['require_photos_override'] ?? 'inherit';

        $validLevels = ['standard', 'heightened', 'custom'];

        $stmt = $db->prepare("
            UPDATE job_plans SET
                tracking_level_override = ?,
                auto_clock_in_override = ?,
                require_clock_in_override = ?,
                require_gps_override = ?,
                require_photos_override = ?
            WHERE id = ?
        ");
        $stmt->execute([
            ($trackingLevel !== 'inherit' && in_array($trackingLevel, $validLevels)) ? $trackingLevel : null,
            $autoClockIn !== 'inherit' ? ($autoClockIn === '1' ? 1 : 0) : null,
            $clockIn !== 'inherit' ? ($clockIn === '1' ? 1 : 0) : null,
            $gps !== 'inherit' ? ($gps === '1' ? 1 : 0) : null,
            $photos !== 'inherit' ? ($photos === '1' ? 1 : 0) : null,
            $planId
        ]);
        header("Location: view.php?id={$planId}&tracking_updated=1");
        exit;
    }

    // Add plan note
    if ($action === 'add_note') {
        $noteContent = trim($_POST['note_content'] ?? '');
        $noteType = $_POST['note_type'] ?? 'general';

        if ($noteContent) {
            $stmt = $db->prepare("
                INSERT INTO plan_notes (plan_id, note_type, content, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$planId, $noteType, $noteContent, $user['id']]);
            header("Location: view.php?id={$planId}&note_added=1");
            exit;
        }
        $message = 'Note content is required.';
        $messageType = 'error';
    }
}

// ── Load Plan Data ───────────────────────────────────────────────────

$plan = getPlanDetails($planId);

if (!$plan) {
    header('Location: index.php');
    exit;
}

// Profitability data
$profitability = getPlanProfitability($planId);

// Get visits
$visits = getPlanVisits($planId, null, 200, 0);

// Get plan line items
$planLineItems = getPlanLineItems($planId);

// Get tracking requirements (resolved: plan overrides > product defaults)
$trackingReqs = resolveTrackingRequirementsForPlan($planId);

// Get plan notes
$stmt = $db->prepare("
    SELECT pn.*, u.full_name
    FROM plan_notes pn
    LEFT JOIN users u ON pn.created_by = u.id
    WHERE pn.plan_id = ?
    ORDER BY pn.created_at DESC
");
$stmt->execute([$planId]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get staff for crew dropdown
$staff = getStaffMembers();

// ── Flash messages from redirects ────────────────────────────────────

$csrfToken = generateCSRFToken();

if (isset($_GET['created'])) { $message = 'Plan created successfully!'; $messageType = 'success'; }
if (isset($_GET['visit_started'])) { $message = 'Visit started!'; $messageType = 'success'; }
if (isset($_GET['visit_completed'])) { $message = 'Visit completed!'; $messageType = 'success'; }
if (isset($_GET['visit_skipped'])) { $message = 'Visit skipped.'; $messageType = 'success'; }
if (isset($_GET['visit_weather'])) { $message = 'Visit marked as weather delay.'; $messageType = 'success'; }
if (isset($_GET['note_added'])) { $message = 'Note added!'; $messageType = 'success'; }

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Build a human-readable recurrence description.
 */
function describeRecurrence(array $plan): string {
    if (!$plan['is_recurring']) return 'One-time';

    $pattern = $plan['recurrence_pattern'] ?? 'weekly';
    $interval = (int)($plan['recurrence_interval'] ?? 1);
    $unit = $plan['recurrence_interval_unit'] ?? 'weeks';

    switch ($pattern) {
        case 'weekly':  return 'Weekly';
        case 'biweekly': return 'Every 2 weeks';
        case 'monthly': return 'Monthly';
        case 'custom':
            $unitLabel = rtrim($unit, 's');
            if ($interval === 1) return 'Every ' . $unitLabel;
            return "Every {$interval} {$unit}";
        default:
            return ucfirst(str_replace('_', ' ', $pattern));
    }
}

/**
 * Separate visits into upcoming and past.
 */
function splitVisits(array $visits): array {
    $today = date('Y-m-d');
    $upcoming = [];
    $past = [];

    foreach ($visits as $v) {
        if ($v['scheduled_date'] >= $today && in_array($v['status'], ['scheduled', 'in_progress'])) {
            $upcoming[] = $v;
        } else {
            $past[] = $v;
        }
    }

    // Sort upcoming by date ASC
    usort($upcoming, function ($a, $b) {
        return strcmp($a['scheduled_date'], $b['scheduled_date']);
    });

    // Past stays DESC (already from query)
    return ['upcoming' => $upcoming, 'past' => $past];
}

$splitVisits = splitVisits($visits);

// ── Page Setup ───────────────────────────────────────────────────────

$pageTitle = 'Plan ' . htmlspecialchars($plan['plan_number']);
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <a href="index.php" class="mw-back-link">&larr; Back to Plans</a>

            <?php if ($message): ?>
                <div class="mw-message <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 1: Plan Header + Details
                 ══════════════════════════════════════════════════════ -->

            <div class="mw-page-header">
                <div>
                    <h1 class="h3 mb-0"><?php echo htmlspecialchars($plan['plan_number']); ?></h1>
                    <div class="mt-2">
                        <?php echo getStatusBadge($plan['status'], 'plan'); ?>
                        <span class="mw-badge-status" style="background: var(--mw-light); color: var(--mw-dark);">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['service_type']))); ?>
                        </span>
                        <?php if ($plan['is_recurring']): ?>
                            <span class="mw-badge-status" style="background: var(--mw-lime); color: #000;">
                                Recurring
                            </span>
                        <?php else: ?>
                            <span class="mw-badge-status" style="background: #E5E7EB; color: #374151;">
                                One-time
                            </span>
                        <?php endif; ?>
                        <span class="ml-3 text-muted">
                            <?php echo htmlspecialchars($plan['title'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <div class="mw-header-actions">
                    <?php if ($plan['status'] === 'active'): ?>
                        <button type="button" class="btn btn-warning" onclick="showModal('pauseModal')">
                            Pause Plan
                        </button>
                    <?php elseif ($plan['status'] === 'paused'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" name="action" value="resume_plan" class="btn btn-success">
                                Resume Plan
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($plan['quote_id']): ?>
                        <a href="../quotes/view.php?id=<?php echo (int)$plan['quote_id']; ?>" class="btn btn-secondary">
                            View Quote <?php echo htmlspecialchars($plan['quote_number'] ?? ''); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 2: Plan Stats Row
                 ══════════════════════════════════════════════════════ -->

            <div class="row mb-4">
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Visits Completed</div>
                            <div class="mw-stat-value" style="color: var(--mw-green);">
                                <?php echo $plan['visits_completed']; ?>
                                <small class="text-muted">/ <?php echo $plan['total_visits']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Visits Scheduled</div>
                            <div class="mw-stat-value" style="color: #3B82F6;">
                                <?php echo $plan['visits_scheduled']; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Total Revenue</div>
                            <div class="mw-stat-value">
                                <?php echo formatCurrency($plan['total_revenue']); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Next Visit</div>
                            <div class="mw-stat-value" style="font-size: 1.1rem;">
                                <?php echo $plan['next_visit_date'] ? formatDate($plan['next_visit_date']) : 'None'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 2b: Profitability Dashboard
                 ══════════════════════════════════════════════════════ -->

            <?php if ($profitability['has_data']): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Profitability
                        <?php if ($profitability['labor_estimated']): ?>
                            <span class="badge badge-warning ml-2" title="Labor cost estimated from plan duration, not actual time tracking">Estimated</span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- LEFT: SVG Gauge -->
                        <div class="col-lg-5 text-center">
                            <div class="mw-gauge-container">
                                <svg viewBox="0 0 200 130" class="mw-gauge-svg" data-margin="<?php echo $profitability['margin_pct']; ?>">
                                    <defs>
                                        <linearGradient id="gaugeGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                            <stop offset="0%" stop-color="#DC2626"/>
                                            <stop offset="35%" stop-color="#F59E0B"/>
                                            <stop offset="65%" stop-color="#2D8659"/>
                                            <stop offset="100%" stop-color="#16A34A"/>
                                        </linearGradient>
                                    </defs>
                                    <!-- Background track -->
                                    <path d="M 20 105 A 80 80 0 0 1 180 105" fill="none" stroke="#E5E7EB" stroke-width="14" stroke-linecap="round"/>
                                    <!-- Colored fill arc (animated via JS) -->
                                    <path d="M 20 105 A 80 80 0 0 1 180 105" fill="none" stroke="url(#gaugeGradient)" stroke-width="14" stroke-linecap="round" class="mw-gauge-arc"/>
                                    <!-- Tick marks -->
                                    <line x1="20" y1="105" x2="20" y2="115" stroke="#D1D5DB" stroke-width="1"/>
                                    <line x1="60" y1="38" x2="57" y2="48" stroke="#D1D5DB" stroke-width="1"/>
                                    <line x1="100" y1="25" x2="100" y2="35" stroke="#D1D5DB" stroke-width="1"/>
                                    <line x1="140" y1="38" x2="143" y2="48" stroke="#D1D5DB" stroke-width="1"/>
                                    <line x1="180" y1="105" x2="180" y2="115" stroke="#D1D5DB" stroke-width="1"/>
                                    <!-- Needle -->
                                    <line x1="100" y1="105" x2="100" y2="35" stroke="#1F2937" stroke-width="2.5" stroke-linecap="round" class="mw-gauge-needle"/>
                                    <!-- Center dot -->
                                    <circle cx="100" cy="105" r="5" fill="#1F2937"/>
                                    <circle cx="100" cy="105" r="2.5" fill="#fff"/>
                                    <!-- Scale labels -->
                                    <text x="15" y="125" font-size="8" fill="#9CA3AF" text-anchor="middle">0%</text>
                                    <text x="60" y="52" font-size="8" fill="#9CA3AF" text-anchor="middle">25%</text>
                                    <text x="100" y="18" font-size="8" fill="#9CA3AF" text-anchor="middle">50%</text>
                                    <text x="140" y="52" font-size="8" fill="#9CA3AF" text-anchor="middle">75%</text>
                                    <text x="185" y="125" font-size="8" fill="#9CA3AF" text-anchor="middle">100%</text>
                                </svg>
                                <div class="mw-gauge-value">
                                    <span class="mw-gauge-number"><?php echo $profitability['margin_pct']; ?></span>
                                    <span class="mw-gauge-unit">%</span>
                                </div>
                                <div class="mw-gauge-label">Profit Margin</div>
                                <div class="mw-gauge-sublabel">
                                    <?php if ($profitability['margin_pct'] >= 40): ?>
                                        <span style="color: var(--mw-green);">Exceeding Target</span>
                                    <?php elseif ($profitability['margin_pct'] >= 20): ?>
                                        <span style="color: #F59E0B;">On Target</span>
                                    <?php elseif ($profitability['margin_pct'] >= 0): ?>
                                        <span style="color: #DC2626;">Below Target</span>
                                    <?php else: ?>
                                        <span style="color: #DC2626;">Losing Money</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT: Cost Breakdown -->
                        <div class="col-lg-7">
                            <div class="mw-profit-breakdown">
                                <?php
                                $breakdownItems = [
                                    ['label' => 'Revenue',  'amount' => $profitability['revenue'],      'color' => 'var(--mw-green)', 'icon' => 'dollar-sign', 'positive' => true],
                                    ['label' => 'Labor',    'amount' => $profitability['labor_cost'],    'color' => '#3B82F6',         'icon' => 'clock',        'positive' => false],
                                    ['label' => 'Expenses', 'amount' => $profitability['expense_cost'],  'color' => '#F59E0B',         'icon' => 'shopping-cart', 'positive' => false],
                                    ['label' => 'Overhead', 'amount' => $profitability['overhead_cost'], 'color' => '#8B5CF6',         'icon' => 'briefcase',    'positive' => false],
                                ];
                                $maxAmount = max(1, max(array_column($breakdownItems, 'amount')));
                                ?>

                                <?php foreach ($breakdownItems as $item): ?>
                                <div class="mw-profit-row">
                                    <div class="mw-profit-row-label">
                                        <i data-feather="<?php echo $item['icon']; ?>" style="width:14px;height:14px;color:<?php echo $item['color']; ?>"></i>
                                        <span><?php echo $item['label']; ?></span>
                                    </div>
                                    <div class="mw-profit-row-bar">
                                        <div class="mw-profit-row-fill" style="width: <?php echo round(($item['amount'] / $maxAmount) * 100, 1); ?>%; background: <?php echo $item['color']; ?>"></div>
                                    </div>
                                    <div class="mw-profit-row-amount">
                                        <?php echo formatCurrency($item['amount']); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <hr class="my-2">

                                <div class="mw-profit-row mw-profit-row-total">
                                    <div class="mw-profit-row-label">
                                        <i data-feather="<?php echo $profitability['profit'] >= 0 ? 'trending-up' : 'trending-down'; ?>" style="width:14px;height:14px;color:<?php echo $profitability['profit'] >= 0 ? 'var(--mw-green)' : '#DC2626'; ?>"></i>
                                        <strong>Net Profit</strong>
                                    </div>
                                    <div class="mw-profit-row-bar"></div>
                                    <div class="mw-profit-row-amount" style="color: <?php echo $profitability['profit'] >= 0 ? 'var(--mw-green)' : '#DC2626'; ?>">
                                        <strong><?php echo formatCurrency($profitability['profit']); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 text-muted small">
                                <?php if ($profitability['labor_estimated']): ?>
                                    <i data-feather="info" style="width:12px;height:12px;"></i>
                                    Labor estimated from plan duration (<?php echo (int)$plan['estimated_duration_minutes']; ?> min/visit &times; <?php echo $profitability['completed_visits']; ?> visits). Use job timers for actual tracking.
                                <?php else: ?>
                                    <i data-feather="check-circle" style="width:12px;height:12px;color:var(--mw-green);"></i>
                                    Based on <?php echo round($profitability['labor_minutes']); ?> min tracked across <?php echo $profitability['completed_visits']; ?> completed visit<?php echo $profitability['completed_visits'] !== 1 ? 's' : ''; ?>.
                                <?php endif; ?>
                                <?php if ($profitability['expense_cost'] > 0): ?>
                                    <br><i data-feather="alert-circle" style="width:12px;height:12px;color:#F59E0B;"></i>
                                    Expenses shown are for the entire property, not specific to this plan.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card mb-4">
                <div class="card-body text-center text-muted py-4">
                    <i data-feather="bar-chart-2" style="width:24px;height:24px;"></i>
                    <p class="mb-0 mt-2">Profitability data will appear after visits are completed.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 Plan Details (Two-Column Grid)
                 ══════════════════════════════════════════════════════ -->

            <div class="row mb-4">
                <!-- Left Column: Property, Client, Service, Pricing -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Plan Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Property</span>
                                <span class="mw-detail-value">
                                    <?php
                                        $addr = trim(($plan['property_address'] ?? '') . ', ' . ($plan['property_city'] ?? ''), ', ');
                                        echo htmlspecialchars($addr ?: 'N/A');
                                    ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Client</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars($plan['company_name'] ?? 'N/A'); ?>
                                </span>
                            </div>
                            <?php
                                $contactName = trim(($plan['first_name'] ?? '') . ' ' . ($plan['last_name'] ?? ''));
                                if ($contactName):
                            ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Contact</span>
                                    <span class="mw-detail-value">
                                        <?php echo htmlspecialchars($contactName); ?>
                                        <?php if (!empty($plan['contact_phone'])): ?>
                                            &mdash;
                                            <a href="tel:<?php echo htmlspecialchars($plan['contact_phone']); ?>">
                                                <?php echo htmlspecialchars($plan['contact_phone']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Service Type</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['service_type']))); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Pricing Model</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['pricing_model'] ?? 'per_visit'))); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Price / Visit</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['price_per_visit'] ? formatCurrency($plan['price_per_visit']) : 'N/A'; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Duration</span>
                                <span class="mw-detail-value">
                                    <?php echo (int)$plan['estimated_duration_minutes']; ?> min
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Default Crew</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars($plan['default_crew_name'] ?? 'Unassigned'); ?>
                                </span>
                            </div>
                            <?php if ($plan['description']): ?>
                                <div class="mt-3">
                                    <span class="mw-detail-label">Description</span>
                                    <p class="mt-1" style="white-space: pre-line;"><?php echo htmlspecialchars($plan['description']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Schedule, Recurrence, Dates, Audit -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Schedule & Recurrence</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Plan Type</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['is_recurring'] ? 'Recurring' : 'One-time'; ?>
                                </span>
                            </div>
                            <?php if ($plan['is_recurring']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Recurrence</span>
                                    <span class="mw-detail-value">
                                        <?php echo htmlspecialchars(describeRecurrence($plan)); ?>
                                        <?php if ($plan['recurrence_day_of_week'] !== null): ?>
                                            <?php
                                                $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                                $dow = (int)$plan['recurrence_day_of_week'];
                                                if (isset($days[$dow])) echo ' (' . $days[$dow] . ')';
                                            ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Start Date</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['plan_start_date'] ? formatDate($plan['plan_start_date']) : 'Not set'; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">End Date</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['plan_end_date'] ? formatDate($plan['plan_end_date']) : 'Ongoing'; ?>
                                </span>
                            </div>
                            <?php if ($plan['default_time_start']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Default Time</span>
                                    <span class="mw-detail-value">
                                        <?php echo date('g:i A', strtotime($plan['default_time_start'])); ?>
                                        <?php if ($plan['default_time_end']): ?>
                                            - <?php echo date('g:i A', strtotime($plan['default_time_end'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Horizon</span>
                                <span class="mw-detail-value">
                                    <?php echo (int)$plan['horizon_days']; ?> days ahead
                                </span>
                            </div>
                            <?php if ($plan['status'] === 'paused' && $plan['paused_reason']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Paused Reason</span>
                                    <span class="mw-detail-value text-warning">
                                        <?php echo htmlspecialchars($plan['paused_reason']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <hr class="my-3">

                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Created By</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars($plan['created_by_name'] ?? 'Unknown'); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Created At</span>
                                <span class="mw-detail-value">
                                    <?php echo formatDateTime($plan['created_at']); ?>
                                </span>
                            </div>
                            <?php if ($plan['quote_number']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">From Quote</span>
                                    <span class="mw-detail-value">
                                        <a href="../quotes/view.php?id=<?php echo (int)$plan['quote_id']; ?>">
                                            <?php echo htmlspecialchars($plan['quote_number']); ?>
                                        </a>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Tracking & Compliance -->
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Tracking &amp; Compliance</h5>
                            <span class="badge badge-<?php echo $trackingReqs['tracking_level'] === 'heightened' ? 'warning' : ($trackingReqs['tracking_level'] === 'custom' ? 'info' : 'secondary'); ?>">
                                <?php echo ucfirst(htmlspecialchars($trackingReqs['tracking_level'])); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <?php
                            $flags = [
                                ['key' => 'auto_clock_in', 'label' => 'Auto Clock-In', 'icon' => 'zap', 'override_key' => 'auto_clock_in'],
                                ['key' => 'require_clock_in', 'label' => 'Clock-In Required', 'icon' => 'clock', 'override_key' => 'clock_in'],
                                ['key' => 'require_gps', 'label' => 'GPS Required', 'icon' => 'map-pin', 'override_key' => 'gps'],
                                ['key' => 'require_photos', 'label' => 'Photos Required', 'icon' => 'camera', 'override_key' => 'photos'],
                            ];
                            foreach ($flags as $flag):
                                $active = $trackingReqs[$flag['key']];
                                $source = $trackingReqs['source'][$flag['override_key']];
                            ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">
                                    <i data-feather="<?php echo $flag['icon']; ?>" style="width:14px;height:14px;margin-right:4px;"></i>
                                    <?php echo $flag['label']; ?>
                                </span>
                                <span class="mw-detail-value">
                                    <?php if ($active): ?>
                                        <span class="badge badge-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No</span>
                                    <?php endif; ?>
                                    <?php if ($source === 'plan'): ?>
                                        <span class="mw-tracking-override-badge">Override</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>

                            <hr class="my-3">

                            <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#trackingOverrideForm">
                                Edit Overrides
                            </button>

                            <div class="collapse mt-3" id="trackingOverrideForm">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="update_tracking">

                                    <?php
                                    // Current raw override values from the plan
                                    $rawOverrides = [
                                        'tracking_level_override' => $plan['tracking_level_override'] ?? null,
                                        'auto_clock_in_override' => $plan['auto_clock_in_override'] ?? null,
                                        'require_clock_in_override' => $plan['require_clock_in_override'] ?? null,
                                        'require_gps_override' => $plan['require_gps_override'] ?? null,
                                        'require_photos_override' => $plan['require_photos_override'] ?? null,
                                    ];
                                    ?>

                                    <div class="form-group">
                                        <label>Tracking Level</label>
                                        <select class="form-control form-control-sm" name="tracking_level_override">
                                            <option value="inherit" <?php echo $rawOverrides['tracking_level_override'] === null ? 'selected' : ''; ?>>Inherit from product</option>
                                            <option value="standard" <?php echo $rawOverrides['tracking_level_override'] === 'standard' ? 'selected' : ''; ?>>Standard</option>
                                            <option value="heightened" <?php echo $rawOverrides['tracking_level_override'] === 'heightened' ? 'selected' : ''; ?>>Heightened</option>
                                            <option value="custom" <?php echo $rawOverrides['tracking_level_override'] === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                        </select>
                                    </div>

                                    <?php $aciVal = $rawOverrides['auto_clock_in_override']; ?>
                                    <div class="form-group">
                                        <label>Auto Clock-In <small class="text-muted">(fixed-price / maintenance)</small></label>
                                        <select class="form-control form-control-sm" name="auto_clock_in_override">
                                            <option value="inherit" <?php echo $aciVal === null ? 'selected' : ''; ?>>Inherit from product</option>
                                            <option value="1" <?php echo $aciVal !== null && (int)$aciVal === 1 ? 'selected' : ''; ?>>Enabled</option>
                                            <option value="0" <?php echo $aciVal !== null && (int)$aciVal === 0 ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                    </div>

                                    <?php
                                    $overrideFlags = [
                                        ['name' => 'require_clock_in_override', 'label' => 'Clock-In'],
                                        ['name' => 'require_gps_override', 'label' => 'GPS'],
                                        ['name' => 'require_photos_override', 'label' => 'Photos'],
                                    ];
                                    foreach ($overrideFlags as $of):
                                        $val = $rawOverrides[$of['name']];
                                    ?>
                                    <div class="form-group">
                                        <label><?php echo $of['label']; ?></label>
                                        <select class="form-control form-control-sm" name="<?php echo $of['name']; ?>">
                                            <option value="inherit" <?php echo $val === null ? 'selected' : ''; ?>>Inherit from product</option>
                                            <option value="1" <?php echo $val !== null && (int)$val === 1 ? 'selected' : ''; ?>>Required</option>
                                            <option value="0" <?php echo $val !== null && (int)$val === 0 ? 'selected' : ''; ?>>Not required</option>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>

                                    <button type="submit" class="btn btn-sm btn-primary">Save Overrides</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Services Included (Plan Line Items)
                 ══════════════════════════════════════════════════════ -->
            <?php if (!empty($planLineItems)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Services Included</h5>
                </div>
                <div class="card-body p-0">
                    <table class="mw-plan-items-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Description</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $itemsSubtotal = 0; ?>
                            <?php foreach ($planLineItems as $pli): ?>
                                <?php $itemsSubtotal += floatval($pli['line_total']); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pli['service_type']); ?></td>
                                    <td class="text-muted"><?php echo htmlspecialchars($pli['description'] ?: '-'); ?></td>
                                    <td class="text-right"><?php echo number_format(floatval($pli['quantity']), ($pli['quantity'] == intval($pli['quantity'])) ? 0 : 2); ?></td>
                                    <td class="text-right"><?php echo formatCurrency($pli['unit_price']); ?></td>
                                    <td class="text-right"><?php echo formatCurrency($pli['line_total']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right">Per Visit Total</td>
                                <td class="text-right"><?php echo formatCurrency($itemsSubtotal); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if ($plan['quote_id']): ?>
                    <div class="card-footer text-muted small">
                        From quote <a href="../quotes/view.php?id=<?php echo (int)$plan['quote_id']; ?>"><?php echo htmlspecialchars($plan['quote_number'] ?? ''); ?></a>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 3: Visits List
                 ══════════════════════════════════════════════════════ -->

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Visits
                        <small class="text-muted">(<?php echo count($visits); ?> total)</small>
                    </h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mw-visit-filter active" data-filter="all">All</button>
                        <button type="button" class="btn btn-sm btn-outline-primary mw-visit-filter" data-filter="upcoming">Upcoming</button>
                        <button type="button" class="btn btn-sm btn-outline-success mw-visit-filter" data-filter="completed">Completed</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($visits)): ?>
                        <div class="p-4 text-center text-muted">
                            <p>No visits generated yet.</p>
                            <?php if ($plan['status'] === 'active'): ?>
                                <p class="small">Visits are generated automatically based on the recurrence pattern and horizon.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="visitsTable">
                                <thead>
                                    <tr>
                                        <th>Visit #</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Crew</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($visits as $visit):
                                        $isUpcoming = ($visit['scheduled_date'] >= date('Y-m-d') && in_array($visit['status'], ['scheduled', 'in_progress']));
                                        $isCompleted = ($visit['status'] === 'completed');
                                        $rowClass = $isUpcoming ? 'mw-visit-upcoming' : ($isCompleted ? 'mw-visit-completed' : 'mw-visit-other');
                                    ?>
                                        <tr class="<?php echo $rowClass; ?>" data-visit-status="<?php echo htmlspecialchars($visit['status']); ?>">
                                            <td>
                                                <span class="font-weight-bold">
                                                    <?php echo htmlspecialchars($visit['visit_number']); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted">#<?php echo (int)$visit['sequence_index']; ?></small>
                                            </td>
                                            <td>
                                                <?php echo formatDate($visit['scheduled_date']); ?>
                                                <?php if ($visit['scheduled_date'] === date('Y-m-d')): ?>
                                                    <span class="mw-badge-status" style="background: var(--mw-orange); color: #fff; font-size: 0.65rem; padding: 2px 6px;">TODAY</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($visit['scheduled_time_start']): ?>
                                                    <?php echo date('g:i A', strtotime($visit['scheduled_time_start'])); ?>
                                                    <?php if ($visit['scheduled_time_end']): ?>
                                                        <br><small class="text-muted">to <?php echo date('g:i A', strtotime($visit['scheduled_time_end'])); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($visit['crew_name'] ?? 'Unassigned'); ?>
                                            </td>
                                            <td>
                                                <?php echo getStatusBadge($visit['status'], 'visit'); ?>
                                                <?php if ($visit['photo_count'] > 0): ?>
                                                    <small class="text-muted ml-1" title="<?php echo (int)$visit['photo_count']; ?> photos">
                                                        <i data-feather="camera" style="width: 12px; height: 12px;"></i>
                                                        <?php echo (int)$visit['photo_count']; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($visit['note_count'] > 0): ?>
                                                    <small class="text-muted ml-1" title="<?php echo (int)$visit['note_count']; ?> notes">
                                                        <i data-feather="message-square" style="width: 12px; height: 12px;"></i>
                                                        <?php echo (int)$visit['note_count']; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($visit['actual_amount']): ?>
                                                    <?php echo formatCurrency($visit['actual_amount']); ?>
                                                <?php elseif ($plan['price_per_visit']): ?>
                                                    <span class="text-muted"><?php echo formatCurrency($plan['price_per_visit']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right">
                                                <?php if ($visit['status'] === 'scheduled'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="visit_id" value="<?php echo (int)$visit['id']; ?>">
                                                        <button type="submit" name="action" value="start_visit"
                                                                class="btn btn-sm btn-info" title="Start this visit">
                                                            Start
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                                            onclick="openSkipModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Skip this visit">
                                                        Skip
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info"
                                                            onclick="openWeatherModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Weather delay">
                                                        <i data-feather="cloud-rain" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                <?php elseif ($visit['status'] === 'in_progress'): ?>
                                                    <button type="button" class="btn btn-sm btn-success"
                                                            onclick="openCompleteModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>', <?php echo floatval($plan['price_per_visit'] ?? 0); ?>)"
                                                            title="Complete this visit">
                                                        Complete
                                                    </button>
                                                <?php elseif ($visit['status'] === 'completed'): ?>
                                                    <span class="text-muted small">
                                                        <?php echo $visit['completed_at'] ? formatDateTime($visit['completed_at']) : ''; ?>
                                                    </span>
                                                <?php elseif ($visit['status'] === 'skipped'): ?>
                                                    <span class="text-muted small">Skipped</span>
                                                <?php elseif ($visit['status'] === 'weather'): ?>
                                                    <span class="text-muted small">Weather</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if ($visit['completion_notes']): ?>
                                            <tr class="<?php echo $rowClass; ?> mw-visit-note-row">
                                                <td></td>
                                                <td colspan="6">
                                                    <small class="text-muted">
                                                        <i data-feather="message-circle" style="width: 12px; height: 12px;"></i>
                                                        <?php echo htmlspecialchars($visit['completion_notes']); ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Plan Notes Section
                 ══════════════════════════════════════════════════════ -->

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Plan Notes</h5>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="showModal('noteModal')">
                        + Add Note
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($notes)): ?>
                        <p class="text-muted small mb-0">No notes yet. Add instructions, customer requests, or internal notes.</p>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="mw-note-item">
                                <div class="mw-note-header">
                                    <span class="mw-note-type"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $note['note_type']))); ?></span>
                                    <span>
                                        <?php echo htmlspecialchars($note['full_name'] ?? 'System'); ?>
                                        &mdash;
                                        <?php echo formatDateTime($note['created_at']); ?>
                                    </span>
                                </div>
                                <div class="mw-note-content">
                                    <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>


    <!-- ══════════════════════════════════════════════════════
         MODALS
         ══════════════════════════════════════════════════════ -->

    <!-- Pause Plan Modal -->
    <div class="mw-modal-overlay" id="pauseModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Pause Plan</h3>
            <p class="text-muted small">Pausing will cancel all future scheduled visits. You can resume at any time.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="pause_plan">

                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <textarea name="pause_reason" class="form-control" rows="2"
                              placeholder="Why is this plan being paused?"></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-warning">Pause Plan</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('pauseModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Complete Visit Modal -->
    <div class="mw-modal-overlay" id="completeModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Complete Visit <span id="completeVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="complete_visit">
                <input type="hidden" name="visit_id" id="completeVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Actual Amount ($)</label>
                    <input type="number" name="actual_amount" id="completeActualAmount" class="form-control" step="0.01" min="0">
                    <small class="form-text text-muted">Leave blank to use the plan's default price per visit.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Completion Notes</label>
                    <textarea name="completion_notes" class="form-control" rows="3"
                              placeholder="Any notes about the completed work..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-success">Complete Visit</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('completeModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Skip Visit Modal -->
    <div class="mw-modal-overlay" id="skipModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Skip Visit <span id="skipVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="skip_visit">
                <input type="hidden" name="visit_id" id="skipVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <textarea name="skip_reason" class="form-control" rows="2"
                              placeholder="Why is this visit being skipped?"></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-secondary">Skip Visit</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="hideModal('skipModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Weather Visit Modal -->
    <div class="mw-modal-overlay" id="weatherModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Weather Delay <span id="weatherVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="weather_visit">
                <input type="hidden" name="visit_id" id="weatherVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Details (optional)</label>
                    <textarea name="weather_reason" class="form-control" rows="2"
                              placeholder="e.g., Heavy rain, snow storm..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-info">Mark Weather Delay</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('weatherModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div class="mw-modal-overlay" id="noteModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Add Plan Note</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_note">

                <div class="form-group">
                    <label class="form-label">Note Type</label>
                    <select name="note_type" class="form-control">
                        <option value="general">General</option>
                        <option value="customer_request">Customer Request</option>
                        <option value="issue">Issue</option>
                        <option value="follow_up">Follow-up</option>
                        <option value="internal">Internal</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="note_content" class="form-control" required rows="4"
                              placeholder="Enter note..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Add Note</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('noteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>


    <!-- ══════════════════════════════════════════════════════
         JAVASCRIPT
         ══════════════════════════════════════════════════════ -->
    <script>
        // ── Profitability Gauge Animation ────────────────────
        (function() {
            var gauge = document.querySelector('.mw-gauge-svg');
            if (!gauge) return;

            var margin = parseFloat(gauge.getAttribute('data-margin')) || 0;
            var arc = gauge.querySelector('.mw-gauge-arc');
            var needle = gauge.querySelector('.mw-gauge-needle');
            if (!arc || !needle) return;

            // Arc total length for a semi-circle with radius 80
            var totalLength = Math.PI * 80; // ~251.3

            // Set initial state
            arc.style.strokeDasharray = totalLength;
            arc.style.strokeDashoffset = totalLength;

            // Clamp to 0-100 range for display
            var clamped = Math.max(0, Math.min(100, margin));
            var normalized = clamped / 100;

            var targetOffset = totalLength * (1 - normalized);
            // Needle: -90° (left, 0%) to +90° (right, 100%)
            var targetAngle = -90 + (normalized * 180);

            // Animate after a brief delay
            setTimeout(function() {
                arc.style.transition = 'stroke-dashoffset 1.4s cubic-bezier(0.4, 0, 0.2, 1)';
                arc.style.strokeDashoffset = targetOffset;

                needle.style.transition = 'transform 1.4s cubic-bezier(0.4, 0, 0.2, 1)';
                needle.style.transformOrigin = '100px 105px';
                needle.style.transform = 'rotate(' + targetAngle + 'deg)';
            }, 400);
        })();

        // ── Modal helpers ─────────────────────────────────────
        function showModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function hideModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        // Close modal on overlay click
        document.querySelectorAll('.mw-modal-overlay').forEach(function(overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });

        // ── Visit action modals ───────────────────────────────
        function openCompleteModal(visitId, visitNumber, defaultAmount) {
            document.getElementById('completeVisitId').value = visitId;
            document.getElementById('completeVisitNumber').textContent = visitNumber;
            document.getElementById('completeActualAmount').value = defaultAmount > 0 ? defaultAmount.toFixed(2) : '';
            showModal('completeModal');
        }

        function openSkipModal(visitId, visitNumber) {
            document.getElementById('skipVisitId').value = visitId;
            document.getElementById('skipVisitNumber').textContent = visitNumber;
            showModal('skipModal');
        }

        function openWeatherModal(visitId, visitNumber) {
            document.getElementById('weatherVisitId').value = visitId;
            document.getElementById('weatherVisitNumber').textContent = visitNumber;
            showModal('weatherModal');
        }

        // ── Visit filter buttons ──────────────────────────────
        document.querySelectorAll('.mw-visit-filter').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var filter = this.getAttribute('data-filter');

                // Toggle active class
                document.querySelectorAll('.mw-visit-filter').forEach(function(b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                // Show/hide rows
                var rows = document.querySelectorAll('#visitsTable tbody tr');
                rows.forEach(function(row) {
                    if (filter === 'all') {
                        row.style.display = '';
                    } else if (filter === 'upcoming') {
                        row.style.display = row.classList.contains('mw-visit-upcoming') ? '' : 'none';
                    } else if (filter === 'completed') {
                        row.style.display = row.classList.contains('mw-visit-completed') ? '' : 'none';
                    }
                });
            });
        });
    </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
