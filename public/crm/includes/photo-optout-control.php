<?php
/**
 * Contact page → "Opt this client out of photos". Tim's rule (2026-10-06): all client property
 * photos are usable in marketing unless the client explicitly opts out; this is that switch.
 * Expects $viewContact (the contact row). Renders nothing before migration 1202 adds
 * contacts.photo_optout, or for users who can't edit clients.
 */
if (empty($viewContact['id']) || !array_key_exists('photo_optout', (array)$viewContact)
    || !function_exists('userHasPermission') || !userHasPermission('clients.edit')) {
    return;
}
$__po = (int)$viewContact['photo_optout'] === 1;
?>
<button type="button" class="mw-photo-optout <?= $__po ? 'is-out' : '' ?>" data-contact="<?= (int)$viewContact['id'] ?>" data-out="<?= $__po ? 1 : 0 ?>"
        title="Photos of this client's properties are used in marketing unless they opt out">
  <?= $__po ? 'Photos: opted out (undo)' : 'Opt this client out of photos' ?>
</button>
<?php if (!defined('MW_MEDIA_TAGS_JS')): define('MW_MEDIA_TAGS_JS', 1); ?>
<script src="/crm/js/media-tags.js?v=20261006" defer></script>
<?php endif; ?>
