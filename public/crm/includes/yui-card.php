<?php
/**
 * Yui, the comms / client relations head — her card in the department heads deck (dashboard).
 *
 * Every one-to-one conversation with EXISTING clients: replies waiting (not about a quote —
 * those stay Sam's), approvals with no accepted quote, account details to tidy, renewals and
 * check-ins, and arrears conversations. Server-rendered: her photo, badges and brain. The
 * greeting, numbers and the five sections load from /crm/api/yui.php (yui-card.js), so the
 * dashboard doesn't read the CRM twice.
 * Yui drafts; Tim edits and sends. Nothing on this card sends without Tim's click.
 * Under her sections: Clues (clues-card.js, /crm/api/clues.php) — facts she and Penny spotted
 * in payments and mail, each with Apply / Not right. Shown once migration 1206 has run.
 *
 * Included by dept-heads-deck.php when this file exists. Renders nothing — never breaks the
 * dashboard — until migration 1191 has run, or for users without billing.edit (same as Sam).
 */
if (!function_exists('userHasPermission') || !userHasPermission('billing.edit')) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Comms/Services/YuiDeskService.php';
    if (!(new YuiDeskService(getDB()))->ready()) {
        return;
    }
    $__yname = SalesDeskService::ownerName((array)($user ?? getCurrentUser() ?? []));
    $__ybadges = ['earned' => [], 'next' => null];
    $__ybrain = null;
    $__ybright = 0.5;
    try {
        require_once APP_ROOT . '/Modules/Comms/Services/YuiBadgeService.php';
        require_once APP_ROOT . '/Modules/Comms/Services/YuiBrainService.php';
        $__yb = new YuiBadgeService(getDB());
        $__ybadges = $__yb->badges();
        $__ybright = $__yb->rightFirstTime() ?? 0.5;
        $__ybrain = (new YuiBrainService(getDB()))->learned();
    } catch (Throwable $__e) { /* badges and brain are a bonus — never block the card */ }
    $__yclues = false;
    try {
        require_once APP_ROOT . '/Modules/Comms/Services/ClueService.php';
        $__yclues = (new ClueService(getDB()))->ready();
    } catch (Throwable $__e) { /* clues are a bonus — never block the card */ }
} catch (Throwable $__e) {
    error_log('Yui card unavailable: ' . $__e->getMessage());
    return;
}
?>
<section class="mw-head-card mw-yui" id="mw-yui">
  <div class="mw-yui-portrait">
    <div class="mw-yui-photo">
      <img src="/crm/img/heads/yui.jpg" alt="Yui, comms and client relations" width="120" height="120">
      <?php if ($__ybrain !== null): ?>
        <button type="button" class="mw-head-brain" aria-label="Yui's brain: <?= (int)$__ybrain['units'] ?> things learned"
                data-head="Yui"
                data-units="<?= (int)$__ybrain['units'] ?>"
                data-bright="<?= h((string)$__ybright) ?>"
                data-parts="<?= h(json_encode($__ybrain['parts'])) ?>"
                data-since="<?= h(!empty($__ybrain['since']) ? date('M j, Y', strtotime($__ybrain['since'])) : '') ?>"
                data-empty="Nothing learned yet. Every message you send, edit or mark handled teaches her something."
                data-teach="She glows brighter the more of her drafts you send unchanged"><canvas></canvas></button>
      <?php endif; ?>
    </div>
    <div>
      <div class="mw-head-name">Yui</div>
      <div class="mw-head-role">Comms · client relations</div>
      <span class="mw-head-pill"><i></i>Working</span>
      <div class="mw-head-badges" aria-label="Yui's badges">
        <?php foreach ($__ybadges['earned'] as $__b): ?>
          <span class="mw-head-badge" title="<?= h($__b['title']) ?>"><?= h($__b['icon']) ?> <?= h($__b['label']) ?></span>
        <?php endforeach; ?>
        <?php if ($__ybadges['next']): $__n = $__ybadges['next']; ?>
          <span class="mw-head-badge is-next" title="Next badge — <?= h($__n['title']) ?>">
            <?= h($__n['icon']) ?> <?= h($__n['label']) ?> <small><?= (int)$__n['have'] ?>/<?= (int)$__n['need'] ?></small>
            <i style="width: <?= (int)round($__n['have'] / max(1, $__n['need']) * 100) ?>%"></i>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mw-yui-body">
    <div class="mw-head-say" id="mw-yui-say">Hey<?= $__yname !== '' ? ' ' . h($__yname) : '' ?> — I'm reading through your clients' messages.</div>

    <div class="mw-head-stats mw-yui-stats" id="mw-yui-stats">
      <div class="mw-head-stat"><div class="mw-k">Replies waiting</div><div class="mw-v" data-stat="inbox">—</div><div class="mw-n" data-stat-n="inbox">Not about a quote — those are Sam's</div></div>
      <div class="mw-head-stat"><div class="mw-k">Promises</div><div class="mw-v" data-stat="promises">—</div><div class="mw-n">A yes with no accepted quote</div></div>
      <div class="mw-head-stat is-alert"><div class="mw-k">Overdue 60+ days</div><div class="mw-v" data-stat="arrears_total">—</div><div class="mw-n" data-stat-n="arrears">Penny keeps the numbers; I draft the note</div></div>
      <div class="mw-head-stat"><div class="mw-k">Sent through me</div><div class="mw-v" data-stat="sent_30">—</div><div class="mw-n">Last 30 days</div></div>
    </div>

    <div class="mw-yui-secs" id="mw-yui-secs" aria-live="polite" data-name="<?= h($__yname) ?>">
      <div class="mw-rc-empty">Loading client conversations…</div>
    </div>

    <?php $__srHead = 'yui'; if (is_file(__DIR__ . '/special-requests-panel.php')) include __DIR__ . '/special-requests-panel.php'; ?>

    <?php if ($__yclues): ?>
    <div class="mw-clues" id="mw-clues" aria-live="polite" data-can-decide="<?= function_exists('isAdmin') && isAdmin() ? '1' : '0' ?>"></div>
    <?php endif; ?>

    <div class="mw-head-foot">
      <span>Learning from every message you send, edit or mark handled · I never send anything myself</span>
      <a class="btn btn-sm btn-success" href="/crm/clients_appstack.php">All clients →</a>
    </div>
  </div>
</section>
<script src="<?= function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/yui-card.js') : '/crm/js/yui-card.js' ?>" defer></script>
<?php if ($__yclues): ?>
<script src="<?= function_exists('_av') ? _av('/crm/js/clues-card.js') : '/crm/js/clues-card.js' ?>" defer></script>
<?php endif; ?>
