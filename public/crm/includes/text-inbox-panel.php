<?php
/**
 * "Texts from numbers we don't know" — shown on Yui's card, to the owner only, while any wait (2026-10-22).
 * The Messages bridge (1.2) now sends every one-to-one text; one from a number that is not a customer waits here until
 * Tim links the number to a customer (the texts join that customer's history, so Yui and Sam see them) or marks it
 * personal (deleted, and the bridge never sends that number again). Data: /crm/api/text-inbox.php. Script: text-inbox.js.
 * Renders nothing before migration 1396, for anyone but the owner, or when nothing is waiting.
 */
if (!function_exists('isAdmin') || !isAdmin()) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Sales/Services/TextBridgeService.php';
    $__tbs = new TextBridgeService(getDB());
    if (!$__tbs->inboxReady() || $__tbs->unknownCount() < 1) {
        return;
    }
    $__txiCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
} catch (Throwable $__e) {
    error_log('Text inbox panel unavailable: ' . $__e->getMessage());
    return;
}
?>
<div class="mw-txi" id="mw-txi" data-csrf="<?= h($__txiCsrf) ?>">
  <div class="mw-txi-head">
    <strong>Texts from numbers we don't know</strong>
    <span class="mw-txi-count" id="mw-txi-count"></span>
  </div>
  <p class="mw-txi-help">A customer texting from a new number lands here. Link it to a customer and the texts join their history; mark it personal and they are deleted and never come in again.</p>
  <div id="mw-txi-msg" class="mw-txi-msg" hidden></div>
  <div id="mw-txi-list"><span class="mw-txi-empty">Loading…</span></div>
</div>
<script src="<?= function_exists('_av') ? _av('/crm/js/text-inbox.js') : '/crm/js/text-inbox.js' ?>" defer></script>
