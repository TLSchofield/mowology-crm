<?php
/**
 * Otto — "how to look after it" (included by otto-card.php).
 *
 * Machines from label photos waiting to be added, each machine's service intervals and what
 * is due / nearly due (job-timer hours — an estimate), "Ask Otto to read the manual"
 * (Claude, on click, daily cap; every interval waits for Tim's confirm), stock running low,
 * and storage / safety notes read from product labels with their SDS link.
 * Loads from /crm/api/label-products.php?mode=otto (public/crm/js/otto-care.js).
 * Renders nothing until migration 1225 has run (the JS hides the box when not ready).
 */
?>
<div class="mw-oc" id="mw-otto-care" hidden aria-live="polite"></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/otto-care.js') : '/crm/js/otto-care.js' ?>" defer></script>
