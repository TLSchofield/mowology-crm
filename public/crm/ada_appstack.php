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
$desk = null;
$notice = null;
try {
    require_once APP_ROOT . '/Modules/Technology/Services/AdaDeskService.php';
    require_once APP_ROOT . '/Modules/Technology/Services/AdaRiskRegister.php';
    $desk = new AdaDeskService(getDB());
    // "Mark fixed": Ada rechecks that job on its next run. The problem and evidence are read from her own
    // fresh check, not from the form, and only a red/amber scheduled job can be marked.
    $__act = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
    if (in_array($__act, ['accept_risk', 'release_risk', 'access_reviewed'], true)) {
        // The register (2026-11-03): accept a non-Critical problem for 90 days with a reason, release it, or record
        // that the admin/manager list was reviewed. Level and acceptability come from Ada's own fresh check.
        $sys = (string)($_POST['system'] ?? '');
        $me = (int)($user['id'] ?? 0) ?: null;
        $meName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: (string)($user['username'] ?? 'admin');
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $notice = 'That form expired. Reload the page and try again.';
        } elseif ($__act === 'access_reviewed') {
            $desk->markAccessReviewed($me, $meName);
            header('Location: /crm/ada_appstack.php?reviewed=1#security%3Aaccess-review');
            exit;
        } elseif ($__act === 'release_risk') {
            $desk->risks()->release($sys, 'released', date('Y-m-d H:i:s'));
            header('Location: /crm/ada_appstack.php?released=1#' . rawurlencode($sys));
            exit;
        } else {
            $reason = (string)($_POST['reason'] ?? '');
            $why = AdaRiskRegister::reasonProblem($reason);
            $found = null;
            foreach ($desk->checks() as $c) if ($c['system'] === $sys) { $found = $c; break; }
            if ($why !== null) {
                $notice = $why;
            } elseif ($found === null || empty($found['acceptable'])) {
                $notice = 'Ada will not let that one be accepted — it is Critical, or no longer a problem.';
            } elseif ($desk->risks()->accept($sys, $reason, $me, $meName, date('Y-m-d H:i:s'))) {
                header('Location: /crm/ada_appstack.php?accepted=1#accepted');
                exit;
            } else {
                $notice = 'Could not record that (is migration 1431 run?).';
            }
        }
    }
    if ($__act === 'mark_fixed') {
        $sys = (string)($_POST['system'] ?? '');
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $notice = 'That form expired. Reload the page and try again.';
        } else {
            foreach ($desk->checks() as $c) {
                if ($c['system'] === $sys && !empty($c['fixable']) && strncmp($sys, 'cron:', 5) === 0) {
                    $desk->marks()->mark($sys, (string)$c['problem'], (string)$c['evidence'], (int)($user['id'] ?? 0) ?: null, date('Y-m-d H:i:s'));
                    header('Location: /crm/ada_appstack.php?marked=1#' . rawurlencode($sys));
                    exit;
                }
            }
            $notice = 'Ada found nothing to mark on that job — it may already be fine.';
        }
    }
    $checks = $desk->checks();
    $accepted = $desk->accepted();
    $headline = $desk->headline();
} catch (Throwable $e) {
    error_log('System Health: ' . $e->getMessage());
    $error = 'Ada could not run her checks. The error is in the server log.';
}
$counts = AdaRules::counts($checks);
$accepted = $accepted ?? [];
$headline = $headline ?? AdaRules::headline($counts);
$handoffs = AdaRules::handoffCounts($checks);
$nextThree = array_slice(array_values(array_filter($checks, static fn($c) => in_array($c['state'], ['red', 'amber'], true))), 0, 3);
$canAccept = $desk && $desk->risks()->ready();
$groups = [];
foreach ($checks as $c) $groups[$c['group']][] = $c;
$order = ['Live checks', 'Scheduled jobs', 'Connections', 'Security', 'Ada'];
$rank = static fn($g) => ($i = array_search($g, $order, true)) === false ? 9 : $i;
uksort($groups, fn($a, $b) => $rank($a) <=> $rank($b));
$words = ['red' => 'Broken', 'amber' => 'Look at', 'unknown' => "Can't tell", 'green' => 'Working', 'info' => 'Note'];

