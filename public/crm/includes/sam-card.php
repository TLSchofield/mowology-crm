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
<section class="mw-head-card mw-sam mw-sam-v2" id="mw-sam">
  <?php
  // Hierarchy (Tim, 2026-11-04): briefing → next action → rows by status → collapsed sections.
  // The numbers, what works, the mulch fact and the push test live under "Sam's numbers".
  $__nL = (int)$__ss['leads_new'];
  $__nQ = (int)$__ss['waiting_quotes'];
  $__nP = (int)$__ss['waiting_people'];
  ?>
  <header class="mw-sam-head">
    <div class="mw-sam-photo">
      <img src="/crm/img/heads/sam.jpg" alt="Sam, sales" width="72" height="72">
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
    <div class="mw-sam-id">
      <div class="mw-head-name">Sam</div>
      <div class="mw-head-role">Sales, quotes and leads</div>
    </div>
    <div class="mw-sam-state" id="mw-sam-state">
      <span class="mw-head-pill"><i></i>Working</span>
      <?php foreach ($__sbadges['earned'] as $__b): ?>
        <span class="mw-head-badge" title="<?= h($__b['title']) ?>"><?= h($__b['icon']) ?> <?= h($__b['label']) ?></span>
      <?php endforeach; ?>
      <?php if ($__sbadges['next']): $__n = $__sbadges['next']; ?>
        <span class="mw-head-badge is-next" title="Next badge: <?= h($__n['title']) ?>">
          <?= h($__n['icon']) ?> <?= h($__n['label']) ?> <small><?= (int)$__n['have'] ?>/<?= (int)$__n['need'] ?></small>
          <i style="width: <?= (int)round($__n['have'] / max(1, $__n['need']) * 100) ?>%"></i>
        </span>
      <?php endif; ?>
    </div>
    <a class="mw-sam-all" href="/crm/quotes_appstack.php">All quotes</a>
  </header>

  <div class="mw-sam-body">
    <div class="mw-sam-brief" id="mw-sam-brief">
      <p class="mw-head-say mw-sam-say" id="mw-sam-say" data-waiting="<?= h($__sm($__ss['waiting_amount'])) ?>" data-people="<?= $__nP ?>" data-leads="<?= $__nL ?>">
        <?php if ($__nQ > 0): ?>
          <b><?= h($__sm($__ss['waiting_amount'])) ?></b> is waiting on <?= $__nP ?> customer<?= $__nP === 1 ? '' : 's' ?>.
        <?php else: ?>
          No quotes are waiting on a reply.
        <?php endif; ?>
        <?php if ($__nL > 0): ?> <?= $__nL ?> new lead<?= $__nL === 1 ? '' : 's' ?> came in.<?php endif; ?>
      </p>

      <div class="mw-sam-first" id="mw-sam-first" hidden></div>

      <div class="mw-sam-rows" id="mw-sam-rows" aria-live="polite">
        <div class="mw-rc-empty">Loading the list…</div>
      </div>
    </div>

    <div class="mw-sam-work" id="mw-sam-work" hidden>
      <button type="button" class="mw-sam-back" id="mw-sam-back">Back to the list</button>
      <div class="mw-rc mw-sam-rc" id="mw-sam-rc" data-name="<?= h($__sname) ?>"></div>
    </div>

    <div class="mw-pq mw-sam-sec" id="mw-sq" hidden></div>

    <details class="mw-sam-sec is-decide" id="mw-sam-requests" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec is-decide" id="mw-sam-acceptances" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec is-decide" id="mw-sam-approved" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec is-decide" id="mw-sam-replies" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec is-decide" id="mw-sam-asks" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec" id="mw-sam-leads" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec" id="mw-sam-renewals" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec" id="mw-sam-quiet" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>
    <details class="mw-sam-sec" id="mw-sam-hygiene" hidden><summary></summary><div class="mw-sam-sec-body"></div></details>

    <details class="mw-sam-sec mw-sam-numbers" id="mw-sam-numbers">
      <summary>Sam's numbers</summary>
      <div class="mw-sam-sec-body">
        <div class="mw-head-stats mw-sam-stats">
          <div class="mw-head-stat is-money">
            <div class="mw-k">Waiting on a reply</div>
            <div class="mw-v"><?= h($__sm($__ss['waiting_amount'])) ?></div>
            <div class="mw-n"><?= $__nQ ?> quote<?= $__nQ === 1 ? '' : 's' ?>, <?= $__nP ?> customer<?= $__nP === 1 ? '' : 's' ?></div>
          </div>
          <div class="mw-head-stat">
            <div class="mw-k">Win rate</div>
            <div class="mw-v"><?= $__ss['win_rate'] === null ? '—' : (int)$__ss['win_rate'] . '%' ?></div>
            <?php if ($__ss['win_rate'] !== null): ?><div class="mw-head-bar"><span style="width: <?= (int)$__ss['win_rate'] ?>%"></span></div><?php endif; ?>
            <div class="mw-n">Last 12 months: <?= (int)$__ss['won'] ?> won, <?= (int)$__ss['lost'] ?> lost or ran out</div>
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

        <?php include __DIR__ . '/sam-mulch-fact.php'; /* mulch price per yard (MulchPricingService) — renders nothing on failure */ ?>
        <?php
        // What works (2026-10-15): per kind of follow-up in the last year — replies within 14 days, wins within 30.
        $__works = [];
        try {
            require_once APP_ROOT . '/Modules/Sales/Services/SamFollowupService.php';
            $__works = array_values(array_filter((new SamFollowupService(getDB()))->templateScores(), fn($w) => $w['sent'] >= 2));
        } catch (Throwable $e) { $__works = []; }
        $__wlabel = ['first_nudge' => 'First nudge', 'second_nudge' => 'Second nudge', 'viewed' => 'After they looked', 'last_call' => 'Last call',
                     'multi' => 'Several quotes', 'reply' => 'Replies'];
        ?>
        <?php if ($__works): ?>
          <p class="mw-sam-works">What works:
            <?= implode('; ', array_map(fn($w) => h($__wlabel[$w['template']] ?? $w['template']) . ' ' . (int)$w['replied'] . '/' . (int)$w['sent'] . ' replied, '
                                           . (int)$w['won'] . ' won', array_slice($__works, 0, 5))) ?>
          </p>
        <?php endif; ?>

        <div class="mw-head-foot">
          <span>Sam learns from every follow-up you send, edit or skip. He never sends anything himself.</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="mw-sam-testpush"
                  title="I push to your iPhone when a customer first opens their quote">Send me a test push</button>
        </div>
        <div class="small mw-sam-meta mt-2" id="mw-sam-pushdiag" aria-live="polite" hidden></div>
      </div>
    </details>
  </div>
</section>
<script src="<?= function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/sam-card.js') : '/crm/js/sam-card.js' ?>" defer></script>
