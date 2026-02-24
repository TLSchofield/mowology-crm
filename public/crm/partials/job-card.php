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

// Job type color mapping — use $serviceColors if passed from parent, else DB with hardcoded fallback
if (!isset($serviceColors) || !is_array($serviceColors)) {
    $serviceColors = [
        'lawn_care'          => '#2E7D32',
        'hedge_trimming'     => '#6A1B9A',
        'garden_maintenance' => '#EF6C00',
        'snow_removal'       => '#1565C0',
        'landscaping'        => '#2D8659',
        'seasonal_cleanup'   => '#455A64',
    ];
    try {
        $__stDb = function_exists('getDB') ? getDB() : null;
        if ($__stDb) {
            $__stRows = $__stDb->query("SELECT slug, color FROM service_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($__stRows as $__r) { $serviceColors[$__r['slug']] = $__r['color']; }
        }
    } catch (Exception $__e) { /* use fallback */ }
}
$jobTypeColors = $serviceColors; // backward compat
$accentColor = $serviceColors[$primaryServiceType] ?? '#455A64';

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

// Tag icon SVG paths (Feather icon style)
$tagIcons = [
    'key'            => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    'unlock'         => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 019.9-1"/>',
    'square'         => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><text x="12" y="16" text-anchor="middle" font-size="12" font-weight="bold" fill="currentColor" stroke="none">P</text>',
    'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'bell'           => '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>',
    'info'           => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
];

/**
 * Render property tags as badges
 */
if (!function_exists('renderPropertyTags')):
function renderPropertyTags(array $tags, array $tagIcons): string {
    if (empty($tags)) return '';
    $html = '<div class="mw-mc-tags">';
    foreach ($tags as $tag) {
        $color = htmlspecialchars($tag['color'] ?? '#6B7280');
        $icon = $tag['icon'] ?? '';
        $label = htmlspecialchars($tag['label'] ?? '');
        $value = htmlspecialchars($tag['value'] ?? '');
        $hasValue = (int)($tag['has_value'] ?? 0);

        $html .= '<span class="mw-mc-tag" style="--tag-color: ' . $color . '">';

        // Icon
        if ($icon && isset($tagIcons[$icon])) {
            $html .= '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $tagIcons[$icon] . '</svg>';
        }

        // Value or label
        if ($hasValue && $value !== '') {
            $html .= '<span class="mw-mc-tag-value">' . $value . '</span>';
        } else {
            $html .= '<span class="mw-mc-tag-value">' . $label . '</span>';
        }

        $html .= '</span>';
    }
    $html .= '</div>';
    return $html;
}
endif;

$stopTags = $stop['tags'] ?? [];
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

            <?php echo renderPropertyTags($stopTags, $tagIcons); ?>

            <?php if (!empty($stop['visits'])): ?>
                <div class="mw-mc-services">
                    <?php foreach ($stop['visits'] as $v):
                        $pillColor = $jobTypeColors[$v['service_type'] ?? ''] ?? '#455A64';
                        $pillStatus = $v['visit_status'] ?? 'scheduled';
                        $autoClockIn = !empty($v['auto_clock_in']) ? '1' : '0';
                    ?>
                        <span class="mw-mc-service-pill mw-mc-pill-interactive mw-mc-pill-<?php echo ($pillStatus === 'completed') ? 'done' : (($pillStatus === 'in_progress') ? 'active' : 'scheduled'); ?>"
                              data-visit-id="<?php echo (int)($v['visit_id'] ?? 0); ?>"
                              data-visit-status="<?php echo htmlspecialchars($pillStatus); ?>"
                              data-service-type="<?php echo htmlspecialchars($v['service_type'] ?? ''); ?>"
                              data-auto-clock-in="<?php echo $autoClockIn; ?>"
                              style="--pill-color: <?php echo $pillColor; ?>; border-left-color: <?php echo $pillColor; ?>">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $v['service_type'] ?? ''))); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <!-- Pill action drawer (JS populates based on tapped pill) -->
                <div class="mw-mc-pill-drawer" style="display: none;"></div>
                <!-- Persistent photo strip (JS populates after photo capture) -->
                <div class="mw-mc-photo-strips"></div>
            <?php endif; ?>

            <?php $stopProfitMargin = $stop['profit_margin'] ?? null; ?>
            <?php if ($stopProfitMargin !== null): ?>
                <div class="mw-mc-profit-bar" title="Est. margin: <?php echo (int)$stopProfitMargin; ?>%">
                    <div class="mw-mc-profit-fill" style="width: <?php echo max(0, min(100, $stopProfitMargin)); ?>%; background: <?php echo function_exists('profitBarColor') ? profitBarColor((int)$stopProfitMargin) : '#2D8659'; ?>" data-margin="<?php echo (int)$stopProfitMargin; ?>"></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
        <div class="mw-mc-actions">
            <button type="button" class="mw-mc-action-btn mw-mc-btn-route"
                    data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                    data-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                <span>Route</span>
            </button>
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
        <div class="mw-mc-compact-main">
            <div class="mw-mc-compact-info">
                <!-- Line 1: Time + Client Name (ALL CAPS) -->
                <div class="mw-mc-compact-line1">
                    <?php if ($timeDisplay): ?>
                        <span class="mw-mc-compact-time"><?php echo htmlspecialchars($timeDisplay); ?></span>
                    <?php endif; ?>
                    <?php if ($clientName): ?>
                        <span class="mw-mc-compact-client"><?php echo strtoupper($clientName); ?></span>
                    <?php else: ?>
                        <span class="mw-mc-compact-title"><?php echo htmlspecialchars($primaryPlanTitle ?: $serviceLabelsStr); ?></span>
                    <?php endif; ?>
                    <?php if ($badge): ?>
                        <span class="mw-mc-badge <?php echo $badge['class']; ?>"><?php echo $badge['label']; ?></span>
                    <?php endif; ?>
                </div>
                <!-- Line 2: Street address -->
                <div class="mw-mc-compact-line2"><?php echo $fullAddress; ?></div>
            </div>

            <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
                <button type="button" class="mw-mc-compact-route"
                        data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                        data-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>"
                        onclick="event.stopPropagation(); if(typeof MwRouteMap!=='undefined') MwRouteMap.openToStop(<?php echo (int)$stop['stop_id']; ?>);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2L4.5 20.3l.7.7L12 18l6.8 3 .7-.7z"/></svg>
                </button>
            <?php endif; ?>
        </div>

        <?php if (!empty($stop['visits'])): ?>
            <div class="mw-mc-services" style="padding-top: 6px;">
                <?php foreach ($stop['visits'] as $v):
                    $pillColor = $jobTypeColors[$v['service_type'] ?? ''] ?? '#455A64';
                    $pillStatus = $v['visit_status'] ?? 'scheduled';
                    $autoClockIn = !empty($v['auto_clock_in']) ? '1' : '0';
                ?>
                    <span class="mw-mc-service-pill mw-mc-pill-interactive mw-mc-pill-<?php echo ($pillStatus === 'completed') ? 'done' : (($pillStatus === 'in_progress') ? 'active' : 'scheduled'); ?>"
                          data-visit-id="<?php echo (int)($v['visit_id'] ?? 0); ?>"
                          data-visit-status="<?php echo htmlspecialchars($pillStatus); ?>"
                          data-service-type="<?php echo htmlspecialchars($v['service_type'] ?? ''); ?>"
                          data-auto-clock-in="<?php echo $autoClockIn; ?>"
                          style="--pill-color: <?php echo $pillColor; ?>; border-left-color: <?php echo $pillColor; ?>">
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $v['service_type'] ?? ''))); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <!-- Pill action drawer (JS populates based on tapped pill) -->
            <div class="mw-mc-pill-drawer" style="display: none;"></div>
            <!-- Persistent photo strip (JS populates after photo capture) -->
            <div class="mw-mc-photo-strips"></div>
        <?php endif; ?>

        <?php $compactProfitMargin = $stop['profit_margin'] ?? null; ?>
        <?php if ($compactProfitMargin !== null): ?>
            <div class="mw-mc-profit-bar" title="Est. margin: <?php echo (int)$compactProfitMargin; ?>%">
                <div class="mw-mc-profit-fill" style="width: <?php echo max(0, min(100, $compactProfitMargin)); ?>%; background: <?php echo function_exists('profitBarColor') ? profitBarColor((int)$compactProfitMargin) : '#2D8659'; ?>" data-margin="<?php echo (int)$compactProfitMargin; ?>"></div>
            </div>
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
        <?php echo renderPropertyTags($stopTags, $tagIcons); ?>
        <?php if ($durationDisplay): ?>
            <div class="mw-mc-duration-row">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?php echo htmlspecialchars($durationDisplay); ?>
            </div>
        <?php endif; ?>

        <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
        <div class="mw-mc-actions">
            <button type="button" class="mw-mc-action-btn mw-mc-btn-route"
                    data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                    data-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                <span>Route</span>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Hidden camera input for photo capture -->
    <input type="file" class="mw-mc-camera-input" accept="image/*" capture="environment"
           style="display: none;" data-stop-id="<?php echo (int)$stop['stop_id']; ?>">
</div>
<?php endif; ?>
