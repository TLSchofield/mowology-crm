<?php
/**
 * Otto — the end-of-day missed-visit review, as a section of Otto's dashboard card
 * (included by otto-card.php). Hidden until something is waiting; the full list and the
 * decided ones are on /crm/ops/missed-visits.php. Data and buttons: public/crm/js/otto-missed.js
 * → /crm/api/otto-missed.php (MissedVisitReviewService). schedule.edit only (admins, managers).
 */
if (!function_exists('userHasPermission') || !userHasPermission('schedule.edit')) {
    return;
}
?>
<div class="mw-omr is-card" id="mw-otto-missed" data-compact="1" hidden aria-live="polite"></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/otto-missed.js') : '/crm/js/otto-missed.js' ?>" defer></script>
