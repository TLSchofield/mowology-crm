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

    // Edit plan details
    if ($action === 'edit_plan') {
        $isRecurring = ($_POST['plan_type'] ?? 'one_time') === 'recurring' ? 1 : 0;
        $recurrencePattern = $isRecurring ? ($_POST['recurrence_pattern'] ?? 'weekly') : null;
        $recurrenceInterval = $isRecurring ? max(1, intval($_POST['recurrence_interval'] ?? 1)) : 1;
        $recurrenceIntervalUnit = $isRecurring ? ($_POST['recurrence_interval_unit'] ?? 'weeks') : 'weeks';
        $recurrenceDow = $isRecurring && isset($_POST['recurrence_day_of_week']) && $_POST['recurrence_day_of_week'] !== ''
            ? intval($_POST['recurrence_day_of_week']) : null;

        // Map presets
        if ($recurrencePattern === 'daily') {
            $recurrencePattern = 'custom';
            $recurrenceInterval = 1;
            $recurrenceIntervalUnit = 'days';
        } elseif ($recurrencePattern === 'biweekly') {
            $recurrenceInterval = 2;
            $recurrenceIntervalUnit = 'weeks';
        } elseif ($recurrencePattern === 'yearly') {
            $recurrenceInterval = max(1, $recurrenceInterval);
            $recurrenceIntervalUnit = 'years';
        }

        if (!in_array($recurrenceIntervalUnit, ['days', 'weeks', 'months', 'years'], true)) {
            $recurrenceIntervalUnit = 'weeks';
        }

        // Multi-crew assignment
        $crewIds = [];
        if (!empty($_POST['crew_ids']) && is_array($_POST['crew_ids'])) {
            $crewIds = array_map('intval', $_POST['crew_ids']);
            $crewIds = array_filter($crewIds, function($id) { return $id > 0; });
        }

        $planData = [
            'title'                    => trim($_POST['edit_title'] ?? ''),
            'description'              => trim($_POST['edit_description'] ?? ''),
            'service_type'             => $_POST['edit_service_type'] ?? 'landscaping',
            'pricing_model'            => $_POST['edit_pricing_model'] ?? 'per_visit',
            'price_per_visit'          => floatval($_POST['edit_price_per_visit'] ?? 0) ?: null,
            'estimated_duration_minutes' => intval($_POST['edit_duration'] ?? 60),
            'default_crew_id'          => !empty($crewIds) ? $crewIds[0] : (!empty($_POST['edit_crew_id']) ? intval($_POST['edit_crew_id']) : null),
            'default_time_start'       => !empty($_POST['edit_time_start']) ? $_POST['edit_time_start'] : null,
            'default_time_end'         => !empty($_POST['edit_time_end']) ? $_POST['edit_time_end'] : null,
            'plan_start_date'          => $_POST['edit_start_date'] ?? date('Y-m-d'),
            'plan_end_date'            => !empty($_POST['edit_end_date']) ? $_POST['edit_end_date'] : null,
            'is_recurring'             => $isRecurring,
            'recurrence_pattern'       => $recurrencePattern,
            'recurrence_interval'      => $recurrenceInterval,
            'recurrence_interval_unit' => $recurrenceIntervalUnit,
            'recurrence_day_of_week'   => $recurrenceDow,
            'horizon_days'             => intval($_POST['edit_horizon_days'] ?? 28),
            'crew_ids'                 => $crewIds,
        ];

        $result = updateJobPlan($planId, $planData, (int)$user['id']);

        if ($result['success']) {
            $suffix = $result['visits_regenerated'] ? '&visits_regenerated=1' : '';
            header("Location: view.php?id={$planId}&plan_updated=1{$suffix}");
            exit;
        }
        $message = implode(' ', $result['errors']);
        $messageType = 'error';
    }

    // Update line items
    if ($action === 'update_line_items') {
        $formItems = [];
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['service_type'])) continue;
                $formItems[] = [
                    'service_type' => $item['service_type'],
                    'description'  => $item['description'] ?? '',
                    'quantity'     => floatval($item['quantity'] ?? 1),
                    'unit_type'    => $item['unit_type'] ?? 'visit',
                    'unit_price'   => floatval($item['unit_price'] ?? 0),
                    'line_total'   => floatval($item['line_total'] ?? 0),
                ];
            }
        }

        if (replacePlanLineItems($planId, $formItems)) {
            header("Location: view.php?id={$planId}&items_updated=1");
            exit;
        }
        $message = 'Could not update line items.';
        $messageType = 'error';
    }

    // Edit visit
    if ($action === 'edit_visit') {
        $visitId = intval($_POST['edit_visit_id'] ?? 0);
        $newDate = $_POST['visit_date'] ?? '';
        $newTimeStart = !empty($_POST['visit_time_start']) ? $_POST['visit_time_start'] : null;
        $newTimeEnd = !empty($_POST['visit_time_end']) ? $_POST['visit_time_end'] : null;
        $newCrewId = !empty($_POST['visit_crew_id']) ? intval($_POST['visit_crew_id']) : null;

        if ($visitId && $newDate) {
            $moved = moveVisit($visitId, $newDate, $newTimeStart, $user['id']);

            // Also update time end and crew
            $updates = [];
            $updateParams = [];
            if ($newTimeEnd !== null) {
                $updates[] = "scheduled_time_end = ?";
                $updateParams[] = $newTimeEnd;
            }
            if ($newCrewId !== null) {
                $updates[] = "assigned_crew_id = ?";
                $updateParams[] = $newCrewId;
            } elseif (isset($_POST['visit_crew_id']) && $_POST['visit_crew_id'] === '') {
                $updates[] = "assigned_crew_id = NULL";
            }
            if (!empty($updates)) {
                $updateParams[] = $visitId;
                $stmt = $db->prepare("UPDATE job_visits SET " . implode(', ', $updates) . " WHERE id = ?");
                $stmt->execute($updateParams);
            }

            header("Location: view.php?id={$planId}&visit_updated=1");
            exit;
        }
        $message = 'Could not update visit.';
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
if (isset($_GET['plan_updated'])) {
    $message = 'Plan updated successfully!';
    if (isset($_GET['visits_regenerated'])) $message .= ' Future visits have been regenerated.';
    $messageType = 'success';
}
if (isset($_GET['items_updated'])) { $message = 'Line items updated!'; $messageType = 'success'; }
if (isset($_GET['visit_updated'])) { $message = 'Visit updated!'; $messageType = 'success'; }

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
        case 'weekly':  return $interval > 1 ? "Every {$interval} weeks" : 'Weekly';
        case 'biweekly': return 'Every 2 weeks';
        case 'monthly': return $interval > 1 ? "Every {$interval} months" : 'Monthly';
        case 'yearly':  return $interval > 1 ? "Every {$interval} years" : 'Yearly';
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
                    <?php if (in_array($plan['status'], ['active', 'paused'])): ?>
                        <button type="button" class="btn btn-outline-primary" onclick="showModal('editPlanModal')">
                            <i data-feather="edit-2" style="width:14px;height:14px;"></i> Edit Plan
                        </button>
                    <?php endif; ?>
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
                                <span class="mw-detail-label">Crew</span>
                                <span class="mw-detail-value">
                                    <?php
                                    $crewAssignments = getPlanCrewAssignments($planId);
                                    if (!empty($crewAssignments)):
                                        $crewNames = [];
                                        foreach ($crewAssignments as $ca) {
                                            $name = htmlspecialchars($ca['full_name']);
                                            if ($ca['role'] === 'lead') $name .= ' <small class="text-muted">(Lead)</small>';
                                            $crewNames[] = $name;
                                        }
                                        echo implode(', ', $crewNames);
                                    else:
                                        echo htmlspecialchars($plan['default_crew_name'] ?? 'Unassigned');
                                    endif;
                                    ?>
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
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Services Included</h5>
                    <?php if (in_array($plan['status'], ['active', 'paused'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="showModal('editItemsModal')">
                            <i data-feather="edit-2" style="width:12px;height:12px;"></i> Edit Items
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($planLineItems)): ?>
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
                <?php else: ?>
                <div class="card-body text-center text-muted py-3">
                    <p class="mb-0 small">No line items. Click "Edit Items" to add services.</p>
                </div>
                <?php endif; ?>
            </div>

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
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                            onclick="openEditVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>', '<?php echo $visit['scheduled_date']; ?>', '<?php echo $visit['scheduled_time_start'] ?? ''; ?>', '<?php echo $visit['scheduled_time_end'] ?? ''; ?>', '<?php echo $visit['assigned_crew_id'] ?? ''; ?>')"
                                                            title="Edit visit">
                                                        <i data-feather="edit-2" style="width: 12px; height: 12px;"></i>
                                                    </button>
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


    <!-- Edit Plan Modal -->
    <?php if (in_array($plan['status'], ['active', 'paused'])): ?>
    <div class="mw-modal-overlay" id="editPlanModal">
        <div class="mw-modal mw-modal-wide">
            <h3 class="mw-modal-title">Edit Plan <?php echo htmlspecialchars($plan['plan_number']); ?></h3>
            <form method="POST" id="editPlanForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_plan">

                <div class="mw-form-row">
                    <div class="mw-form-group" style="flex:2;">
                        <label class="form-label">Plan Title *</label>
                        <input type="text" name="edit_title" class="form-control" required
                               value="<?php echo htmlspecialchars($plan['title'] ?? ''); ?>">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Service Type</label>
                        <select name="edit_service_type" class="form-control">
                            <?php
                            $serviceTypes = ['landscaping'=>'Landscaping','lawn_care'=>'Lawn Care','snow_removal'=>'Snow Removal','hedge_trimming'=>'Hedge Trimming','garden_maintenance'=>'Garden Maintenance','seasonal_cleanup'=>'Seasonal Cleanup'];
                            foreach ($serviceTypes as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo $plan['service_type'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mw-form-group">
                    <label class="form-label">Description</label>
                    <textarea name="edit_description" class="form-control" rows="3"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></textarea>
                </div>

                <hr class="my-3">
                <h6>Scheduling</h6>

                <div class="mw-form-row">
                    <div class="mw-form-group">
                        <label class="form-label">Plan Type</label>
                        <select name="plan_type" id="editPlanType" class="form-control" onchange="toggleEditRecurring()">
                            <option value="one_time" <?php echo !$plan['is_recurring'] ? 'selected' : ''; ?>>One-Time</option>
                            <option value="recurring" <?php echo $plan['is_recurring'] ? 'selected' : ''; ?>>Recurring</option>
                        </select>
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="edit_start_date" class="form-control"
                               value="<?php echo $plan['plan_start_date'] ?? ''; ?>">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="edit_end_date" class="form-control"
                               value="<?php echo $plan['plan_end_date'] ?? ''; ?>">
                        <small class="text-muted">Blank = ongoing</small>
                    </div>
                </div>

                <div class="mw-form-row">
                    <div class="mw-form-group">
                        <label class="form-label">Default Start Time</label>
                        <input type="time" name="edit_time_start" class="form-control"
                               value="<?php echo $plan['default_time_start'] ?? ''; ?>">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Default End Time</label>
                        <input type="time" name="edit_time_end" class="form-control"
                               value="<?php echo $plan['default_time_end'] ?? ''; ?>">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Duration (min)</label>
                        <input type="number" name="edit_duration" class="form-control"
                               value="<?php echo (int)$plan['estimated_duration_minutes']; ?>" min="15" step="15">
                    </div>
                </div>

                <div class="mw-form-group">
                    <label class="form-label">Crew Assignment</label>
                    <div class="mw-crew-wrapper">
                        <div class="mw-crew-chips" id="editCrewChips">
                            <?php
                            $existingCrew = getPlanCrewAssignments($planId);
                            foreach ($existingCrew as $ec):
                            ?>
                                <span class="mw-crew-chip <?php echo $ec['role'] === 'lead' ? 'mw-crew-lead' : ''; ?>">
                                    <?php echo htmlspecialchars($ec['full_name']); ?><?php echo $ec['role'] === 'lead' ? ' (Lead)' : ''; ?>
                                    <button type="button" class="mw-crew-chip-remove" onclick="editRemoveCrew(<?php echo (int)$ec['user_id']; ?>)">&times;</button>
                                    <input type="hidden" name="crew_ids[]" value="<?php echo (int)$ec['user_id']; ?>">
                                </span>
                            <?php endforeach; ?>
                            <button type="button" class="mw-crew-add-btn" onclick="editToggleCrewDropdown()">+ Assign</button>
                        </div>
                        <div class="mw-crew-dropdown" id="editCrewDropdown">
                            <?php foreach ($staff as $s): ?>
                                <div class="mw-crew-dropdown-item"
                                     data-id="<?php echo (int)$s['id']; ?>"
                                     data-name="<?php echo htmlspecialchars($s['full_name'], ENT_QUOTES); ?>"
                                     onclick="editAssignCrew(<?php echo (int)$s['id']; ?>, this.dataset.name)">
                                    <?php echo htmlspecialchars($s['full_name']); ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($s['role']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="default_crew_id" id="editDefaultCrewIdHidden" value="<?php echo (int)($plan['default_crew_id'] ?? 0); ?>">
                    <small class="text-muted">First person assigned is the crew lead.</small>
                </div>

                <div class="mw-form-group">
                    <label class="form-label">Horizon Days</label>
                    <input type="number" name="edit_horizon_days" class="form-control"
                           value="<?php echo (int)$plan['horizon_days']; ?>" min="7" max="90" style="width:120px;">
                </div>

                <?php
                // Determine the display recurrence pattern for the edit modal
                $editRecPattern = $plan['recurrence_pattern'] ?? 'weekly';
                $editIsCustom = false;
                if ($editRecPattern === 'custom' && (int)($plan['recurrence_interval'] ?? 1) === 1
                    && ($plan['recurrence_interval_unit'] ?? 'weeks') === 'days') {
                    $editRecPattern = 'daily';
                } elseif ($editRecPattern === 'weekly' && (int)($plan['recurrence_interval'] ?? 1) === 2) {
                    $editRecPattern = 'biweekly';
                } elseif ($editRecPattern === 'custom') {
                    $editIsCustom = true;
                }
                $editDow = $plan['recurrence_day_of_week'] ?? null;
                ?>

                <div id="editRecurringOptions" style="<?php echo $plan['is_recurring'] ? '' : 'display:none;'; ?>">
                    <h6 class="mt-3">Recurrence</h6>

                    <!-- Frequency picker -->
                    <div class="mw-freq-picker" id="editFreqPicker">
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'daily' ? 'active' : ''; ?>" data-freq="daily">Daily</button>
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'weekly' ? 'active' : ''; ?>" data-freq="weekly">Weekly</button>
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'monthly' ? 'active' : ''; ?>" data-freq="monthly">Monthly</button>
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'yearly' ? 'active' : ''; ?>" data-freq="yearly">Yearly</button>
                        <button type="button" class="mw-freq-btn <?php echo $editIsCustom ? 'active' : ''; ?>" data-freq="custom">Custom</button>
                    </div>

                    <!-- Interval row -->
                    <div class="mw-interval-row" id="editIntervalRow">
                        <span class="mw-interval-label">Every</span>
                        <input type="number" name="recurrence_interval" id="editRecurrenceInterval"
                               class="form-control form-control-sm" value="<?php echo (int)($plan['recurrence_interval'] ?? 1); ?>" min="1" max="365">
                        <span class="mw-interval-label" id="editIntervalUnitLabel">
                            <?php
                            $unitLabels = ['daily'=>'day(s)','weekly'=>'week(s)','biweekly'=>'week(s)','monthly'=>'month(s)','yearly'=>'year(s)'];
                            echo $unitLabels[$editRecPattern] ?? 'week(s)';
                            ?>
                        </span>
                    </div>

                    <!-- Day-of-week picker -->
                    <div id="editDowPickerWrap" style="<?php echo in_array($editRecPattern, ['weekly','biweekly']) ? '' : 'display:none;'; ?>">
                        <label class="form-label mb-2">On</label>
                        <div class="mw-dow-picker" id="editDowPicker">
                            <?php $dowLetters = ['S','M','T','W','T','F','S']; ?>
                            <?php for ($d = 0; $d <= 6; $d++): ?>
                                <button type="button" class="mw-dow-btn <?php echo ($editDow !== null && (int)$editDow === $d) ? 'active' : ''; ?>" data-dow="<?php echo $d; ?>"><?php echo $dowLetters[$d]; ?></button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Custom unit picker -->
                    <div id="editCustomUnitWrap" style="<?php echo $editIsCustom ? '' : 'display:none;'; ?>" class="mb-2">
                        <select name="recurrence_interval_unit" id="editRecurrenceUnit" class="form-control form-control-sm" style="width:140px;">
                            <option value="days" <?php echo ($plan['recurrence_interval_unit'] ?? '') === 'days' ? 'selected' : ''; ?>>Days</option>
                            <option value="weeks" <?php echo ($plan['recurrence_interval_unit'] ?? 'weeks') === 'weeks' ? 'selected' : ''; ?>>Weeks</option>
                            <option value="months" <?php echo ($plan['recurrence_interval_unit'] ?? '') === 'months' ? 'selected' : ''; ?>>Months</option>
                            <option value="years" <?php echo ($plan['recurrence_interval_unit'] ?? '') === 'years' ? 'selected' : ''; ?>>Years</option>
                        </select>
                    </div>

                    <!-- Hidden fields synced by JS -->
                    <input type="hidden" name="recurrence_pattern" id="editRecurrencePatternHidden" value="<?php echo htmlspecialchars($editRecPattern); ?>">
                    <input type="hidden" name="recurrence_day_of_week" id="editRecurrenceDowHidden" value="<?php echo $editDow !== null ? (int)$editDow : ''; ?>">

                    <!-- Summary -->
                    <div class="mw-recurrence-summary" id="editRecurrenceSummary">
                        <i data-feather="repeat" style="width:14px;height:14px;"></i>
                        <span id="editRecurrenceSummaryText"><?php echo htmlspecialchars(describeRecurrence($plan)); ?></span>
                    </div>

                    <div class="mt-2">
                        <small class="text-muted"><i data-feather="alert-triangle" style="width:12px;height:12px;color:#F59E0B;"></i> Changing recurrence settings will cancel and regenerate future visits.</small>
                    </div>
                </div>

                <hr class="my-3">
                <h6>Pricing</h6>

                <div class="mw-form-row">
                    <div class="mw-form-group">
                        <label class="form-label">Pricing Model</label>
                        <select name="edit_pricing_model" class="form-control">
                            <?php
                            $pricingModels = ['per_visit'=>'Per Visit','monthly_flat'=>'Monthly Flat Rate','seasonal'=>'Seasonal','custom'=>'Custom'];
                            foreach ($pricingModels as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo ($plan['pricing_model'] ?? 'per_visit') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Price Per Visit ($)</label>
                        <input type="number" name="edit_price_per_visit" class="form-control" step="0.01" min="0"
                               value="<?php echo $plan['price_per_visit'] ?? ''; ?>">
                    </div>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editPlanModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Line Items Modal -->
    <div class="mw-modal-overlay" id="editItemsModal">
        <div class="mw-modal mw-modal-wide">
            <h3 class="mw-modal-title">Edit Services — <?php echo htmlspecialchars($plan['plan_number']); ?></h3>
            <form method="POST" id="editItemsForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_line_items">

                <table class="mw-line-items-table" id="editItemsTable">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Description</th>
                            <th style="width:70px;">Qty</th>
                            <th class="text-right" style="width:90px;">Price</th>
                            <th class="text-right" style="width:90px;">Total</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="editItemsBody">
                        <?php foreach ($planLineItems as $idx => $pli): ?>
                        <tr>
                            <td><input type="text" name="items[<?php echo $idx; ?>][service_type]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pli['service_type']); ?>" required></td>
                            <td><input type="text" name="items[<?php echo $idx; ?>][description]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pli['description'] ?? ''); ?>"></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][quantity]" class="form-control form-control-sm mw-ei-qty" value="<?php echo floatval($pli['quantity']); ?>" min="0.01" step="0.01" onchange="recalcEditItemRow(this)"></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][unit_price]" class="form-control form-control-sm mw-ei-price text-right" value="<?php echo floatval($pli['unit_price']); ?>" min="0" step="0.01" onchange="recalcEditItemRow(this)">
                                <input type="hidden" name="items[<?php echo $idx; ?>][unit_type]" value="<?php echo htmlspecialchars($pli['unit_type'] ?? 'visit'); ?>"></td>
                            <td class="text-right"><span class="mw-ei-row-total"><?php echo formatCurrency($pli['line_total']); ?></span>
                                <input type="hidden" name="items[<?php echo $idx; ?>][line_total]" class="mw-ei-total-input" value="<?php echo floatval($pli['line_total']); ?>"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeEditItemRow(this)" title="Remove">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right"><strong>Per Visit Total</strong></td>
                            <td class="text-right"><strong id="editItemsTotal"><?php echo formatCurrency(array_sum(array_column($planLineItems, 'line_total'))); ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addEditItem()">
                    <i data-feather="plus" style="width:14px;height:14px;"></i> Add Item
                </button>

                <div class="mw-modal-actions mt-3">
                    <button type="submit" class="btn btn-primary">Save Items</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editItemsModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Visit Modal -->
    <div class="mw-modal-overlay" id="editVisitModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Edit Visit <span id="editVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_visit">
                <input type="hidden" name="edit_visit_id" id="editVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="visit_date" id="editVisitDate" class="form-control" required>
                </div>
                <div class="mw-form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="visit_time_start" id="editVisitTimeStart" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Time</label>
                        <input type="time" name="visit_time_end" id="editVisitTimeEnd" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Crew</label>
                    <select name="visit_crew_id" id="editVisitCrew" class="form-control">
                        <option value="">Unassigned</option>
                        <?php foreach ($staff as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Save Visit</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editVisitModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

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

        // ── Edit Plan modal toggles ─────────────────────────
        function toggleEditRecurring() {
            var planType = document.getElementById('editPlanType');
            var opts = document.getElementById('editRecurringOptions');
            if (!planType || !opts) return;
            opts.style.display = (planType.value === 'recurring') ? '' : 'none';
        }

        // ── Edit Modal: Jobber-style recurrence controls ─────
        var editCurrentFreq = <?php echo json_encode($editRecPattern); ?>;
        var editSelectedDow = <?php echo ($editDow !== null) ? (int)$editDow : 'null'; ?>;
        var editDayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        // Frequency picker
        document.querySelectorAll('#editFreqPicker .mw-freq-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#editFreqPicker .mw-freq-btn').forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                editCurrentFreq = this.dataset.freq;
                editUpdateRecurrenceUI();
            });
        });

        // DOW picker
        document.querySelectorAll('#editDowPicker .mw-dow-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#editDowPicker .mw-dow-btn').forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                editSelectedDow = parseInt(this.dataset.dow);
                editUpdateHiddenFields();
                editUpdateSummaryText();
            });
        });

        // Interval input
        var editIntervalInput = document.getElementById('editRecurrenceInterval');
        if (editIntervalInput) {
            editIntervalInput.addEventListener('input', function() {
                editUpdateHiddenFields();
                editUpdateSummaryText();
            });
        }

        // Custom unit select
        var editUnitSelect = document.getElementById('editRecurrenceUnit');
        if (editUnitSelect) {
            editUnitSelect.addEventListener('change', function() {
                editUpdateSummaryText();
            });
        }

        function editUpdateRecurrenceUI() {
            var dowWrap = document.getElementById('editDowPickerWrap');
            var customUnitWrap = document.getElementById('editCustomUnitWrap');
            var unitLabel = document.getElementById('editIntervalUnitLabel');

            dowWrap.style.display = 'none';
            customUnitWrap.style.display = 'none';
            unitLabel.style.display = '';

            switch (editCurrentFreq) {
                case 'daily': unitLabel.textContent = 'day(s)'; break;
                case 'weekly': unitLabel.textContent = 'week(s)'; dowWrap.style.display = ''; break;
                case 'monthly': unitLabel.textContent = 'month(s)'; break;
                case 'yearly': unitLabel.textContent = 'year(s)'; break;
                case 'custom': customUnitWrap.style.display = ''; unitLabel.style.display = 'none'; break;
            }

            editUpdateHiddenFields();
            editUpdateSummaryText();
            if (typeof feather !== 'undefined') feather.replace();
        }

        function editUpdateHiddenFields() {
            document.getElementById('editRecurrencePatternHidden').value = editCurrentFreq;
            document.getElementById('editRecurrenceDowHidden').value = (editSelectedDow !== null) ? editSelectedDow : '';
        }

        function editUpdateSummaryText() {
            var interval = parseInt(document.getElementById('editRecurrenceInterval').value) || 1;
            var text = 'Repeats ';
            switch (editCurrentFreq) {
                case 'daily': text += interval === 1 ? 'every day' : 'every ' + interval + ' days'; break;
                case 'weekly':
                    text += interval === 1 ? 'every week' : 'every ' + interval + ' weeks';
                    if (editSelectedDow !== null) text += ' on ' + editDayNames[editSelectedDow];
                    break;
                case 'monthly': text += interval === 1 ? 'every month' : 'every ' + interval + ' months'; break;
                case 'yearly': text += interval === 1 ? 'every year' : 'every ' + interval + ' years'; break;
                case 'custom':
                    var unit = document.getElementById('editRecurrenceUnit').value;
                    text += 'every ' + interval + ' ' + unit;
                    break;
            }
            document.getElementById('editRecurrenceSummaryText').textContent = text;
        }

        // ── Edit Modal: Multi-crew assignment ─────────────────
        var editAssignedCrew = <?php
            $crewJson = [];
            if (!empty($existingCrew)) {
                // $existingCrew was fetched earlier for edit modal HTML
            } else {
                $existingCrew = getPlanCrewAssignments($planId);
            }
            foreach ($existingCrew as $ec) {
                $crewJson[] = ['id' => (int)$ec['user_id'], 'name' => $ec['full_name']];
            }
            echo json_encode($crewJson);
        ?>;

        function editToggleCrewDropdown() {
            var dd = document.getElementById('editCrewDropdown');
            dd.classList.toggle('show');
            dd.querySelectorAll('.mw-crew-dropdown-item').forEach(function(item) {
                var id = parseInt(item.dataset.id);
                item.classList.toggle('disabled', editAssignedCrew.some(function(c) { return c.id === id; }));
            });
        }

        function editAssignCrew(id, name) {
            if (editAssignedCrew.some(function(c) { return c.id === id; })) return;
            editAssignedCrew.push({ id: id, name: name });
            editRenderCrewChips();
            document.getElementById('editCrewDropdown').classList.remove('show');
        }

        function editRemoveCrew(id) {
            editAssignedCrew = editAssignedCrew.filter(function(c) { return c.id !== id; });
            editRenderCrewChips();
        }

        function editRenderCrewChips() {
            var container = document.getElementById('editCrewChips');
            var html = '';
            editAssignedCrew.forEach(function(c, idx) {
                var isLead = (idx === 0);
                html += '<span class="mw-crew-chip ' + (isLead ? 'mw-crew-lead' : '') + '">' +
                    escHtml(c.name) + (isLead ? ' (Lead)' : '') +
                    '<button type="button" class="mw-crew-chip-remove" onclick="editRemoveCrew(' + c.id + ')">&times;</button>' +
                    '<input type="hidden" name="crew_ids[]" value="' + c.id + '">' +
                    '</span>';
            });
            html += '<button type="button" class="mw-crew-add-btn" onclick="editToggleCrewDropdown()">+ Assign</button>';
            container.innerHTML = html;
            document.getElementById('editDefaultCrewIdHidden').value = editAssignedCrew.length > 0 ? editAssignedCrew[0].id : '';
        }

        function escHtml(str) {
            if (!str) return '';
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Close edit crew dropdown on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#editCrewChips') && !e.target.closest('#editCrewDropdown')) {
                var dd = document.getElementById('editCrewDropdown');
                if (dd) dd.classList.remove('show');
            }
        });

        // ── Edit Visit modal ────────────────────────────────
        function openEditVisitModal(visitId, visitNumber, date, timeStart, timeEnd, crewId) {
            document.getElementById('editVisitId').value = visitId;
            document.getElementById('editVisitNumber').textContent = visitNumber;
            document.getElementById('editVisitDate').value = date;
            document.getElementById('editVisitTimeStart').value = timeStart || '';
            document.getElementById('editVisitTimeEnd').value = timeEnd || '';
            var crewSelect = document.getElementById('editVisitCrew');
            crewSelect.value = crewId || '';
            showModal('editVisitModal');
        }

        // ── Edit Line Items ─────────────────────────────────
        var editItemIndex = <?php echo count($planLineItems); ?>;

        function addEditItem() {
            var body = document.getElementById('editItemsBody');
            var idx = editItemIndex++;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="text" name="items[' + idx + '][service_type]" class="form-control form-control-sm" placeholder="Service type" required></td>' +
                '<td><input type="text" name="items[' + idx + '][description]" class="form-control form-control-sm" placeholder="Description"></td>' +
                '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control form-control-sm mw-ei-qty" value="1" min="0.01" step="0.01" onchange="recalcEditItemRow(this)"></td>' +
                '<td><input type="number" name="items[' + idx + '][unit_price]" class="form-control form-control-sm mw-ei-price text-right" value="0" min="0" step="0.01" onchange="recalcEditItemRow(this)">' +
                    '<input type="hidden" name="items[' + idx + '][unit_type]" value="visit"></td>' +
                '<td class="text-right"><span class="mw-ei-row-total">$0.00</span>' +
                    '<input type="hidden" name="items[' + idx + '][line_total]" class="mw-ei-total-input" value="0"></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeEditItemRow(this)" title="Remove">&times;</button></td>';
            body.appendChild(tr);
        }

        function recalcEditItemRow(input) {
            var tr = input.closest('tr');
            var qty = parseFloat(tr.querySelector('.mw-ei-qty').value) || 0;
            var price = parseFloat(tr.querySelector('.mw-ei-price').value) || 0;
            var total = qty * price;
            tr.querySelector('.mw-ei-row-total').textContent = '$' + total.toFixed(2);
            tr.querySelector('.mw-ei-total-input').value = total.toFixed(2);
            updateEditItemTotals();
        }

        function removeEditItemRow(btn) {
            btn.closest('tr').remove();
            updateEditItemTotals();
        }

        function updateEditItemTotals() {
            var inputs = document.querySelectorAll('#editItemsBody .mw-ei-total-input');
            var sum = 0;
            inputs.forEach(function(inp) { sum += parseFloat(inp.value) || 0; });
            var totalEl = document.getElementById('editItemsTotal');
            if (totalEl) totalEl.textContent = '$' + sum.toFixed(2);
        }
    </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
