<?php
/**
 * Kaname's line: her alerts run off HER sidebar row, the way a head's pages and card run off hers (Tim, 2026-11-10:
 * "Kaname's alerts need to run off her sidebar, not in the top nav"; "only ever one subject at a time"). Printed at the
 * top of the page content inside the Ittai shell, owner only; ittai-shell.js levels it with her row and draws the
 * hairline from her portrait on the spine to this card's edge. It stays under the top bar while the page scrolls.
 * Reads what the sidebar already loaded ($__itNav, $__itBlocks, $__itOwner); nothing is computed here
 * (KanameBandService: the newest of the last 24 h of alerts, else her line; +N; heads in approval/blocked).
 */
if (empty($itShell) || !defined('APP_ROOT') || !function_exists('h') || empty($__itOwner)) return;
if (($activePage ?? '') === 'kaname') return;   // her own page is the whole brief
require_once APP_ROOT . '/Services/HeadIdentity.php';
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/KanameBandService.php';
$__kbName = HeadIdentity::chiefName();
$__kb = KanameBandService::load(getDB(), (array)($__itNav ?? []), (array)($__itBlocks ?? []), time(), $__kbName);
require_once __DIR__ . '/ittai-icons.php';
itIconSprite();
?>
<section class="it-kan-line" aria-label="<?= h($__kbName) ?>">
  <a class="it-kan-subject is-<?= h($__kb['subject']['kind']) ?>" href="<?= h($__kb['subject']['url']) ?>" title="<?= h($__kb['subject']['text']) ?>">
    <?php if ($__kb['subject']['kind'] === 'alert'): ?><svg class="it-si" width="12" height="12" aria-hidden="true"><use href="#it-st-blocked"/></svg><?php endif; ?>
    <span class="it-kan-text"><?= h($__kb['subject']['text']) ?></span>
    <?php if ($__kb['subject']['age'] !== ''): ?><span class="it-kan-age"><?= h($__kb['subject']['age']) ?></span><?php endif; ?>
  </a>
  <?php if ($__kb['more'] > 0): ?><a class="it-kan-more" href="<?= h(KanameBandService::BRIEF_URL) ?>" aria-label="<?= h($__kb['more'] . ' more alert' . ($__kb['more'] === 1 ? '' : 's')) ?>">+<?= (int)$__kb['more'] ?></a><?php endif; ?>
  <?php if ($__kb['heads']): ?>
  <span class="it-kan-heads" aria-label="Heads waiting on you">
    <?php foreach ($__kb['heads'] as $__h): ?>
    <a class="it-kan-head it-pt it-pt-<?= h($__h['state']) ?>" href="<?= h($__h['url']) ?>" title="<?= h($__h['name'] . ': ' . $__h['word']) ?>" aria-label="<?= h($__h['name'] . ': ' . $__h['word']) ?>"><img src="/crm/img/heads/<?= h($__h['slug']) ?>.jpg" width="28" height="28" alt="" loading="lazy"><svg class="it-bd it-bd-<?= h($__h['state']) ?>" width="14" height="14" aria-hidden="true"><use href="#it-st-<?= h($__h['state']) ?>"/></svg></a>
    <?php endforeach; ?>
  </span>
  <?php endif; ?>
  <a class="it-kan-open" href="<?= h(KanameBandService::BRIEF_URL) ?>">Open the brief</a>
</section>
