<?php
/**
 * Penny's card on the crew app (/crm/my-team.php) — her face and her receipt questions.
 *
 * Expects $__pc = ['name' => first name, 'items' => [MissingReceiptService::ask-shaped rows:
 *   id, ask, amount, date, time, vendor, basis_note], 'focus' => ?int item id from a push tap].
 * Buttons: Snap it (receipt camera, pre-linked to the item) · It's already in (pick a recent receipt)
 * · No receipt (lost / vendor didn't give one). Mobile receipts stay capture-only: nothing here
 * approves or sends a receipt — the office does that on the desktop.
 * Behaviour: /crm/js/crew-team.js. Styles: /crm/css/crew-team.css.
 */
$__pcItems = (array)($__pc['items'] ?? []);
$__pcFocus = (int)($__pc['focus'] ?? 0);
$__pcMoney = fn(float $v) => '$' . number_format($v, 2);
?>
<section class="ct-head" id="ct-penny" data-head="penny">
  <div class="ct-head-top">
    <img class="ct-face" src="/crm/img/heads/penny.jpg" alt="Penny" width="64" height="64">
    <div class="ct-head-id">
      <div class="ct-head-name">Penny</div>
      <div class="ct-head-role">Bookkeeper · receipts</div>
    </div>
    <?php if ($__pcItems): ?>
      <span class="ct-count" aria-label="<?= count($__pcItems) ?> receipts to find"><?= count($__pcItems) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$__pcItems): ?>
    <p class="ct-say ct-say-done">All your receipts are in<?= !empty($__pc['name']) ? ', ' . htmlspecialchars((string)$__pc['name']) : '' ?>. Thank you — that makes my job easy.</p>
  <?php else: ?>
    <ul class="ct-asks">
      <?php foreach ($__pcItems as $__i): ?>
        <li class="ct-ask<?= (int)$__i['id'] === $__pcFocus ? ' is-focus' : '' ?>" id="ct-missing-<?= (int)$__i['id'] ?>"
            data-id="<?= (int)$__i['id'] ?>" data-amount="<?= htmlspecialchars((string)$__i['amount']) ?>">
          <p class="ct-say"><?= htmlspecialchars((string)$__i['ask']) ?></p>
          <div class="ct-meta">
            <span class="ct-amount"><?= $__pcMoney((float)$__i['amount']) ?></span>
            <span><?= htmlspecialchars(date('D M j', strtotime((string)$__i['date']))) ?><?= !empty($__i['time']) ? ' · ' . htmlspecialchars((string)$__i['time']) : '' ?></span>
            <?php if (!empty($__i['basis_note'])): ?>
              <span class="ct-why" title="Why Penny is asking you"><?= htmlspecialchars((string)$__i['basis_note']) ?></span>
            <?php endif; ?>
          </div>
          <div class="ct-actions">
            <a class="ct-btn ct-btn-primary" data-act="snap"
               href="/crm/expenses_appstack.php?mode=quick&amp;trigger=camera&amp;penny_missing=<?= (int)$__i['id'] ?>&amp;return=<?= urlencode('/crm/my-team.php') ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              Snap it
            </a>
            <button type="button" class="ct-btn" data-act="already">It's already in</button>
            <button type="button" class="ct-btn ct-btn-quiet" data-act="none">No receipt</button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
