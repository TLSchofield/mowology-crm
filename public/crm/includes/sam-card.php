<?php
/**
 * Sam, the sales head — his card in the department heads deck (dashboard).
 *
 * Server-rendered: his photo, badges, brain and greeting, and his numbers. The follow-up
 * carousel, new leads and questions load from /crm/api/sales-head.php (sam-card.js).
 * Sam suggests; Tim sends. Nothing on this card sends without Tim's click.
 *
 * Included by dept-heads-deck.php when this file exists. Renders nothing — never breaks
 * the dashboard — until migration 1140 has run, or for users without billing.edit.
 */
if (!function_exists('userHasPermission') || !userHasPermission('billing.edit')) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Sales/Services/SalesDeskService.php';
    $__sd = new SalesDeskService(getDB());
    if (!$__sd->ready()) {
        return;
    }
    $__ss = $__sd->stats();
    $__sname = SalesDeskService::ownerName((array)($user ?? getCurrentUser() ?? []));
    $__sbadges = ['earned' => [], 'next' => null];
    $__sbrain = null;
    $__sbright = 0.5;
    try {
        require_once APP_ROOT . '/Modules/Sales/Services/SamBadgeService.php';
        require_once APP_ROOT . '/Modules/Sales/Services/SamBrainService.php';
        $__sb = new SamBadgeService(getDB());
        $__sbadges = $__sb->badges();
        $__sbright = $__sb->rightFirstTime() ?? 0.5;
        $__sbrain = (new SamBrainService(getDB()))->learned();
    } catch (Throwable $__e) { /* badges and brain are a bonus — never block the card */ }
} catch (Throwable $__e) {
    error_log('Sam card unavailable: ' . $__e->getMessage());
    return;
}
$__sm = fn(float $v) => SalesDeskService::money($v);
?>
<section class="mw-head-card mw-sam" id="mw-sam">
  <div class="mw-sam-portrait">
    <div class="mw-sam-photo">
      <img src="/crm/img/heads/sam.jpg" alt="Sam, sales" width="120" height="120">
      <?php if ($__sbrain !== null): ?>
        <button type="button" class="mw-head-brain" aria-label="Sam's brain: <?= (int)$__sbrain['units'] ?> things learned"
                data-head="Sam"
                data-units="<?= (int)$__sbrain['units'] ?>"
                data-bright="<?= h((string)$__sbright) ?>"
                data-parts="<?= h(json_encode($__sbrain['parts'])) ?>"
                data-since="<?= h(!empty($__sbrain['since']) ? date('M j, Y', strtotime($__sbrain['since'])) : '') ?>"
                data-empty="Nothing learned yet. Every follow-up you send, edit or skip teaches him something."
                data-teach="He glows brighter the more of his drafts you send unchanged"><canvas></canvas></button>
      <?php endif; ?>
    </div>
    <div>
      <div class="mw-head-name">Sam</div>
      <div class="mw-head-role">Sales · quotes &amp; leads</div>
      <span class="mw-head-pill"><i></i>Working</span>
      <div class="mw-head-badges" aria-label="Sam's badges">
        <?php foreach ($__sbadges['earned'] as $__b): ?>
          <span class="mw-head-badge" title="<?= h($__b['title']) ?>"><?= h($__b['icon']) ?> <?= h($__b['label']) ?></span>
        <?php endforeach; ?>
        <?php if ($__sbadges['next']): $__n = $__sbadges['next']; ?>
          <span class="mw-head-badge is-next" title="Next badge — <?= h($__n['title']) ?>">
            <?= h($__n['icon']) ?> <?= h($__n['label']) ?> <small><?= (int)$__n['have'] ?>/<?= (int)$__n['need'] ?></small>
            <i style="width: <?= (int)round($__n['have'] / max(1, $__n['need']) * 100) ?>%"></i>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mw-sam-body">
    <div class="mw-head-say" id="mw-sam-say">
      Hey<?= $__sname !== '' ? ' ' . h($__sname) : '' ?> —
      <?php if ($__ss['waiting_quotes'] > 0): ?>
        there's <b><?= h($__sm($__ss['waiting_amount'])) ?></b> in <?= (int)$__ss['waiting_quotes'] ?> quote<?= $__ss['waiting_quotes'] === 1 ? '' : 's' ?>
        waiting on <?= (int)$__ss['waiting_people'] ?> customer<?= $__ss['waiting_people'] === 1 ? '' : 's' ?>. I'm sorting out who to nudge.
      <?php else: ?>
        no quotes are waiting on a reply right now.
      <?php endif; ?>
      <?php if ($__ss['leads_new'] > 0): ?> And <b><?= (int)$__ss['leads_new'] ?> new lead<?= $__ss['leads_new'] === 1 ? '' : 's' ?></b> to look at.<?php endif; ?>
    </div>

    <div class="mw-head-stats mw-sam-stats">
      <div class="mw-head-stat is-money">
        <div class="mw-k">Waiting on a reply</div>
        <div class="mw-v"><?= h($__sm($__ss['waiting_amount'])) ?></div>
        <div class="mw-n"><?= (int)$__ss['waiting_quotes'] ?> quote<?= $__ss['waiting_quotes'] === 1 ? '' : 's' ?> · <?= (int)$__ss['waiting_people'] ?> customer<?= $__ss['waiting_people'] === 1 ? '' : 's' ?></div>
      </div>
      <div class="mw-head-stat">
        <div class="mw-k">Win rate</div>
        <div class="mw-v"><?= $__ss['win_rate'] === null ? '—' : (int)$__ss['win_rate'] . '%' ?></div>
        <?php if ($__ss['win_rate'] !== null): ?><div class="mw-head-bar"><span style="width: <?= (int)$__ss['win_rate'] ?>%"></span></div><?php endif; ?>
        <div class="mw-n">Last 12 months · <?= (int)$__ss['won'] ?> won, <?= (int)$__ss['lost'] ?> lost or ran out</div>
      </div>
      <div class="mw-head-stat">
        <div class="mw-k">Days to a yes</div>
        <div class="mw-v"><?= $__ss['avg_days'] === null ? '—' : h((string)$__ss['avg_days']) ?> <small>days</small></div>
        <div class="mw-n">Average from sending to accepted</div>
      </div>
      <div class="mw-head-stat">
        <div class="mw-k">Won after a follow-up</div>
        <div class="mw-v"><?= (int)$__ss['won_by_followup'] ?></div>
        <div class="mw-n"><?= $__ss['won_by_followup'] > 0 ? h($__sm($__ss['won_by_followup_amount'])) . ' accepted within ' . SalesDeskService::WON_WINDOW_DAYS . ' days' : 'Counts once you send through me' ?></div>
      </div>
    </div>

    <div class="mw-pq" id="mw-sq" hidden></div>

    <div class="mw-rc mw-sam-rc" id="mw-sam-rc" aria-live="polite" data-name="<?= h($__sname) ?>">
      <div class="mw-rc-empty">Loading follow-ups…</div>
    </div>

    <div class="mw-sam-leads mw-sam-approved" id="mw-sam-approved" hidden></div>

    <div class="mw-sam-leads mw-sam-replies" id="mw-sam-replies" hidden></div>

    <div class="mw-sam-leads mw-sam-asks" id="mw-sam-asks" hidden></div>

    <div class="mw-sam-leads" id="mw-sam-leads" hidden></div>

    <div class="mw-head-foot">
      <span>Learning from every follow-up you send, edit or skip · I never send anything myself</span>
      <span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="mw-sam-testpush"
                title="I push to your iPhone when a customer first opens their quote">Send me a test push</button>
        <a class="btn btn-sm btn-success" href="/crm/quotes_appstack.php">All quotes →</a>
      </span>
    </div>
    <div class="small text-muted mt-2" id="mw-sam-pushdiag" aria-live="polite" hidden></div>
  </div>
</section>
<script src="<?= function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/sam-card.js') : '/crm/js/sam-card.js' ?>" defer></script>
