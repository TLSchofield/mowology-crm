<?php
/**
 * Job Card Partial — Mobile Execution View
 *
 * Renders a single stop as a card for the mobile schedule view.
 * Two modes: active (expanded with action buttons) and compact (minimized two-line).
 *
 * Expected variables:
 *   $stop       — array from getCalendarStops() (stop data + visits[])
 *   $isActive   — bool: true = large active card, false = compact upcoming card
 *   $permissions — array of permission keys the current user has (e.g. ['schedule.view', 'timer.start'])
 *   $serviceColors — array mapping service_type => hex color
 *
 * Job type color mapping for left bar:
 *   lawn_care           => #2E7D32
 *   hedge_trimming      => #6A1B9A
 *   garden_maintenance  => #EF6C00
 *   snow_removal        => #1565C0
 *   landscaping         => #2D8659
 *   seasonal_cleanup    => #455A64
 */

// Determine primary service type from the first visit
$primaryServiceType = '';
$primaryPlanTitle = '';
$estimatedDuration = 0;
$visitId = 0;
$visitStatus = 'scheduled';
if (!empty($stop['visits'])) {
    $primaryServiceType = $stop['visits'][0]['service_type'] ?? '';
    $primaryPlanTitle = $stop['visits'][0]['plan_title'] ?? '';
    $estimatedDuration = (int)($stop['visits'][0]['estimated_duration'] ?? 0);
    $visitId = (int)($stop['visits'][0]['visit_id'] ?? 0);
    $visitStatus = $stop['visits'][0]['visit_status'] ?? 'scheduled';
}

// Job type color mapping (field-execution palette)
$jobTypeColors = [
    'lawn_care'          => '#2E7D32',
    'hedge_trimming'     => '#6A1B9A',
    'garden_maintenance' => '#EF6C00',
    'snow_removal'       => '#1565C0',
    'landscaping'        => '#2D8659',
    'seasonal_cleanup'   => '#455A64',
];
$accentColor = $jobTypeColors[$primaryServiceType] ?? '#455A64';

// Time display
$timeDisplay = '';
if (!empty($stop['estimated_arrival'])) {
    $timeDisplay = date('g:i A', strtotime($stop['estimated_arrival']));
} elseif (!empty($stop['visits'][0]['scheduled_time_start'])) {
    $timeDisplay = date('g:i A', strtotime($stop['visits'][0]['scheduled_time_start']));
}

// Address: full for active, street-only for compact
$fullAddress = htmlspecialchars($stop['property_address'] ?? 'Unknown');
$streetAddress = $fullAddress; // Already just street from DB

// Client name: prefer contact name, fall back to company name
$clientName = '';
if (!empty($stop['contact_name'])) {
    $clientName = htmlspecialchars(trim($stop['contact_name']));
} elseif (!empty($stop['company_name'])) {
    $clientName = htmlspecialchars($stop['company_name']);
}

// Client tier (future: from DB)
// 'gold' | 'standard' | 'warning' | null
$clientTier = $stop['client_tier'] ?? null;
$tierColors = [
    'gold'     => '#D4A017',
    'standard' => '#9E9E9E',
    'warning'  => '#D32F2F',
];

// Status badge
$statusBadges = [
    'in_progress' => ['label' => 'In Progress', 'class' => 'mw-mc-badge-progress'],
    'completed'   => ['label' => 'Completed', 'class' => 'mw-mc-badge-done'],
    'skipped'     => ['label' => 'Skipped', 'class' => 'mw-mc-badge-skipped'],
];
$badge = $statusBadges[$visitStatus] ?? null;
$stopStatus = $stop['stop_status'] ?? 'scheduled';

// Duration display
$durationDisplay = '';
if ($estimatedDuration > 0) {
    if ($estimatedDuration >= 60) {
        $hours = floor($estimatedDuration / 60);
        $mins = $estimatedDuration % 60;
        $durationDisplay = $hours . 'h' . ($mins > 0 ? ' ' . $mins . 'm' : '');
    } else {
        $durationDisplay = $estimatedDuration . ' min';
    }
}

