<?php
/**
 * Penny's "Statements" strip — included by dept-heads-deck.php inside Penny's card.
 * Expects $__scStrip = StatementCoverageService::strip(report). Renders nothing without it.
 *   "September: TD ✓ · Vancity ✓ · Visa ✗ never imported" + the newest gaps.
 */
if (empty($__scStrip) || (empty($__scStrip['accounts']) && empty($__scStrip['gaps']))) return;
$__scBad = (bool)array_filter($__scStrip['accounts'], fn($a) => !$a['ok']) || !empty($__scStrip['gaps']);
?>
<div class="mw-penny-statements<?= $__scBad ? ' is-bad' : '' ?>" title="Every bank and card statement for last month, checked against the running balance on each line.">
  <div class="mw-ps-row">
    <span class="mw-ps-k">📄 Statements</span>
    <b><?= h($__scStrip['month']) ?>:</b>
    <?php foreach ($__scStrip['accounts'] as $__i => $__a): ?>
      <?php if ($__i > 0): ?><span class="mw-ps-sep">·</span><?php endif; ?>
      <?php if ($__a['ok']): ?>
        <span class="mw-ps-ok"><?= h($__a['label']) ?> ✓</span>
      <?php else: ?>
        <span class="mw-ps-bad"><?= h($__a['label']) ?> ✗ <?= h($__a['note']) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
    <a class="mw-ps-link" href="/crm/accounting/bank-import.php#statements-check">Bank import →</a>
  </div>
  <?php if (!empty($__scStrip['gaps'])): ?>
    <ul class="mw-ps-gaps">
      <?php foreach ($__scStrip['gaps'] as $__g): ?>
        <li class="<?= $__g['weak'] ? 'is-weak' : '' ?>"><?= h($__g['text']) ?></li>
      <?php endforeach; ?>
      <?php if ($__scStrip['more_gaps'] > 0): ?><li class="is-weak">+<?= (int)$__scStrip['more_gaps'] ?> more on the bank import page</li><?php endif; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($__scStrip['skipped'])): ?>
    <div class="mw-ps-note"><?= (int)$__scStrip['skipped'] ?> line<?= (int)$__scStrip['skipped'] === 1 ? '' : 's' ?> already in the CRM <?= (int)$__scStrip['skipped'] === 1 ? 'was' : 'were' ?> skipped on re-import this week.</div>
  <?php endif; ?>
</div>
