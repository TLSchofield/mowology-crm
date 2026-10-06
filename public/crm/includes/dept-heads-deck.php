<?php
/**
 * Department heads deck — dashboard top row.
 *
 * Penny (bookkeeper) is live: her numbers and the receipt carousel
 * (/crm/js/bookkeeper-card.js → /crm/api/bookkeeper.php). Every other head shows as a
 * placeholder until its card exists: when includes/<slug>-card.php is there (sam-card.php,
 * otto-card.php, mia-card.php, charlie-card.php) it is shown full-width under Penny instead.
 * Each card guards itself (permission, migration) and renders nothing until it's ready.
 *
 * Shown only to users who can approve expenses, and only once migration 1125 has
 * run. Never breaks the dashboard: any failure renders nothing.
 */
if (!function_exists('userHasPermission') || !userHasPermission('expenses.approve')) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Expenses/Services/BookkeeperDeskService.php';
    $__desk = new BookkeeperDeskService(getDB());
    if (!$__desk->ready()) {
        return;
    }
    $__rate = null;
    try {
        require_once APP_ROOT . '/Modules/Accounting/Services/OwnerFreedomService.php';
        $__rate = (float)((new OwnerFreedomService(getDB()))->settings()['owner_rate'] ?? 0) ?: null;
    } catch (Throwable $__e) { /* no rate → no net saving */ }
    $ps = $__desk->stats($__rate);
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
    $__hi = PennyQuestionService::firstName((array)($user ?? getCurrentUser() ?? []));
    $__badges = ['earned' => [], 'next' => null];
    try {
        require_once APP_ROOT . '/Modules/Expenses/Services/PennyBadgeService.php';
        $__badges = (new PennyBadgeService(getDB()))->badges();
    } catch (Throwable $__e) { /* badges are a bonus — never block the card */ }
} catch (Throwable $__e) {
    error_log('Dept heads deck unavailable: ' . $__e->getMessage());
    return;
}

