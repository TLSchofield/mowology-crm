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

// Preferred contact phone for one-tap call — try mobile first (most
// reliable for SMS + calls), fall back to landline. Sanitise to digits
// + leading '+' so tel: URIs work on Android and iOS.
$rawPhone = $stop['contact_mobile'] ?? $stop['contact_phone'] ?? '';
$contactPhone = preg_replace('/[^0-9+]/', '', (string)$rawPhone);
// Trim obviously-empty results ("+" or "00" etc.)
if (strlen($contactPhone) < 7) $contactPhone = '';

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

// Footer targets the first schedulable visit (in_progress or scheduled).
// If $stop['visits'][0] is already completed, the "Complete Job" button would
// call end_visit on a completed visit and fail with "cannot be ended" error.
$footerVisitId = $visitId;
foreach ($stop['visits'] as $_fv) {
    $_fvStatus = $_fv['visit_status'] ?? 'scheduled';
    if ($_fvStatus === 'scheduled' || $_fvStatus === 'in_progress') {
        $footerVisitId = (int)($_fv['visit_id'] ?? $visitId);
        break;
    }
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

// Lawn sq ft display
$lawnSqFt = (int)($stop['lawn_sqft'] ?? 0);
$lawnSqFtDisplay = $lawnSqFt > 0 ? number_format($lawnSqFt) . ' sq ft' : '';

// Last completed visit display
$lastVisitDisplay = '';
if (!empty($stop['last_completed_date'])) {
    $lv = new DateTime($stop['last_completed_date']);
    $now = new DateTime('today');
    $diffDays = (int)$now->diff($lv)->days;
    if ($diffDays === 0) {
        $lastVisitDisplay = 'Earlier today';
    } elseif ($diffDays === 1) {
        $lastVisitDisplay = 'Yesterday';
    } elseif ($diffDays <= 14) {
        $lastVisitDisplay = $diffDays . ' days ago';
    } elseif ($lv->format('Y') === $now->format('Y')) {
        $lastVisitDisplay = $lv->format('M j');
    } else {
        $lastVisitDisplay = $lv->format('M j, Y');
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

// Obsidian Root™ / fertilizer program status
$orStatus      = $stop['or_status'] ?? 'none';
$orIconVariant = ($orStatus === 'enrolled') ? 'full' : (($orStatus === 'offer') ? 'sell' : 'grey');
$orStatusLabel = ($orStatus === 'enrolled') ? 'Active Program' : (($orStatus === 'offer') ? 'Offer Program' : 'Not Enrolled');
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
    <div class="mw-mc-flip-inner">
    <div class="mw-mc-flip-front">
        <div class="mw-mc-card-header">
            <!-- Top row: uses exact same classes as compact card (.mw-mc-compact-main) — proven to work -->
            <div class="mw-mc-compact-main">
                <div class="mw-mc-compact-info">
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
                </div>
                <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
                <div class="mw-mc-header-actions">
                    <?php if ($contactPhone): ?>
                    <a href="tel:<?php echo htmlspecialchars($contactPhone); ?>"
                       class="mw-mc-btn-call"
                       data-haptic="tap"
                       aria-label="Call <?php echo htmlspecialchars($clientName ?: 'customer'); ?>"
                       onclick="event.stopPropagation();">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="mw-mc-btn-route mw-mc-compact-route mw-mc-compact-go"
                            data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                            data-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                        <span>GO</span>
                    </button>
                </div>
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

            <?php if ($lawnSqFtDisplay): ?>
                <div class="mw-mc-lawn-sqft">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 9h18M9 3v18"/></svg>
                    <?php echo htmlspecialchars($lawnSqFtDisplay); ?>
                </div>
            <?php endif; ?>

            <?php if ($lastVisitDisplay): ?>
                <div class="mw-mc-last-visit">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Last visit: <?php echo htmlspecialchars($lastVisitDisplay); ?>
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

        <!-- ── Obsidian Root™ Program Tile (matches Before/After tile dimensions) ── -->
        <button type="button"
                class="mw-mc-or-full-tile or-icon-<?php echo $orIconVariant; ?>"
                data-or-status="<?php echo htmlspecialchars($orStatus); ?>"
                data-flip-card="<?php echo (int)$stop['stop_id']; ?>">
            <img src="/assets/images/programs/obsidian-root-logo.png"
                 width="56" height="56"
                 alt="Obsidian Root™"
                 decoding="async">
            <div class="mw-mc-or-full-tile-text">
                <span class="mw-mc-or-tile-name">Obsidian Root™</span>
                <span class="mw-mc-or-tile-status"><?php echo htmlspecialchars($orStatusLabel); ?></span>
            </div>
            <?php if ($orStatus !== 'none'): ?>
            <svg class="mw-mc-or-tile-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            <?php endif; ?>
        </button>

        <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
        <!-- ── Card Action Footer: Clock In / Timer + Complete ── -->
        <div class="mw-mc-card-footer" data-footer-stop="<?php echo (int)$stop['stop_id']; ?>" data-footer-visit="<?php echo $footerVisitId; ?>">
            <div class="mw-mc-footer-timer" data-footer-timer="<?php echo (int)$stop['stop_id']; ?>" style="display:none;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="mw-mc-footer-elapsed" data-footer-elapsed="<?php echo (int)$stop['stop_id']; ?>">0:00</span>
            </div>
            <button type="button" class="mw-mc-footer-btn mw-mc-footer-btn-clockin" data-footer-clockin="<?php echo (int)$stop['stop_id']; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Clock In
            </button>
            <button type="button" class="mw-mc-footer-btn mw-mc-footer-btn-complete" data-footer-complete="<?php echo (int)$stop['stop_id']; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Complete Job
            </button>
        </div>
        <?php endif; ?>

    </div><!-- /.mw-mc-flip-front -->

    <!-- ── Card Back: Fertilizer Panel ── -->
    <div class="mw-mc-flip-back">
        <div class="mw-mc-fert-panel">
            <div class="mw-mc-fert-panel-header">
                <span class="or-icon or-icon-<?php echo $orIconVariant; ?>" style="width:48px;height:48px;flex-shrink:0;">
                    <img src="/assets/images/programs/obsidian-root-logo.png" width="48" height="48" alt="Obsidian Root™">
                </span>
                <div>
                    <div class="mw-mc-fert-panel-title">Obsidian Root™</div>
                    <div class="mw-mc-fert-panel-sub"><?php echo htmlspecialchars($orStatusLabel); ?></div>
                </div>
                <button type="button" class="mw-mc-fert-close" data-flip-card="<?php echo (int)$stop['stop_id']; ?>">&#x2715;</button>
            </div>
            <div class="mw-mc-fert-panel-body">
                <?php if ($orStatus === 'enrolled'): ?>
                    <div class="mw-mc-fert-stat-row">
                        <div class="mw-mc-fert-stat">
                            <div class="mw-mc-fert-stat-val">—</div>
                            <div class="mw-mc-fert-stat-lbl">Last Applied</div>
                        </div>
                        <div class="mw-mc-fert-stat">
                            <div class="mw-mc-fert-stat-val">—</div>
                            <div class="mw-mc-fert-stat-lbl">Next Due</div>
                        </div>
                        <div class="mw-mc-fert-stat">
                            <div class="mw-mc-fert-stat-val">—</div>
                            <div class="mw-mc-fert-stat-lbl">Applications</div>
                        </div>
                    </div>
                    <button type="button" class="mw-mc-fert-log-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Log Application
                    </button>
                <?php elseif ($orStatus === 'offer'): ?>
                    <div class="mw-mc-fert-none-msg">This client doesn't have an Obsidian Root™ program.<br>Consider offering it today.</div>
                    <button type="button" class="mw-mc-fert-log-btn" style="background: linear-gradient(135deg, #CC0000, #8B0000);">
                        Offer Program
                    </button>
                <?php else: ?>
                    <div class="mw-mc-fert-none-msg">No fertilizer program on file for this property.</div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /.mw-mc-flip-back -->

    </div><!-- /.mw-mc-flip-inner -->

    </div><!-- /.mw-mc-card-body -->

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
                <div class="mw-mc-header-actions">
                    <?php if ($contactPhone): ?>
                    <a href="tel:<?php echo htmlspecialchars($contactPhone); ?>"
                       class="mw-mc-btn-call"
                       data-haptic="tap"
                       aria-label="Call <?php echo htmlspecialchars($clientName ?: 'customer'); ?>"
                       onclick="event.stopPropagation();">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="mw-mc-compact-route mw-mc-compact-go"
                            data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                            data-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>"
                            onclick="event.stopPropagation(); (function(sid){ var isMobile=window.matchMedia('(max-width:991px)').matches||('ontouchstart' in window&&window.innerWidth<=991); if(isMobile&&typeof MwRouteMap!=='undefined'&&MwRouteMap.launchNavToStop){MwRouteMap.launchNavToStop(sid);return;} if(typeof MwRouteMap!=='undefined')MwRouteMap.openToStop(sid); })(<?php echo (int)$stop['stop_id']; ?>);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                        GO
                    </button>
                </div>
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
        <?php endif; ?>

        <?php $compactProfitMargin = $stop['profit_margin'] ?? null; ?>
        <?php if ($compactProfitMargin !== null): ?>
            <div class="mw-mc-profit-bar" title="Est. margin: <?php echo (int)$compactProfitMargin; ?>%">
                <div class="mw-mc-profit-fill" style="width: <?php echo max(0, min(100, $compactProfitMargin)); ?>%; background: <?php echo function_exists('profitBarColor') ? profitBarColor((int)$compactProfitMargin) : '#2D8659'; ?>" data-margin="<?php echo (int)$compactProfitMargin; ?>"></div>
            </div>
        <?php endif; ?>

        <!-- Expandable detail — MUST be inside card-body (not a sibling of it).
             The card is display:flex flex-direction:row, so any direct child of the card
             becomes a horizontal flex item. Putting expand-detail inside card-body keeps
             it in the normal block flow (below compact-main), not squeezing card-body width. -->
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

            <?php if ($lawnSqFtDisplay): ?>
                <div class="mw-mc-lawn-sqft">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 9h18M9 3v18"/></svg>
                    <?php echo htmlspecialchars($lawnSqFtDisplay); ?>
                </div>
            <?php endif; ?>

            <?php if ($lastVisitDisplay): ?>
                <div class="mw-mc-last-visit">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Last visit: <?php echo htmlspecialchars($lastVisitDisplay); ?>
                </div>
            <?php endif; ?>

            <!-- Obsidian Root™ tile — matches Before/After photo tile dimensions -->
            <div class="mw-mc-or-full-tile or-icon-<?php echo $orIconVariant; ?>"
                 data-or-status="<?php echo htmlspecialchars($orStatus); ?>">
                <img src="/assets/images/programs/obsidian-root-logo.png"
                     width="56" height="56"
                     alt="Obsidian Root™"
                     decoding="async">
                <div class="mw-mc-or-full-tile-text">
                    <span class="mw-mc-or-tile-name">Obsidian Root™</span>
                    <span class="mw-mc-or-tile-status"><?php echo htmlspecialchars($orStatusLabel); ?></span>
                </div>
            </div>

            <?php if (!empty($stop['visits'])): ?>
                <?php $multiVisit = count($stop['visits']) > 1; ?>
                <?php foreach ($stop['visits'] as $ev):
                    $evId     = (int)($ev['visit_id'] ?? 0);
                    $evStatus = $ev['visit_status'] ?? 'scheduled';
                    $evDone   = ($evStatus === 'completed' || $evStatus === 'skipped');
                    $evColor  = $serviceColors[$ev['service_type'] ?? ''] ?? '#455A64';
                    $evLabel  = ucfirst(str_replace('_', ' ', $ev['service_type'] ?? ''));
                    $evPillCls = 'mw-mc-pill-' . (($evStatus === 'completed') ? 'done' : (($evStatus === 'in_progress') ? 'active' : 'scheduled'));
                ?>
                <div class="mw-mc-visit-section" data-section-visit="<?php echo $evId; ?>">
                    <?php if ($multiVisit): ?>
                    <div class="mw-mc-visit-section-header">
                        <span class="mw-mc-service-pill <?php echo $evPillCls; ?>"
                              data-section-pill="<?php echo $evId; ?>"
                              style="--pill-color: <?php echo $evColor; ?>; border-left-color: <?php echo $evColor; ?>">
                            <?php if ($evStatus === 'completed'): ?>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($evLabel); ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Photo strip for this specific visit -->
                    <div class="mw-mc-photo-strips" data-visit-strip="<?php echo $evId; ?>"></div>

                    <?php if (!$evDone && $stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
                    <!-- Per-visit action footer — only visible when card is expanded -->
                    <div class="mw-mc-pv-footer" data-pv-footer="<?php echo $evId; ?>">
                        <div class="mw-mc-footer-timer" data-pv-timer="<?php echo $evId; ?>" style="display:none;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span class="mw-mc-footer-elapsed" data-pv-elapsed="<?php echo $evId; ?>">0:00</span>
                        </div>
                        <button type="button" class="mw-mc-footer-btn mw-mc-footer-btn-clockin"
                                data-pv-clockin="<?php echo $evId; ?>" style="display:none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            Clock In
                        </button>
                        <button type="button" class="mw-mc-footer-btn mw-mc-footer-btn-complete"
                                data-pv-complete="<?php echo $evId; ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            Complete Job
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($stopStatus !== 'completed' && $stopStatus !== 'skipped'): ?>
        <!-- ── Card Action Footer: Clock In / Timer + Complete ── -->
        <div class="mw-mc-card-footer" data-footer-stop="<?php echo (int)$stop['stop_id']; ?>" data-footer-visit="<?php echo $footerVisitId; ?>">
            <div class="mw-mc-footer-timer" data-footer-timer="<?php echo (int)$stop['stop_id']; ?>" style="display:none;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="mw-mc-footer-elapsed" data-footer-elapsed="<?php echo (int)$stop['stop_id']; ?>">0:00</span>
            </div>
            <button type="button" class="mw-mc-footer-btn mw-mc-footer-btn-clockin" data-footer-clockin="<?php echo (int)$stop['stop_id']; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Clock In
            </button>
            <button type="button" class="mw-mc-footer-btn mw-mc-footer-btn-complete" data-footer-complete="<?php echo (int)$stop['stop_id']; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Complete Job
            </button>
        </div>
        <?php endif; ?>

    </div><!-- /.mw-mc-card-body -->

    <!-- Hidden camera input for photo capture -->
    <input type="file" class="mw-mc-camera-input" accept="image/*" capture="environment"
           style="display: none;" data-stop-id="<?php echo (int)$stop['stop_id']; ?>">
</div>
<?php endif; ?>
