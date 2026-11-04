<?php
/**
 * Agent Studio — the AI office at a glance. Admin only, read-only.
 *
 * One row per head (who they are, what they have waiting, what they cost, when they last acted), and for the head you
 * pick: identity, skills, the crons that wake it, what it may do on its own, its open tasks, what it did and who
 * approved it, how it scores on the eval cases, and what it cost. Thin page: every number comes from
 * AgentStudioService, which only reads. Nothing here changes a head; switches live in Settings (ops_settings keys
 * agents_kill_all / agents_kill_<head> / agents_policy_enforce).
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Admins only.');
}

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$error = null;
$ready = $switches = $overview = $evals = [];
$detail = null;
try {
    require_once APP_ROOT . '/Modules/Agents/Services/AgentStudioService.php';
    $studio = new AgentStudioService(getDB());
    $ready = $studio->readiness();
    $switches = $studio->switches();
    $overview = $studio->overview();
    $evals = $studio->evals();
    $pick = strtolower((string)($_GET['head'] ?? ''));
    if ($pick !== '' && isset($overview[$pick])) {
        $detail = $overview[$pick] + [
            'skill_rows' => SkillRegistry::forHead($pick),
            'triggers'   => $studio->triggers($pick),
            'autonomy'   => $studio->autonomy($pick),
            'tasks'      => $studio->openTasks($pick),
            'activity'   => $studio->activity($pick),
            'cost'       => $studio->cost($pick),
        ];
    }
} catch (Throwable $ex) {
    error_log('Agent Studio: ' . $ex->getMessage());
    $error = 'The Studio could not load. The error is in the server log.';
}
$missing = array_keys(array_filter($ready, static fn($ok) => !$ok));
$usd = static fn($v): string => $v > 0 ? '$' . number_format((float)$v, $v < 1 ? 3 : 2) : '—';
$when = static fn($t): string => $t ? date('M j, g:i a', strtotime((string)$t)) : '—';

$pageTitle = 'Agent Studio';
$activePage = 'agents';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-ag-page">
  <div class="mw-ag-head">
    <h1 class="h3 mb-1">Agent Studio</h1>
    <div class="text-muted">Your AI office: who they are, what they may do on their own, what they are working on and what they cost. Read-only.</div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

  <?php if ($missing): ?>
    <div class="alert alert-warning">Not set up yet: <?= $e(implode(', ', $missing)) ?>. Run those migrations in Database &rsaquo; Migrations and the matching sections fill in.</div>
  <?php endif; ?>

  <?php if ($switches): ?>
  <div class="mw-ada-tally">
    <span class="mw-ada-chip is-<?= $switches['kill_all'] ? 'red' : 'green' ?>"><span class="mw-ada-dot"></span><?= $switches['kill_all'] ? 'All heads switched off' : 'All heads on' ?></span>
    <?php foreach ($switches['killed_heads'] as $h): ?>
      <span class="mw-ada-chip is-red"><span class="mw-ada-dot"></span><?= $e(ucfirst($h)) ?> switched off</span>
    <?php endforeach; ?>
    <span class="mw-ada-chip is-<?= $switches['enforcing'] ? 'green' : 'amber' ?>"><span class="mw-ada-dot"></span><?= $switches['enforcing'] ? 'Autonomy limits enforced' : 'Autonomy limits recorded only (shadow)' ?></span>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm mb-0 mw-ag-table">
        <thead><tr><th>Head</th><th class="text-right">Skills</th><th class="text-right">Open tasks</th><th class="text-right">Needs you</th><th class="text-right">30-day AI calls</th><th class="text-right">30-day cost</th><th>Last action</th></tr></thead>
        <tbody>
        <?php foreach ($overview as $slug => $o): ?>
          <tr class="<?= $detail && $detail['slug'] === $slug ? 'mw-ag-picked' : '' ?>">
            <td>
              <a class="mw-ag-who" href="?head=<?= $e($slug) ?>">
                <img src="/crm/img/heads/<?= $e($slug) ?>.jpg" alt="" width="32" height="32">
                <span><strong><?= $e($o['name']) ?></strong><small><?= $e($o['role']) ?></small></span>
              </a>
            </td>
            <td class="text-right"><?= (int)$o['skills'] ?></td>
            <td class="text-right"><?= (int)$o['open_tasks'] ?></td>
            <td class="text-right"><?= (int)$o['waiting'] + (int)$o['failed'] ?: '—' ?></td>
            <td class="text-right"><?= (int)$o['calls'] ?: '—' ?></td>
            <td class="text-right"><?= $e($usd($o['cost_usd'])) ?></td>
            <td><?= $e($when($o['last_action'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($detail): $d = $detail; ?>
  <div class="mw-ag-detail">
    <div class="mw-ada-page-head">
      <img src="/crm/img/heads/<?= $e($d['slug']) ?>.jpg" alt="<?= $e($d['name']) ?>" width="64" height="64">
      <div><h2 class="h4 mb-1"><?= $e($d['name']) ?></h2><div class="text-muted"><?= $e($d['role']) ?></div></div>
    </div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Responsible for</h5></div>
      <ul class="mw-ag-list"><?php foreach ($d['responsibilities'] as $r): ?><li><?= $e($r) ?></li><?php endforeach; ?></ul></div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Skills</h5></div>
      <div class="table-responsive"><table class="table table-sm mb-0 mw-ag-table">
        <thead><tr><th>Skill</th><th>What it does</th><th>How</th><th>Gated by</th><th>Eval</th></tr></thead><tbody>
        <?php foreach ($d['skill_rows'] as $k): $ev = $k['eval'] ? ($evals[$k['eval']] ?? null) : null; ?>
          <tr><td><?= $e($k['name']) ?> <small class="text-muted">v<?= (int)$k['version'] ?></small></td><td><?= $e($k['purpose']) ?></td>
              <td><?= $k['deterministic'] ? 'Rules' : 'AI draft' ?></td><td><?= $e($k['action'] ?? '—') ?></td>
              <td><?= $ev ? $e(rtrim(rtrim(number_format($ev['score'], 1), '0'), '.')) . '% of ' . (int)$ev['cases'] : ($k['eval'] ? 'not run yet' : '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">What may it do on its own?</h5></div>
      <?php if ($d['autonomy']): ?>
      <div class="table-responsive"><table class="table table-sm mb-0 mw-ag-table"><thead><tr><th>Action</th><th>Level</th><th></th></tr></thead><tbody>
        <?php foreach ($d['autonomy'] as $a): ?>
          <tr><td><?= $e($a['action']) ?></td><td><span class="mw-ag-level is-<?= (int)$a['level'] ?>"><?= (int)$a['level'] ?></span> <?= $e($a['label']) ?></td><td class="text-muted"><?= $a['default'] ? 'default' : 'set by you' ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><div class="card-body text-muted">Nothing here changes anything. <?= $e($d['name']) ?> reads and reports.</div><?php endif; ?></div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Wakes up on</h5></div>
      <div class="table-responsive"><table class="table table-sm mb-0 mw-ag-table"><thead><tr><th>Scheduled job</th><th>Last run</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($d['triggers'] as $t): ?>
          <tr><td><?= $e($t['key']) ?></td><td><?= $e($when($t['ran_at'])) ?></td><td><?= $e($t['status'] ? $t['status'] . ($t['summary'] ? ' — ' . mb_substr((string)$t['summary'], 0, 90) : '') : 'no run recorded') ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Working on</h5></div>
      <?php if ($d['tasks']): ?><div class="table-responsive"><table class="table table-sm mb-0 mw-ag-table"><thead><tr><th>Task</th><th>State</th><th>Priority</th><th>Due</th></tr></thead><tbody>
        <?php foreach ($d['tasks'] as $t): ?>
          <tr><td><?= $e($t['title'] ?: $t['kind']) ?></td><td><?= $e(str_replace('_', ' ', $t['state'])) ?></td><td><?= $e($t['priority']) ?></td><td><?= $e($when($t['due_at'])) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><div class="card-body text-muted">Nothing open.</div><?php endif; ?></div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Recent actions</h5></div>
      <?php if ($d['activity']): ?><div class="table-responsive"><table class="table table-sm mb-0 mw-ag-table"><thead><tr><th>When</th><th>Action</th><th>Result</th><th>Approved by</th></tr></thead><tbody>
        <?php foreach ($d['activity'] as $a): ?>
          <tr><td><?= $e($when($a['created_at'])) ?></td><td><?= $e($a['action_key']) ?> <small class="text-muted"><?= $e($a['entity_type'] . ' ' . $a['entity_id']) ?></small></td>
              <td><?= $e($a['decision']) ?><?= $a['result'] ? ' — ' . $e(mb_substr((string)$a['result'], 0, 90)) : '' ?></td><td><?= $e($a['approver'] ?? '—') ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><div class="card-body text-muted">No actions recorded yet.</div><?php endif; ?></div>

    <div class="card"><div class="card-header"><h5 class="card-title mb-0">AI cost, last 30 days</h5></div>
      <?php if ($d['cost']): ?><div class="table-responsive"><table class="table table-sm mb-0 mw-ag-table"><thead><tr><th>Skill</th><th>Model</th><th class="text-right">Calls</th><th class="text-right">Problems</th><th class="text-right">Cost</th></tr></thead><tbody>
        <?php foreach ($d['cost'] as $c): ?>
          <tr><td><?= $e($c['skill']) ?></td><td><?= $e($c['model']) ?></td><td class="text-right"><?= (int)$c['calls'] ?></td><td class="text-right"><?= (int)$c['problems'] ?: '—' ?></td><td class="text-right"><?= $e($usd((float)$c['usd'])) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><div class="card-body text-muted">No AI calls recorded.</div><?php endif; ?></div>
  </div>
  <?php endif; ?>
</div>

<?php include 'includes/appstack_footer.php'; ?>