// Build service label string for all visits on this stop
$serviceLabelsStr = '';
if (!empty($stop['visits'])) {
    $labels = [];
    foreach ($stop['visits'] as $v) {
        $type = $v['service_type'] ?? '';
        $labels[] = ucfirst(str_replace('_', ' ', $type));
    }
    $serviceLabelsStr = implode(', ', $labels);
}
?>

<?php if ($isActive): ?>
<!-- ═══ ACTIVE CARD (expanded) ═══ -->
<div class="mw-mc-card mw-mc-card-active <?php echo ($stopStatus === 'completed') ? 'mw-mc-card-completed' : ''; ?>"
     data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
     data-visit-id="<?php echo $visitId; ?>"
     data-lat="<?php echo htmlspecialchars($stop['latitude'] ?? ''); ?>"
     data-lng="<?php echo htmlspecialchars($stop['longitude'] ?? ''); ?>">

    <?php if ($clientTier && isset($tierColors[$clientTier])): ?>
        <div class="mw-mc-tier-strip" style="background: <?php echo $tierColors[$clientTier]; ?>"></div>
    <?php endif; ?>

    <div class="mw-mc-accent" style="background: <?php echo $accentColor; ?>"></div>

    <div class="mw-mc-card-body">
        <div class="mw-mc-card-header">
            <div class="mw-mc-time-row">
                <?php if ($timeDisplay): ?>
                    <span class="mw-mc-time"><?php echo htmlspecialchars($timeDisplay); ?></span>
                <?php endif; ?>
                <?php if ($durationDisplay): ?>
                    <span class="mw-mc-duration"><?php echo htmlspecialchars($durationDisplay); ?></span>
                <?php endif; ?>
                <?php if ($badge): ?>
                    <span class="mw-mc-badge <?php echo $badge['class']; ?>"><?php echo $badge['label']; ?></span>
                <?php endif; ?>
            </div>

            <h3 class="mw-mc-title"><?php echo htmlspecialchars($primaryPlanTitle ?: $serviceLabelsStr); ?></h3>

            <div class="mw-mc-address">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?php echo $fullAddress; ?>
            </div>

            <?php if ($clientName): ?>
                <div class="mw-mc-client">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php echo $clientName; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($stop['visits'])): ?>
                <div class="mw-mc-services">
                    <?php foreach ($stop['visits'] as $v):
                        $pillColor = $jobTypeColors[$v['service_type'] ?? ''] ?? '#455A64';
                        $pillStatus = $v['visit_status'] ?? 'scheduled';
                    ?>
                        <span class="mw-mc-service-pill mw-mc-pill-interactive mw-mc-pill-<?php echo ($pillStatus === 'completed') ? 'done' : (($pillStatus === 'in_progress') ? 'active' : 'scheduled'); ?>"
                              data-visit-id="<?php echo (int)($v['visit_id'] ?? 0); ?>"
                              data-visit-status="<?php echo htmlspecialchars($pillStatus); ?>"
                              data-service-type="<?php echo htmlspecialchars($v['service_type'] ?? ''); ?>"
                              style="--pill-color: <?php echo $pillColor; ?>; border-left-color: <?php echo $pillColor; ?>">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $v['service_type'] ?? ''))); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <!-- Pill action drawer (JS populates based on tapped pill) -->
                <div class="mw-mc-pill-drawer" style="display: none;"></div>
            <?php endif; ?>
        </div>

        <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
        <div class="mw-mc-actions">
            <a class="mw-mc-action-btn mw-mc-btn-route"
               href="https://maps.google.com/?daddr=<?php echo urlencode($stop['property_address'] ?? ''); ?>"
               target="_blank" rel="noopener">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                <span>Route</span>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Hidden camera input for photo capture -->
    <input type="file" class="mw-mc-camera-input" accept="image/*" capture="environment"
           style="display: none;" data-stop-id="<?php echo (int)$stop['stop_id']; ?>">