$__money = fn(?float $v) => $v === null ? '—' : '$' . number_format($v, $v >= 100 ? 0 : 2);
$__toReview = $ps['waiting'] + $ps['drafts'];
$__readyPct = $__toReview > 0 ? min(100, (int)round($ps['ready'] / $__toReview * 100)) : 100;
$__team = [
    ['sam',     'Sam',     'Sales',                     ['New leads & HomeStars requests', 'Quotes waiting on a reply', 'Follow-ups due today']],
    ['otto',    'Otto',    'Operations',                ["Today's crew & route", 'Weather changes', 'GPS / timesheet gaps']],
    ['mia',     'Mia',     'Marketing & relationships', ['Who to reconnect with', 'Quiet property managers', 'Reviews & referrals']],
    ['charlie', 'Charlie', 'Chief of Staff',            ['The one thing that needs you today', '7 am brief from every head']],
];
?>
<div class="mw-heads-deck" id="mw-heads-deck">
  <section class="mw-head-card mw-head-feature" id="mw-penny">
    <div class="mw-head-portrait">
      <img src="/crm/img/heads/penny.jpg" alt="Penny, bookkeeper" width="168" height="168">
      <div>
        <div class="mw-head-name">Penny</div>
        <div class="mw-head-role">Bookkeeper · receipts &amp; expenses</div>
        <span class="mw-head-pill"><i></i>Working</span>
        <div class="mw-head-badges" aria-label="Penny's badges">
          <?php foreach ($__badges['earned'] as $__b): ?>
            <span class="mw-head-badge" title="<?= h($__b['title']) ?>"><?= h($__b['icon']) ?> <?= h($__b['label']) ?></span>
          <?php endforeach; ?>
          <?php if ($__badges['next']): $__n = $__badges['next']; ?>
            <span class="mw-head-badge is-next" title="Next badge — <?= h($__n['title']) ?>">
              <?= h($__n['icon']) ?> <?= h($__n['label']) ?> <small><?= (int)$__n['have'] ?>/<?= (int)$__n['need'] ?></small>
              <i style="width: <?= (int)round($__n['have'] / max(1, $__n['need']) * 100) ?>%"></i>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <div class="mw-head-say">
        Hey<?= $__hi !== '' ? ' ' . h($__hi) : '' ?> —
        <?php if ($ps['ready'] > 0): ?>
          I've prepared <b><?= (int)$ps['ready'] ?> receipt<?= $ps['ready'] === 1 ? '' : 's' ?></b> for you to approve.
        <?php else: ?>
          I'm preparing your receipts now.
        <?php endif; ?>
        <?php if ($ps['drafts'] > 0): ?> I'm working through the <b><?= (int)$ps['drafts'] ?> draft<?= $ps['drafts'] === 1 ? '' : 's' ?></b>.<?php endif; ?>
        <?php if ($ps['gst_stuck'] >= 1): ?>
          There's <b><?= h($__money($ps['gst_stuck'])) ?> of GST</b> you can claim back sitting in receipts nobody has approved yet.
        <?php endif; ?>
      </div>

      <div class="mw-head-stats">
        <div class="mw-head-stat">
          <div class="mw-k">Waiting for you</div>
          <div class="mw-v"><?= (int)$ps['ready'] ?> <small>ready</small></div>
          <div class="mw-head-bar"><span style="width: <?= $__readyPct ?>%"></span></div>
          <div class="mw-n"><?= (int)$ps['waiting'] ?> submitted · <b><?= (int)$ps['drafts'] ?> draft<?= $ps['drafts'] === 1 ? '' : 's' ?></b> in the backlog</div>
        </div>
        <div class="mw-head-stat">
          <div class="mw-k">Handled by code alone</div>
          <div class="mw-v"><?= (int)$ps['code_alone_pct'] ?>% <small>/ goal 80%</small></div>
          <div class="mw-head-bar"><span style="width: <?= min(100, (int)$ps['code_alone_pct']) ?>%"></span></div>
          <div class="mw-n">Rises as I learn your vendors — no AI cost for those</div>
        </div>
        <div class="mw-head-stat">
          <div class="mw-k">Right first time</div>
          <div class="mw-v"><?= $ps['right_first_time'] === null ? '—' : (int)$ps['right_first_time'] . '%' ?></div>
          <div class="mw-n">Category, judged on <?= h($ps['right_source']) ?></div>
        </div>
        <div class="mw-head-stat is-money">
          <div class="mw-k">Net saving this month</div>
          <div class="mw-v"><?= $ps['net_saving_month'] === null ? '—' : h($__money($ps['net_saving_month'])) ?></div>
          <div class="mw-n">
            <?php if ($ps['net_saving_month'] === null): ?>
              Starts with your first approvals · AI so far <?= h($__money($ps['ai_cost_month'])) ?>
            <?php else: ?>
              ~<?= h((string)$ps['hours_saved_month']) ?> h of your time, minus <?= h($__money($ps['ai_cost_month'])) ?> AI
            <?php endif; ?>
          </div>
        </div>
        <div class="mw-head-stat is-alert">
          <div class="mw-k">GST to claim back</div>
          <div class="mw-v"><?= h($__money($ps['gst_stuck'])) ?></div>
          <div class="mw-n">Last 60 days, in unapproved receipts · <?= h($__money($ps['gst_confirmed'])) ?> already approved</div>
        </div>
        <div class="mw-head-stat">
          <div class="mw-k">Job costing</div>
          <div class="mw-v"><?= (int)$ps['job_costing_pct'] ?>% <small>on a job</small></div>
          <div class="mw-head-bar"><span style="width: <?= min(100, (int)$ps['job_costing_pct']) ?>%"></span></div>
          <div class="mw-n">Last 90 days — I match the rest by time &amp; place</div>
        </div>
      </div>

      <div class="mw-pq" id="mw-pq" hidden></div>

      <div class="mw-rc" id="mw-rc" aria-live="polite" data-categories="<?= h(json_encode(array_values(EXPENSE_ACCOUNTING_CATEGORIES))) ?>" data-backlog="<?= (int)$__toReview ?>" data-name="<?= h($__hi) ?>">
        <div class="mw-rc-empty">Loading receipts…</div>
      </div>

      <div class="mw-head-foot">
        <span>Learning from every approval: store locations · item names · your fuel &amp; EGO rules</span>
        <a class="btn btn-sm btn-success" href="/crm/expenses_appstack.php">All receipts →</a>
      </div>
    </div>
  </section>

  <?php $__live = array_values(array_filter(array_column($__team, 0), fn($__s) => is_file(__DIR__ . '/' . $__s . '-card.php'))); ?>
  <?php if (count($__live) < count($__team)): ?>
  <div class="mw-heads-side">
    <?php foreach ($__team as [$__slug, $__name, $__role, $__items]): if (in_array($__slug, $__live, true)) continue; ?>
    <section class="mw-head-card mw-head-soon">
      <img class="mw-head-face" src="/crm/img/heads/<?= $__slug ?>.jpg" alt="<?= h($__name) ?>, <?= h($__role) ?>" width="96" height="96">
      <div class="mw-head-nm"><?= h($__name) ?></div>
      <div class="mw-head-role"><?= h($__role) ?></div>
      <span class="mw-head-pill is-planned"><i></i>Planned</span>
      <ul><?php foreach ($__items as $__it): ?><li><?= h($__it) ?></li><?php endforeach; ?></ul>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($__live): ?>
  <div class="mw-heads-live">
    <?php foreach ($__live as $__slug) { include __DIR__ . '/' . $__slug . '-card.php'; } ?>
  </div>
  <?php endif; ?>
</div>
<script src="<?= function_exists('_av') ? _av('/crm/js/bookkeeper-card.js') : '/crm/js/bookkeeper-card.js' ?>" defer></script>
