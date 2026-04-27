<?php
/**
 * Purchase Task Card — Unified expandable card for schedule views.
 *
 * Replaces the old compact/active split. All cards start compact;
 * tapping expands the full detail body. No AJAX needed — all content
 * is pre-rendered server-side.
 *
 * Variables expected from parent:
 *   $task  array  Purchase task row from getPurchaseTasksForSchedule()
 *
 * ($isActive is no longer used but harmless if still passed)
 */

$ptId       = (int)$task['id'];
$ptNumber   = htmlspecialchars($task['task_number'] ?? 'PUR-????');
$ptTitle    = htmlspecialchars($task['title'] ?? '');
$ptVendor   = htmlspecialchars($task['vendor_name'] ?? 'Unknown Vendor');
$ptLocation = htmlspecialchars($task['location_label'] ?? $task['location_address'] ?? '');
$ptAddress  = htmlspecialchars($task['location_address'] ?? '');
$ptCity     = htmlspecialchars($task['location_city'] ?? '');
$ptPhone    = htmlspecialchars($task['location_phone'] ?? '');
$ptHoursWd  = $task['hours_weekday']  ?? '';
$ptHoursSat = $task['hours_saturday'] ?? '';
$ptHoursSun = $task['hours_sunday']   ?? '';
$ptLocNotes = htmlspecialchars($task['location_notes'] ?? '');
$ptItems    = (int)($task['items_count'] ?? 0);
$ptEstTotal = $task['estimated_total'] !== null ? '$' . number_format((float)$task['estimated_total'], 2) : null;
$ptAssigned = htmlspecialchars($task['assigned_to_name'] ?? '');
$ptNotes    = htmlspecialchars($task['description'] ?? '');
$ptStatus   = $task['purchase_status'] ?? 'requested';
$ptMode     = $task['procurement_mode'] ?? 'asap';
$ptPriority = $task['priority'] ?? 'normal';
$ptPlanNumber = htmlspecialchars($task['plan_number'] ?? '');
$ptPlanTitle  = htmlspecialchars($task['plan_title'] ?? '');
$ptPlanId     = (int)($task['plan_id'] ?? 0);
$ptContact    = htmlspecialchars(trim($task['contact_name'] ?? ''));
$ptItemList   = $task['items'] ?? [];

// Navigate URL — prefer GPS coords for precision, fall back to address string
$_navTarget = '';
if (!empty($task['lat']) && !empty($task['lng'])) {
    $_navTarget = $task['lat'] . ',' . $task['lng'];
} elseif (!empty($task['location_address'])) {
    $_navTarget = $task['location_address'];
}
$ptNavUrl = $_navTarget
    ? 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($_navTarget) . '&travelmode=driving&dir_action=navigate'
    : '';

// "Open now" — parse "8am-5pm" free-text stored in vendor_locations.hours_*
// Server timezone matches the business's local timezone (single-market CRM).
$ptOpenNow    = null; // null = unknown, true = open, false = closed
$ptHoursToday = '';
$_dow = (int)date('N'); // 1=Mon … 7=Sun
if ($_dow <= 5 && $ptHoursWd)  $ptHoursToday = $ptHoursWd;
elseif ($_dow === 6 && $ptHoursSat) $ptHoursToday = $ptHoursSat;
elseif ($_dow === 7 && $ptHoursSun) $ptHoursToday = $ptHoursSun;
if ($ptHoursToday) {
    $ptOpenNow = false;
    if (stripos($ptHoursToday, 'closed') === false
        && preg_match('/(\d+(?::\d+)?(?:am|pm))\s*[-–]\s*(\d+(?::\d+)?(?:am|pm))/i', $ptHoursToday, $_hm)) {
        $_open  = strtotime($_hm[1]);
        $_close = strtotime($_hm[2]);
        $_now   = time();
        if ($_open !== false && $_close !== false && $_now >= $_open && $_now < $_close) {
            $ptOpenNow = true;
        }
    }
}

// Status display map
$_statusLabels = [
    'requested' => 'Requested',
    'assigned'  => 'Assigned',
    'en_route'  => 'En Route',
    'at_vendor' => 'At Vendor',
    'purchased' => 'Purchased',
    'delivered' => 'Delivered',
    'verified'  => 'Verified',
];
$_statusClasses = [
    'requested' => 'mw-pt-badge-gray',
    'assigned'  => 'mw-pt-badge-blue',
    'en_route'  => 'mw-pt-badge-orange',
    'at_vendor' => 'mw-pt-badge-yellow',
    'purchased' => 'mw-pt-badge-green',
    'delivered' => 'mw-pt-badge-teal',
    'verified'  => 'mw-pt-badge-darkgreen',
];
$ptStatusLabel = $_statusLabels[$ptStatus] ?? ucfirst($ptStatus);
$ptStatusClass = $_statusClasses[$ptStatus] ?? 'mw-pt-badge-gray';

