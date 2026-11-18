<?php
/**
 * Special requests — panel on Yui's (client comms) and Otto's (operations) dashboard cards.
 * Included by yui-card.php ($__srHead = 'yui') and otto-card.php ($__srHead = 'otto').
 *
 * Pending requests proposed from client messages (Yui) or pasted by hand (Otto) wait here for
 * Tim's one tap — "Attach to these visits" — which texts the crew leader and pushes the crew.
 * Below: this week's attached visits with who has read it and the crew's answer
 * (done / not done / extra work done → on the visit's extras; "bill by hand" if already invoiced).
 * Loads from /crm/api/special-requests.php?mode=office (special-requests-panel.js).
 *
 * Renders nothing unless ops_settings.special_requests_enabled = '1' and migrations 1295–1299 ran.
 */
$__srHead = isset($__srHead) && in_array($__srHead, ['yui', 'otto'], true) ? $__srHead : 'otto';
try {
    require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestService.php';
    if (!(new SpecialRequestService(getDB()))->enabled()) {
        return;
    }
} catch (Throwable $__e) {
    return;
}
?>
<div class="mw-srp" id="mw-srp-<?= h($__srHead) ?>" data-sr-head="<?= h($__srHead) ?>" aria-live="polite">
  <div class="mw-srp-title">Special requests <small>Client asks about work already on the schedule</small></div>
  <div class="mw-srp-body"><div class="mw-rc-empty">Loading…</div></div>
</div>
<script src="<?= function_exists('_av') ? _av('/crm/js/special-requests-panel.js') : '/crm/js/special-requests-panel.js' ?>" defer></script>