$cleared = [];
try { if ($desk) $cleared = $desk->marks()->recent(8); } catch (Throwable $e) { error_log('System Health: ' . $e->getMessage()); }
$canMark = $desk && $desk->marks()->ready();
$checkedAt = date('g:i a');
$pageTitle = 'System Health';
$activePage = 'system-health';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-ada-page">
  <div class="mw-ada-page-head">
    <img src="/crm/img/heads/ada.jpg" alt="Ada" width="64" height="64">
    <div>
      <h1 class="h3 mb-1">System Health</h1>
      <div class="text-muted">Ada checked everything at <?= h($checkedAt) ?> (every time this page loads). <?= h($headline) ?>
        She reads and reports; anything to fix goes to a developer session or to you.</div>
    </div>
  </div>

  <?php if ($notice): ?><div class="alert alert-warning"><?= h($notice) ?></div><?php endif; ?>
  <?php if (!empty($_GET['accepted'])): ?><div class="alert alert-success">Accepted. It stays on this page under "Accepted", out of the counts, the brief and the emails until its review date.</div><?php endif; ?>
  <?php if (!empty($_GET['released'])): ?><div class="alert alert-success">Released. Ada treats it as open again.</div><?php endif; ?>
  <?php if (!empty($_GET['reviewed'])): ?><div class="alert alert-success">Noted. Ada asks again in <?= AdaRules::ACCESS_REVIEW_DAYS ?> days, or sooner if the list changes.</div><?php endif; ?>
  <?php if (!empty($_GET['marked'])): ?><div class="alert alert-success">Marked fixed. Ada will check that job again on its next run and write down what she finds below.</div><?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="mw-ada-tally">
    <?php foreach (['red', 'amber', 'unknown', 'green'] as $s): ?>
      <span class="mw-ada-chip is-<?= $s ?>"><span class="mw-ada-dot"></span><?= (int)$counts[$s] ?> <?= h(strtolower($words[$s])) ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($handoffs['tim'] + $handoffs['developer'] > 0 || $nextThree): ?>
    <div class="mw-ada-decisions">
      <span>For you: <strong><?= (int)$handoffs['tim'] ?></strong></span>
      <span>For a developer session: <strong><?= (int)$handoffs['developer'] ?></strong></span>
      <?php if ($nextThree): ?>
        <span class="mw-ada-next">Next:
          <?php foreach ($nextThree as $i => $c): ?><a href="#<?= h($c['system']) ?>"><?= h($c['name']) ?></a><?= $i < count($nextThree) - 1 ? ' · ' : '' ?><?php endforeach; ?>
        </span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($groups as $group => $rows): ?>
    <div class="card mw-ada-group">
      <div class="card-header"><h5 class="card-title mb-0"><?= h($group) ?></h5></div>
      <ul class="mw-ada-rows">
        <?php foreach ($rows as $c): ?>
          <li class="mw-ada-row is-<?= h($c['state']) ?>" id="<?= h($c['system']) ?>">
            <span class="mw-ada-dot" aria-hidden="true"></span>
            <div class="mw-ada-main">
              <div class="mw-ada-name"><?= h($c['name']) ?> <small class="mw-ada-state"><?= h($words[$c['state']] ?? $c['state']) ?></small>
                <?php if (!empty($c['level'])): ?><small class="mw-ada-level is-<?= h(strtolower($c['level'])) ?>"><?= h($c['level']) ?></small><?php endif; ?></div>
              <div class="mw-ada-evidence"><?= h(AdaRules::withAge((string)$c['evidence'], AdaRules::badSince($c), date('Y-m-d H:i:s'))) ?></div>
              <?php if (!empty($c['basis'])): ?>
                <div class="mw-ada-basis"><?= h($c['basis']) ?></div>
              <?php endif; ?>
              <?php if (!empty($c['last_worked'])): ?>
                <div class="mw-ada-when">Last worked <?= h(date('M j, g:i a', strtotime($c['last_worked']))) ?></div>
              <?php endif; ?>
              <?php if (!empty($c['reviewable'])): ?>
                <form method="post" class="mw-ada-fix" action="/crm/ada_appstack.php">
                  <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
                  <input type="hidden" name="action" value="access_reviewed">
                  <button type="submit" class="btn btn-sm btn-outline-success">Reviewed — this list is right</button>
                  <span class="text-muted small">Switch off anyone who should not be here first (Settings → Users).</span>
                </form>
              <?php endif; ?>
              <?php if ($canAccept && !empty($c['acceptable'])): ?>
                <form method="post" class="mw-ada-fix mw-ada-accept" action="/crm/ada_appstack.php">
                  <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
                  <input type="hidden" name="action" value="accept_risk">
                  <input type="hidden" name="system" value="<?= h($c['system']) ?>">
                  <input type="text" name="reason" class="form-control form-control-sm" maxlength="<?= AdaRiskRegister::REASON_MAX ?>" placeholder="Why this risk is accepted (one line)" required>
                  <button type="submit" class="btn btn-sm btn-outline-secondary">Accept for <?= AdaRiskRegister::REVIEW_DAYS ?> days</button>
                </form>
              <?php elseif (($c['level'] ?? '') === AdaRiskRegister::CRITICAL): ?>
                <div class="mw-ada-basis">Critical: cannot be accepted — it has to be fixed.</div>
              <?php endif; ?>
              <?php if ($canMark && !empty($c['fixable']) && strncmp($c['system'], 'cron:', 5) === 0): ?>
                <form method="post" class="mw-ada-fix" action="/crm/ada_appstack.php">
                  <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
                  <input type="hidden" name="action" value="mark_fixed">
                  <input type="hidden" name="system" value="<?= h($c['system']) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-success">Mark fixed</button>
                  <span class="text-muted small">Ada checks it again on its next run.</span>
                </form>
              <?php endif; ?>
              <?php if (!empty($c['handoff'])): ?>
                <div class="mw-ada-handoff">
                  <span class="mw-ada-to"><?= $c['handoff_to'] === 'tim' ? 'For you' : 'For a developer session' ?></span>
                  <span class="mw-ada-task"><?= h(explode("\n\nThe cPanel line", $c['handoff'])[0]) ?></span>
                  <button type="button" class="btn btn-sm btn-outline-secondary mw-ada-copy" data-copy="<?= h($c['handoff']) ?>">Copy</button>
                </div>
              <?php endif; ?>
              <?php if (!empty($c['config'])): $cf = $c['config']; ?>
                <div class="mw-ada-cron">
                  <div class="mw-ada-cron-head">cPanel → Cron Jobs should have exactly this:</div>
                  <dl class="mw-ada-cron-fields">
                    <?php foreach (['minute' => 'Minute', 'hour' => 'Hour', 'day' => 'Day', 'month' => 'Month', 'weekday' => 'Weekday'] as $k => $label): ?>
                      <div><dt><?= $label ?></dt><dd><code><?= h($cf[$k]) ?></code></dd></div>
                    <?php endforeach; ?>
                  </dl>
                  <div class="mw-ada-cron-cmd"><span>Command</span><code><?= h($cf['command']) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-secondary mw-ada-copy" data-copy="<?= h($cf['command']) ?>">Copy</button></div>
                  <div class="mw-ada-cron-cmd"><span>Whole line</span><code><?= h($cf['line']) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-secondary mw-ada-copy" data-copy="<?= h($cf['line']) ?>">Copy</button></div>
                </div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>

  <?php if ($accepted): ?>
    <div class="card mw-ada-group" id="accepted">
      <div class="card-header"><h5 class="card-title mb-0">Accepted risks</h5>
        <div class="text-muted small">Still true, left out of the counts, the brief and the emails until their review date. Release one to put it back.</div></div>
      <ul class="mw-ada-rows">
        <?php foreach ($accepted as $c): $a = $c['accepted']; ?>
          <li class="mw-ada-row is-<?= h($c['state']) ?>" id="<?= h($c['system']) ?>">
            <span class="mw-ada-dot" aria-hidden="true"></span>
            <div class="mw-ada-main">
              <div class="mw-ada-name"><?= h($c['name']) ?> <small class="mw-ada-state">Accepted</small>
                <?php if (!empty($c['level'])): ?><small class="mw-ada-level is-<?= h(strtolower($c['level'])) ?>"><?= h($c['level']) ?></small><?php endif; ?></div>
              <div class="mw-ada-evidence"><?= h($c['evidence']) ?></div>
              <div class="mw-ada-when">Accepted <?= h(date('M j', strtotime($a['at']))) ?> by <?= h($a['by']) ?> — "<?= h($a['reason']) ?>" · review by <?= h(date('M j, Y', strtotime($a['review_by']))) ?></div>
              <form method="post" class="mw-ada-fix" action="/crm/ada_appstack.php">
                <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
                <input type="hidden" name="action" value="release_risk">
                <input type="hidden" name="system" value="<?= h($c['system']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Release</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($cleared): ?>
    <div class="card mw-ada-group">
      <div class="card-header"><h5 class="card-title mb-0">Rechecked after you marked them fixed</h5></div>
      <ul class="mw-ada-rows">
        <?php foreach ($cleared as $r): $ok = $r['outcome'] === 'cleared'; ?>
          <li class="mw-ada-row is-<?= $ok ? 'green' : 'red' ?>">
            <span class="mw-ada-dot" aria-hidden="true"></span>
            <div class="mw-ada-main">
              <div class="mw-ada-name"><?= h(preg_replace('/^cron:/', '', (string)$r['system'])) ?>
                <small class="mw-ada-state"><?= $ok ? 'Cleared' : 'Came back' ?></small></div>
              <div class="mw-ada-evidence"><?= h((string)$r['outcome_evidence']) ?></div>
              <div class="mw-ada-when">Marked fixed <?= h(date('M j, g:i a', strtotime((string)$r['fixed_at']))) ?> · rechecked <?= h(date('M j, g:i a', strtotime((string)$r['outcome_at']))) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

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