// Procurement mode display
$_modeLabels = [
    'asap'      => 'ASAP',
    'on_route'  => 'On Route',
    'truck_kit' => 'Truck Kit',
];
$ptModeLabel = $_modeLabels[$ptMode] ?? strtoupper($ptMode);

// Action button for current status
$ptActionLabel  = null;
$ptActionAction = null;
switch ($ptStatus) {
    case 'requested':
    case 'assigned':
        $ptActionLabel  = 'Start Run';
        $ptActionAction = 'start_run';
        break;
    case 'en_route':
        $ptActionLabel  = 'At Vendor';
        $ptActionAction = 'arrive_vendor';
        break;
    case 'at_vendor':
        $ptActionLabel  = 'Mark Purchased';
        $ptActionAction = 'complete_purchase';
        break;
    case 'purchased':
        $ptActionLabel  = 'Mark Delivered';
        $ptActionAction = 'deliver';
        break;
}
?>

<div class="mw-pt-card mw-pt-card-expandable"
     data-task-id="<?php echo $ptId; ?>"
     data-expanded="false"
     data-purchase-status="<?php echo htmlspecialchars($ptStatus); ?>"
     onclick="mwExpandPurchaseTask(this, event)">

    <!-- ── Compact Row — always visible ── -->
    <div class="mw-pt-compact-row">
        <svg class="mw-pt-compact-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        <span class="mw-pt-compact-vendor"><?php echo $ptVendor; ?></span>
        <?php if ($ptLocation): ?>
            <span class="mw-pt-compact-loc"><?php echo $ptLocation; ?></span>
        <?php endif; ?>
        <?php if ($ptItems > 0): ?>
            <span class="mw-pt-compact-items"><?php echo $ptItems; ?> item<?php echo $ptItems !== 1 ? 's' : ''; ?></span>
        <?php endif; ?>
        <span class="mw-pt-status-badge <?php echo $ptStatusClass; ?>"><?php echo $ptStatusLabel; ?></span>
        <span class="mw-pt-expand-chevron" aria-hidden="true">&#9658;</span>
    </div>

    <!-- ── Expanded Body — hidden until tapped ── -->
    <div class="mw-pt-expanded-body">

        <!-- Header: task number + priority/mode/status badges -->
        <div class="mw-pt-card-header">
            <div class="mw-pt-card-header-left">
                <svg class="mw-pt-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                <span class="mw-pt-number"><?php echo $ptNumber; ?></span>
            </div>
            <div class="mw-pt-card-header-right">
                <?php if ($ptPriority === 'high'): ?>
                    <span class="mw-pt-priority-badge">High</span>
                <?php endif; ?>
                <span class="mw-pt-mode-badge mw-pt-mode-<?php echo htmlspecialchars($ptMode); ?>"><?php echo $ptModeLabel; ?></span>
                <span class="mw-pt-status-badge <?php echo $ptStatusClass; ?>"><?php echo $ptStatusLabel; ?></span>
            </div>
        </div>

        <div class="mw-pt-card-body">

            <!-- Vendor name + location label -->
            <div class="mw-pt-vendor">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <strong><?php echo $ptVendor; ?></strong>
                <?php if ($ptLocation): ?>
                    <span class="mw-pt-location">&mdash; <?php echo $ptLocation; ?></span>
                <?php endif; ?>
            </div>

            <?php if ($ptAddress): ?>
            <!-- Full address + Navigate button -->
            <div class="mw-pt-address-row">
                <span class="mw-pt-address-text"><?php echo $ptAddress; ?><?php echo $ptCity ? ', ' . $ptCity : ''; ?></span>
                <?php if ($ptNavUrl): ?>
                    <a href="<?php echo htmlspecialchars($ptNavUrl); ?>"
                       class="mw-pt-nav-btn"
                       target="_blank"
                       rel="noopener noreferrer"
                       onclick="event.stopPropagation()">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Navigate
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($ptPhone): ?>
            <!-- Vendor phone (tap to call) -->
            <div class="mw-pt-phone-row">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 8.81 19.79 19.79 0 01.01 2.18 2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg>
                <a href="tel:<?php echo htmlspecialchars($ptPhone); ?>"
                   class="mw-pt-phone-link"
                   onclick="event.stopPropagation()"><?php echo $ptPhone; ?></a>
            </div>
            <?php endif; ?>

            <?php if ($ptHoursWd || $ptHoursSat || $ptHoursSun): ?>
            <!-- Store hours + open/closed indicator -->
            <div class="mw-pt-hours">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="mw-pt-hours-text">
                    <?php
                    $hoursParts = [];
                    if ($ptHoursWd)  $hoursParts[] = 'M&ndash;F: ' . htmlspecialchars($ptHoursWd);
                    if ($ptHoursSat) $hoursParts[] = 'Sat: ' . htmlspecialchars($ptHoursSat);
                    if ($ptHoursSun) $hoursParts[] = 'Sun: ' . htmlspecialchars($ptHoursSun);
                    echo implode(' &middot; ', $hoursParts);
                    ?>
                </span>
                <?php if ($ptOpenNow === true): ?>
                    <span class="mw-pt-open-badge">Open</span>
                <?php elseif ($ptOpenNow === false): ?>
                    <span class="mw-pt-closed-badge">Closed</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($ptPlanNumber && $ptPlanId): ?>
            <!-- Plan/job context -->
            <div class="mw-pt-plan-context">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                For plan
                <a href="/crm/jobs/view.php?id=<?php echo $ptPlanId; ?>"
                   class="mw-pt-plan-link"
                   onclick="event.stopPropagation()"><?php echo $ptPlanNumber; ?></a>
                <?php if ($ptPlanTitle): ?>
                    &mdash; <?php echo $ptPlanTitle; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Meta row: items count, estimated total, assignee, contact -->
            <div class="mw-pt-meta">
                <?php if ($ptItems > 0): ?>
                    <span class="mw-pt-meta-item">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                        <?php echo $ptItems; ?> item<?php echo $ptItems !== 1 ? 's' : ''; ?>
                    </span>
                <?php endif; ?>
                <?php if ($ptEstTotal): ?>
                    <span class="mw-pt-meta-item">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                        <?php echo $ptEstTotal; ?> est.
                    </span>
                <?php endif; ?>
                <?php if ($ptAssigned): ?>
                    <span class="mw-pt-meta-item">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?php echo $ptAssigned; ?>
                    </span>
                <?php endif; ?>
                <?php if ($ptContact && trim($ptContact) !== ''): ?>
                    <span class="mw-pt-meta-item">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <?php echo $ptContact; ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($ptItemList)): ?>
            <!-- Purchase item checklist -->
            <div class="mw-pt-items-list">
                <?php foreach ($ptItemList as $_item):
                    $_itemId    = (int)$_item['id'];
                    $_itemDone  = !empty($_item['is_purchased']);
                    $_itemQty   = rtrim(rtrim(number_format((float)($_item['quantity'] ?? 1), 2, '.', ''), '0'), '.');
                    $_itemUnit  = htmlspecialchars($_item['unit'] ?? '');
                    $_itemPrice = $_item['estimated_unit_price'] !== null
                        ? '$' . number_format((float)$_item['estimated_unit_price'], 2)
                        : '';
                ?>
                <div class="mw-pt-item-row<?php echo $_itemDone ? ' mw-pt-item-done' : ''; ?>"
                     data-item-id="<?php echo $_itemId; ?>">
                    <label class="mw-pt-item-check-label" onclick="event.stopPropagation()">
                        <input type="checkbox"
                               class="mw-pt-item-checkbox"
                               data-item-id="<?php echo $_itemId; ?>"
                               data-task-id="<?php echo $ptId; ?>"
                               <?php echo $_itemDone ? 'checked' : ''; ?>
                               onchange="mwTogglePurchaseItem(this)">
                        <span class="mw-pt-item-name"><?php echo htmlspecialchars($_item['name']); ?></span>
                    </label>
                    <span class="mw-pt-item-qty"><?php echo $_itemQty . ($_itemUnit ? ' ' . $_itemUnit : ''); ?></span>
                    <?php if ($_itemPrice): ?>
                        <span class="mw-pt-item-price"><?php echo $_itemPrice; ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($ptLocNotes): ?>
                <div class="mw-pt-notes mw-pt-loc-notes">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php echo $ptLocNotes; ?>
                </div>
            <?php endif; ?>

            <?php if ($ptNotes): ?>
                <div class="mw-pt-notes"><?php echo $ptNotes; ?></div>
            <?php endif; ?>

        </div><!-- /.mw-pt-card-body -->

        <?php if ($ptActionLabel): ?>
        <div class="mw-pt-card-footer">
            <button type="button"
                    class="mw-pt-action-btn"
                    data-task-id="<?php echo $ptId; ?>"
                    data-action="<?php echo htmlspecialchars($ptActionAction); ?>"
                    onclick="event.stopPropagation(); mwPurchaseTaskAction(<?php echo $ptId; ?>, '<?php echo $ptActionAction; ?>')">
                <?php echo htmlspecialchars($ptActionLabel); ?>
            </button>
        </div>
        <?php endif; ?>

    </div><!-- /.mw-pt-expanded-body -->
</div><!-- /.mw-pt-card-expandable -->
