<?php
/**
 * Kaname's band: the fixed strip across the top of every office page in the Ittai desktop shell (Tim, 2026-11-10:
 * "yes fixed, it's where important alerts and heads' notifications can land"; "the line format should only ever
 * display one subject at a time"). Printed inside appstack_topbar.php under $itShell, before the clock, receipt
 * and search controls, which stay to its right. Reads what the sidebar already loaded ($__itNav, $__itBlocks,
 * $__itOwner); nothing is computed here (KanameBandService). On her own page the subject is still shown.
 */
if (empty($itShell) || !defined('APP_ROOT') || !function_exists('h')) return;
require_once APP_ROOT . '/Services/HeadIdentity.php';
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/KanameBandService.php';
$__kbName = HeadIdentity::chiefName();
$__kb = !empty($__itOwner)
    ? KanameBandService::load(getDB(), (array)($__itNav ?? []), (array)($__itBlocks ?? []), time(), $__kbName)
    : KanameBandService::build([], [], [], time(), $__kbName);
$__kbStates = ['working', 'approval', 'blocked', 'idle', 'unavailable'];
$__kbState = in_array($__kb['state'], $__kbStates, true) ? $__kb['state'] : '';
require_once __DIR__ . '/ittai-icons.php';
itIconSprite();
?>
<div class="it-band" role="region" aria-label="<?= h($__kbName) ?>">
  <a class="it-band-who" href="<?= h(KanameBandService::BRIEF_URL) ?>" title="Open the brief">
    <span class="it-pt it-pt-<?= h($__kbState !== '' ? $__kbState : 'none') ?>"><img src="/crm/img/heads/charlie.jpg" width="28" height="28" alt="" loading="lazy"></span>
    <b><?= h($__kbName) ?></b>
    <?php if ($__kb['count'] > 0): ?><span class="it-chip it-chip-need"><?= h($__kb['word']) ?></span><?php endif; ?>
  </a>
  <a class="it-band-subject is-<?= h($__kb['subject']['kind']) ?>" href="<?= h($__kb['subject']['url']) ?>" title="<?= h($__kb['subject']['text']) ?>">
    <?php if ($__kb['subject']['kind'] === 'alert'): ?><svg class="it-si" width="12" height="12" aria-hidden="true"><use href="#it-st-blocked"/></svg><?php endif; ?>
    <span class="it-band-text"><?= h($__kb['subject']['text']) ?></span>
    <?php if ($__kb['subject']['age'] !== ''): ?><span class="it-band-age"><?= h($__kb['subject']['age']) ?></span><?php endif; ?>
  </a>
  <?php if ($__kb['more'] > 0): ?><a class="it-band-more" href="<?= h(KanameBandService::BRIEF_URL) ?>" aria-label="<?= h($__kb['more'] . ' more alert' . ($__kb['more'] === 1 ? '' : 's')) ?>">+<?= (int)$__kb['more'] ?></a><?php endif; ?>
  <?php if ($__kb['heads']): ?>
  <span class="it-band-heads" aria-label="Heads waiting on you">
    <?php foreach ($__kb['heads'] as $__h): ?>
    <a class="it-band-head it-pt it-pt-<?= h($__h['state']) ?>" href="<?= h($__h['url']) ?>" title="<?= h($__h['name'] . ': ' . $__h['word']) ?>" aria-label="<?= h($__h['name'] . ': ' . $__h['word']) ?>"><img src="/crm/img/heads/<?= h($__h['slug']) ?>.jpg" width="28" height="28" alt="" loading="lazy"><svg class="it-bd it-bd-<?= h($__h['state']) ?>" width="14" height="14" aria-hidden="true"><use href="#it-st-<?= h($__h['state']) ?>"/></svg></a>
    <?php endforeach; ?>
  </span>
  <?php endif; ?>
</div>