</div>

<?php else: ?>
<!-- ═══ COMPACT CARD (minimized) ═══ -->
<div class="mw-mc-card mw-mc-card-compact <?php echo ($stopStatus === 'completed') ? 'mw-mc-card-completed' : ''; ?>"
     data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
     data-visit-id="<?php echo $visitId; ?>"
     data-lat="<?php echo htmlspecialchars($stop['latitude'] ?? ''); ?>"
     data-lng="<?php echo htmlspecialchars($stop['longitude'] ?? ''); ?>">

    <?php if ($clientTier && isset($tierColors[$clientTier])): ?>
        <div class="mw-mc-tier-strip" style="background: <?php echo $tierColors[$clientTier]; ?>"></div>
    <?php endif; ?>

    <div class="mw-mc-accent" style="background: <?php echo $accentColor; ?>"></div>

    <div class="mw-mc-card-body">
        <!-- Line 1: Time + Job Name -->
        <div class="mw-mc-compact-line1">
            <?php if ($timeDisplay): ?>
                <span class="mw-mc-compact-time"><?php echo htmlspecialchars($timeDisplay); ?></span>
            <?php endif; ?>
            <span class="mw-mc-compact-title"><?php echo htmlspecialchars($primaryPlanTitle ?: $serviceLabelsStr); ?></span>
            <?php if ($badge): ?>
                <span class="mw-mc-badge <?php echo $badge['class']; ?>"><?php echo $badge['label']; ?></span>
            <?php endif; ?>
        </div>
        <!-- Line 2: Street name -->
        <div class="mw-mc-compact-line2"><?php echo $fullAddress; ?></div>

        <?php if (!empty($stop['visits'])): ?>
            <div class="mw-mc-services" style="padding-top: 6px;">
                <?php foreach ($stop['visits'] as $v):
                    $pillColor = $jobTypeColors[$v['service_type'] ?? ''] ?? '#455A64';
                    $pillStatus = $v['visit_status'] ?? 'scheduled';
                ?>
                    <span class="mw-mc-service-pill mw-mc-pill-interactive mw-mc-pill-<?php echo ($pillStatus === 'completed') ? 'done' : (($pillStatus === 'in_progress') ? 'active' : 'scheduled'); ?>"
                          data-visit-id="<?php echo (int)($v['visit_id'] ?? 0); ?>"
                          data-visit-status="<?php echo htmlspecialchars($pillStatus); ?>"
                          data-service-type="<?php echo htmlspecialchars($v['service_type'] ?? ''); ?>"
                          style="--pill-color: <?php echo $pillColor; ?>; border-left-color: <?php echo $pillColor; ?>">
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $v['service_type'] ?? ''))); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <!-- Pill action drawer (JS populates based on tapped pill) -->
            <div class="mw-mc-pill-drawer" style="display: none;"></div>
        <?php endif; ?>
    </div>

    <!-- Expandable detail (hidden by default, revealed on tap) -->
    <div class="mw-mc-expand-detail" style="display: none;">
        <?php if ($clientName): ?>
            <div class="mw-mc-client">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?php echo $clientName; ?>
            </div>
        <?php endif; ?>
        <?php if ($durationDisplay): ?>
            <div class="mw-mc-duration-row">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?php echo htmlspecialchars($durationDisplay); ?>
            </div>
        <?php endif; ?>

        <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
        <div class="mw-mc-actions">
            <a class="mw-mc-action-btn mw-mc-btn-route"
               href="https://maps.google.com/?daddr=<?php echo urlencode($stop['property_address'] ?? ''); ?>"
               target="_blank" rel="noopener">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                <span>Route</span>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Hidden camera input for photo capture -->
    <input type="file" class="mw-mc-camera-input" accept="image/*" capture="environment"
           style="display: none;" data-stop-id="<?php echo (int)$stop['stop_id']; ?>">
</div>
<?php endif; ?>
