<?php
/**
 * System Health — Ada's page (head of Technology). Admin only.
 *
 * One row per system: green / amber / red / can't tell, when it last worked, the evidence,
 * and — when not green — the hand-off, ready to copy into a developer session or to do
 * yourself. Thin page: everything comes from AdaDeskService::checks().
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Admins only.');
}

$checks = [];
$error = null;
try {
    require_once APP_ROOT . '/Modules/Technology/Services/AdaDeskService.php';
    $checks = (new AdaDeskService(getDB()))->checks();
} catch (Throwable $e) {
    error_log('System Health: ' . $e->getMessage());
    $error = 'Ada could not run her checks. The error is in the server log.';
}
$counts = AdaRules::counts($checks);
$groups = [];
foreach ($checks as $c) $groups[$c['group']][] = $c;
$order = ['Scheduled jobs', 'Connections', 'Security', 'Ada'];
$rank = static fn($g) => ($i = array_search($g, $order, true)) === false ? 9 : $i;
uksort($groups, fn($a, $b) => $rank($a) <=> $rank($b));
$words = ['red' => 'Broken', 'amber' => 'Look at', 'unknown' => "Can't tell", 'green' => 'Working', 'info' => 'Note'];

$pageTitle = 'System Health';
$activePage = 'system-health';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-ada-page">
  <div class="mw-ada-page-head">
    <img src="/crm/img/heads/ada.jpg" alt="Ada" width="64" height="64">
    <div>
      <h1 class="h3 mb-1">System Health</h1>
      <div class="text-muted">Ada checked everything just now. <?= h(AdaRules::headline($counts)) ?>
        She reads and reports; anything to fix goes to a developer session or to you.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="mw-ada-tally">
    <?php foreach (['red', 'amber', 'unknown', 'green'] as $s): ?>
      <span class="mw-ada-chip is-<?= $s ?>"><span class="mw-ada-dot"></span><?= (int)$counts[$s] ?> <?= h(strtolower($words[$s])) ?></span>
    <?php endforeach; ?>
  </div>

  <?php foreach ($groups as $group => $rows): ?>
    <div class="card mw-ada-group">
      <div class="card-header"><h5 class="card-title mb-0"><?= h($group) ?></h5></div>
      <ul class="mw-ada-rows">
        <?php foreach ($rows as $c): ?>
          <li class="mw-ada-row is-<?= h($c['state']) ?>" id="<?= h($c['system']) ?>">
            <span class="mw-ada-dot" aria-hidden="true"></span>
            <div class="mw-ada-main">
              <div class="mw-ada-name"><?= h($c['name']) ?> <small class="mw-ada-state"><?= h($words[$c['state']] ?? $c['state']) ?></small></div>
              <div class="mw-ada-evidence"><?= h($c['evidence']) ?></div>
              <?php if (!empty($c['last_worked'])): ?>
                <div class="mw-ada-when">Last worked <?= h(date('M j, g:i a', strtotime($c['last_worked']))) ?></div>
              <?php endif; ?>
              <?php if (!empty($c['handoff'])): ?>
                <div class="mw-ada-handoff">
                  <span class="mw-ada-to"><?= $c['handoff_to'] === 'tim' ? 'For you' : 'For a developer session' ?></span>
                  <span class="mw-ada-task"><?= h($c['handoff']) ?></span>
                  <button type="button" class="btn btn-sm btn-outline-secondary mw-ada-copy" data-copy="<?= h($c['handoff']) ?>">Copy</button>
                </div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>

  <p class="text-muted small">More in the <a href="/crm/database_appstack.php">Database Manager</a> (cron history, migrations, schema)
    and the <a href="/crm/system-log_appstack.php">System Log</a>.</p>
</div>

<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('.mw-ada-copy');
    if (!b) return;
    var text = b.getAttribute('data-copy') || '';
    var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this:', text); });
    } else {
        window.prompt('Copy this:', text);
    }
});
</script>

<?php include 'includes/appstack_footer.php'; ?>
