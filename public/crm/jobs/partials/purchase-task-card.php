<?php
/**
 * Purchase Task Card Partial
 * /crm/jobs/partials/purchase-task-card.php
 *
 * Renders a single purchase task as a mini-card for the schedule views.
 *
 * Expected variables:
 *   $task  — array from getPurchaseTasksForSchedule() (single task entry)
 *   $mode  — 'week' | 'day' | 'mobile'  (controls layout density)
 */

if (!isset($task) || !is_array($task)) {
    return;
}

$mode = $mode ?? 'week';

$taskId     = (int)$task['id'];
$status     = $task['purchase_status'] ?? 'pending';
$priority   = $task['priority'] ?? 'normal';
$title      = htmlspecialchars($task['title'] ?? 'Supply Run');
$vendor     = htmlspecialchars($task['vendor_name'] ?? '');
$location   = htmlspecialchars($task['location_label'] ?? $task['location_address'] ?? '');
$assignee   = htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned');
$total      = $task['estimated_total'] !== null
    ? '$' . number_format((float)$task['estimated_total'], 2)
    : '';
$items      = $task['items'] ?? [];
$itemCount  = count($items);
$purchased  = array_filter($items, fn($i) => (int)$i['is_purchased'] === 1);
$purchasedCount = count($purchased);

$statusLabel = [
    'pending'    => 'Pending',
    'in_transit' => 'En Route',
    'purchased'  => 'Done',
    'cancelled'  => 'Cancelled',
][$status] ?? ucfirst($status);

$statusClass = [
    'pending'    => 'mw-pt-status--pending',
    'in_transit' => 'mw-pt-status--transit',
    'purchased'  => 'mw-pt-status--done',
    'cancelled'  => 'mw-pt-status--cancelled',
][$status] ?? '';

$priorityClass = $priority === 'urgent' ? 'mw-pt-card--urgent' : '';

$modeClass = 'mw-pt-card--' . $mode;

$taskJson = htmlspecialchars(json_encode([
    'id'            => $taskId,
    'title'         => $task['title'] ?? '',
    'status'        => $status,
    'vendor'        => $task['vendor_name'] ?? '',
    'location'      => $task['location_address'] ?? '',
    'assignee'      => $task['assigned_to_name'] ?? '',
    'itemCount'     => $itemCount,
    'purchasedCount'=> $purchasedCount,
    'total'         => $task['estimated_total'],
]), ENT_QUOTES);
?>
<div class="mw-pt-card <?= $modeClass ?> <?= $priorityClass ?>"
     data-task-id="<?= $taskId ?>"
     data-task='<?= $taskJson ?>'
     onclick="mwExpandPurchaseTask(<?= $taskId ?>)">

  <div class="mw-pt-card-header">
    <span class="mw-pt-icon">
      <i data-feather="shopping-cart"></i>
    </span>
    <span class="mw-pt-title"><?= $title ?></span>
    <?php if ($priority === 'urgent'): ?>
      <span class="mw-pt-urgent-badge">URGENT</span>
    <?php endif; ?>
    <span class="mw-pt-status <?= $statusClass ?>"><?= $statusLabel ?></span>
  </div>

  <?php if ($mode !== 'week' || $vendor || $location): ?>
  <div class="mw-pt-card-meta">
    <?php if ($vendor): ?>
      <span class="mw-pt-meta-item">
        <i data-feather="map-pin"></i> <?= $vendor ?>
      </span>
    <?php endif; ?>
    <?php if ($location && $location !== $vendor): ?>
      <span class="mw-pt-meta-item mw-pt-meta-location"><?= $location ?></span>
    <?php endif; ?>
    <?php if ($mode !== 'week'): ?>
      <span class="mw-pt-meta-item">
        <i data-feather="user"></i> <?= $assignee ?>
      </span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($itemCount > 0): ?>
  <div class="mw-pt-card-items">
    <span class="mw-pt-item-count">
      <?= $purchasedCount ?>/<?= $itemCount ?> items
    </span>
    <?php if ($mode !== 'week' && $itemCount <= 5): ?>
      <ul class="mw-pt-item-list">
        <?php foreach ($items as $item): ?>
          <li class="mw-pt-item <?= (int)$item['is_purchased'] ? 'mw-pt-item--done' : '' ?>">
            <?php if ((int)$item['is_purchased']): ?>
              <i data-feather="check-circle"></i>
            <?php else: ?>
              <i data-feather="circle"></i>
            <?php endif; ?>
            <?= htmlspecialchars($item['description']) ?>
            <?php if ($item['quantity'] != 1 || $item['unit']): ?>
              <span class="mw-pt-item-qty">× <?= number_format((float)$item['quantity'], $item['quantity'] == floor($item['quantity']) ? 0 : 2) ?><?= $item['unit'] ? ' ' . htmlspecialchars($item['unit']) : '' ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($total): ?>
  <div class="mw-pt-card-footer">
    <span class="mw-pt-total"><?= $total ?></span>
    <?php if ($status === 'pending'): ?>
      <button class="mw-pt-btn-transit"
              onclick="event.stopPropagation(); mwPurchaseTaskAction(<?= $taskId ?>, 'in_transit')"
              title="Mark En Route">
        <i data-feather="truck"></i>
      </button>
    <?php elseif ($status === 'in_transit'): ?>
      <button class="mw-pt-btn-done"
              onclick="event.stopPropagation(); mwPurchaseTaskAction(<?= $taskId ?>, 'purchased')"
              title="Mark Purchased">
        <i data-feather="check"></i>
      </button>
    <?php endif; ?>
  </div>
  <?php elseif ($status === 'pending'): ?>
  <div class="mw-pt-card-footer">
    <button class="mw-pt-btn-transit"
            onclick="event.stopPropagation(); mwPurchaseTaskAction(<?= $taskId ?>, 'in_transit')"
            title="Mark En Route">
      <i data-feather="truck"></i>
    </button>
  </div>
  <?php elseif ($status === 'in_transit'): ?>
  <div class="mw-pt-card-footer">
    <button class="mw-pt-btn-done"
            onclick="event.stopPropagation(); mwPurchaseTaskAction(<?= $taskId ?>, 'purchased')"
            title="Mark Purchased">
      <i data-feather="check"></i> Done
    </button>
  </div>
  <?php endif; ?>

</div>
