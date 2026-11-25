<?php
/**
 * Ada, head of Technology — her card in the department heads deck (dashboard).
 *
 * She keeps the system working and tells Charlie when it is not: scheduled jobs, outside
 * connections (Google, Meta, Apple push, Stripe, truck GPS) and unused logins. Server-rendered
 * from AdaDeskService (a handful of indexed queries). She reads; she never fixes — each problem
 * carries a hand-off on the System Health page (/crm/ada_appstack.php).
 *
 * Included by dept-heads-deck.php when this file exists. Admins only; renders nothing — never
 * breaks the dashboard — if her checks throw.
 */
if ((($user ?? null)['role'] ?? (getCurrentUser()['role'] ?? '')) !== 'admin') {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Technology/Services/AdaDeskService.php';
    $__achecks = (new AdaDeskService(getDB()))->checks();
    $__acounts = AdaRules::counts($__achecks);
    $__aproblems = array_values(array_filter($__achecks, static fn($c) => in_array($c['state'], ['red', 'amber'], true)));
} catch (Throwable $__e) {
    error_log('Ada card unavailable: ' . $__e->getMessage());
    return;
}
$__abad = $__acounts['red'] + $__acounts['amber'];
?>
<section class="mw-head-card mw-ada" id="mw-ada">
  <div class="mw-ada-portrait">
    <div class="mw-ada-photo">
      <img src="/crm/img/heads/ada.jpg" alt="Ada, head of technology" width="120" height="120">
    </div>
    <div>
      <div class="mw-head-name">Ada</div>
      <div class="mw-head-role">Technology</div>
      <span class="mw-head-pill"><i></i>Working</span>
    </div>
  </div>

  <div class="mw-ada-body">
    <div class="mw-head-say"><?= h(AdaRules::headline($__acounts)) ?> I check every scheduled job, connection and login, and say what I see.</div>

    <div class="mw-head-stats mw-ada-stats">
      <div class="mw-head-stat<?= $__acounts['red'] ? ' is-alert' : '' ?>"><div class="mw-k">Broken</div><div class="mw-v"><?= (int)$__acounts['red'] ?></div><div class="mw-n">Failing or missed</div></div>
      <div class="mw-head-stat"><div class="mw-k">To look at</div><div class="mw-v"><?= (int)$__acounts['amber'] ?></div><div class="mw-n">Recovered, slow or never scheduled</div></div>
      <div class="mw-head-stat"><div class="mw-k">Can't tell</div><div class="mw-v"><?= (int)$__acounts['unknown'] ?></div><div class="mw-n">Keep no record of their runs</div></div>
      <div class="mw-head-stat"><div class="mw-k">Working</div><div class="mw-v"><?= (int)$__acounts['green'] ?></div><div class="mw-n">Checked just now</div></div>
    </div>

    <ul class="mw-ada-list">
      <?php if (!$__abad): ?>
        <li class="mw-ada-row is-green"><span class="mw-ada-dot"></span>Everything I watch is working.</li>
      <?php endif; ?>
      <?php foreach (array_slice($__aproblems, 0, 6) as $__c): ?>
        <li class="mw-ada-row is-<?= h($__c['state']) ?>">
          <span class="mw-ada-dot" aria-label="<?= h($__c['state']) ?>"></span>
          <a href="/crm/ada_appstack.php#<?= h(rawurlencode($__c['system'])) ?>"><?= h($__c['evidence']) ?></a>
        </li>
      <?php endforeach; ?>
      <?php if (count($__aproblems) > 6): ?>
        <li class="mw-ada-more"><a href="/crm/ada_appstack.php">+<?= count($__aproblems) - 6 ?> more</a></li>
      <?php endif; ?>
    </ul>

    <div class="mw-head-foot">
      <span>I read and report · I never fix, restart or lock anything myself</span>
      <a class="btn btn-sm btn-success" href="/crm/ada_appstack.php">System health →</a>
    </div>
  </div>
</section>
