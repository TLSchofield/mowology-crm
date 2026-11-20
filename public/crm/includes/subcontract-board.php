<?php
/**
 * Otto — the Subcontracts carousel (included by otto-card.php): every job given to another company, one at a time,
 * with the stage it is at and the one thing it needs from you. Server-rendered; subcontract-board.js only moves
 * between slides and posts the buttons to /crm/api/subcontract.php. Renders nothing without jobs or tables.
 */
$__sb = [];
try {
    require_once APP_ROOT . '/Modules/Subcontract/Services/SubcontractService.php';
    $__sb = (new SubcontractService(getDB()))->board();
} catch (Throwable $__e) {
    error_log('subcontract-board: ' . $__e->getMessage());
}
if (!$__sb) return;
$__sbWaiting = count(array_filter($__sb, static fn ($r) => in_array($r['action'], ['accept', 'mark_paid', 'dismiss'], true)));
$__sbButtons = ['accept' => 'Accept the work', 'mark_paid' => 'Mark paid', 'dismiss' => 'Clear it'];
?>
<div class="mw-subboard" id="mw-subboard" aria-roledescription="carousel" aria-label="Subcontracts">
  <div class="mw-subboard-head">
    <h3 class="mw-subboard-title">Subcontracts <small><?= count($__sb) ?> open<?= $__sbWaiting ? ' · ' . $__sbWaiting . ' need' . ($__sbWaiting === 1 ? 's' : '') . ' you' : '' ?></small></h3>
    <?php if (count($__sb) > 1): ?>
    <div class="mw-subboard-nav">
      <button type="button" class="mw-subboard-arrow" data-sub-step="-1" aria-label="Previous job">&#8249;</button>
      <span class="mw-subboard-count" aria-live="polite"><span data-sub-index>1</span> of <?= count($__sb) ?></span>
      <button type="button" class="mw-subboard-arrow" data-sub-step="1" aria-label="Next job">&#8250;</button>
    </div>
    <?php endif; ?>
  </div>

  <?php foreach ($__sb as $__i => $__r): ?>
  <article class="mw-subboard-slide"<?= $__i ? ' hidden' : '' ?> aria-label="<?= h($__r['company']) ?>, <?= $__i + 1 ?> of <?= count($__sb) ?>">
    <div class="mw-subboard-who">
      <strong><?= h($__r['company']) ?></strong>
      <span><?= h($__r['title']) ?><?= $__r['address'] !== '' ? ' · ' . h($__r['address']) : '' ?></span>
    </div>

    <ol class="mw-subboard-track" aria-label="Stages">
      <?php foreach ($__r['stages'] as $__s): ?>
        <li class="<?= $__s['current'] ? 'is-current' : ($__s['reached'] ? 'is-done' : '') ?>"<?= $__s['current'] ? ' aria-current="step"' : '' ?>>
          <span class="mw-subboard-dot" aria-hidden="true"><?= $__s['reached'] ? '&#10003;' : '' ?></span>
          <span class="mw-subboard-label"><?= h($__s['label']) ?><span class="sr-only"><?= $__s['current'] ? ' (now)' : ($__s['reached'] ? ' (done)' : ' (not yet)') ?></span></span>
        </li>
      <?php endforeach; ?>
    </ol>

    <p class="mw-subboard-now">
      Now: <strong><?= h($__r['at']) ?></strong><?php if ($__r['last']): ?> · <?= h($__r['last']) ?>, <?= h(date('D M j, g:i A', strtotime((string)$__r['last_at']))) ?><?php endif; ?>
      <?php if ($__r['action'] === 'penny'): ?><br>Their bill is waiting in Penny's review.<?php endif; ?>
    </p>

    <?php if ($__r['photos']): ?>
    <div class="mw-subboard-photos">
      <?php foreach (array_slice($__r['photos'], 0, 4) as $__p): ?>
        <a href="<?= h($__p) ?>" target="_blank" rel="noopener"><img src="<?= h($__p) ?>" alt="Photo from <?= h($__r['company']) ?>" loading="lazy"></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mw-subboard-actions">
      <?php if ($__r['action'] !== null && isset($__sbButtons[$__r['action']])): ?>
        <button type="button" class="btn btn-primary btn-sm" data-sub-board="<?= h($__r['action']) ?>" data-job="<?= (int)$__r['job_id'] ?>"><?= h($__sbButtons[$__r['action']]) ?></button>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h($__r['url']) ?>">Open</a>
    </div>
  </article>
  <?php endforeach; ?>
  <p class="mw-subboard-error" role="alert" hidden></p>
</div>
<script src="<?= function_exists('_av') ? _av('/crm/js/subcontract-board.js') : '/crm/js/subcontract-board.js' ?>" defer></script>
