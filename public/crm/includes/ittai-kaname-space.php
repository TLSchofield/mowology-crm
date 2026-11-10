<?php
/**
 * Kaname's space at the top of every office page in the Ittai desktop shell (Tim, 2026-11-10: "Kaname's space must
 * always be available on the main page"). One line: her count, her current sentence, the way to her brief. Reads
 * only what the sidebar already loaded ($__itNav from HeadNavService; owner-gated there). Nothing on her own page.
 */
if (empty($itShell) || !defined('APP_ROOT')) return;
if (($activePage ?? '') === 'kaname') return;
if (empty($__itOwner)) return;
$__ksHead = (array)((($__itNav ?? [])['heads'] ?? [])['charlie'] ?? []);
require_once APP_ROOT . '/Services/HeadIdentity.php';
$__ksName = HeadIdentity::chiefName();
$__ksWord = (string)($__ksHead['word'] ?? '');
$__ksState = (string)($__ksHead['state'] ?? '');
$__ksLine = trim((string)($__ksHead['line'] ?? ''));
if ($__ksLine === '') $__ksLine = $__ksWord === '' ? 'Nothing is waiting on you.' : '';
// Jobs given to other companies: what needs you, else the day's news (Tim, 2026-11-10: "kaname should know this").
$__ksSub = null;
try {
    require_once APP_ROOT . '/Modules/Subcontract/Services/SubcontractService.php';
    $__ksSub = (new SubcontractService(getDB()))->spaceLine();
} catch (Throwable $__e) { /* tables not on this business yet */ }
require_once __DIR__ . '/ittai-icons.php';
itIconSprite();
?>
<section class="it-kan-space" aria-label="<?= h($__ksName) ?>">
  <img src="/crm/img/heads/charlie.jpg" width="28" height="28" alt="" loading="lazy">
  <b><?= h($__ksName) ?></b><span class="it-tag">AI</span>
  <?php if ($__ksWord !== '' && $__ksState !== ''): ?><span class="it-s it-s-<?= h($__ksState) ?>"><svg class="it-si" width="12" height="12" aria-hidden="true"><use href="#it-st-<?= h($__ksState) ?>"/></svg><?= h($__ksWord) ?></span><?php endif; ?>
  <span class="it-kan-line"><?= h($__ksLine) ?></span>
  <?php if ($__ksSub): ?><a class="it-kan-sub" href="<?= h($__ksSub['url']) ?>" title="<?= h($__ksSub['text']) ?>"><?= h($__ksSub['text']) ?></a><?php endif; ?>
  <a class="it-kan-open" href="/crm/kaname_appstack.php">Open the brief</a>
</section>
