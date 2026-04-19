<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$pageTitle  = 'System Log';
$activePage = 'system-log';

$db = getDB();

// ── Filters ───────────────────────────────────────────────────────────────────
$filterLevel  = $_GET['level']  ?? '';
$filterSource = $_GET['source'] ?? '';
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($filterLevel && in_array($filterLevel, ['error', 'warning', 'info'], true)) {
    $where[]  = 'level = ?';
    $params[] = $filterLevel;
}
if ($filterSource !== '') {
    $where[]  = 'source = ?';
    $params[] = $filterSource;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Fetch logs ────────────────────────────────────────────────────────────────
$logs = [];
$total = 0;
$sources = [];
try {
    $total = (int) $db->prepare("SELECT COUNT(*) FROM system_log {$whereSQL}")
                      ->execute($params) ? $db->query("SELECT COUNT(*) FROM system_log {$whereSQL}")->fetchColumn() : 0;

    // Re-run properly with PDO
    $countStmt = $db->prepare("SELECT COUNT(*) FROM system_log {$whereSQL}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $logStmt = $db->prepare("SELECT * FROM system_log {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    $logStmt->execute($params);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    $srcStmt = $db->query("SELECT DISTINCT source FROM system_log ORDER BY source");
    $sources = $srcStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $tableError = true;
}

$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

function levelBadge(string $level): string {
    $map = ['error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $cls = $map[$level] ?? 'secondary';
    return '<span class="badge badge-' . $cls . '">' . htmlspecialchars(strtoupper($level)) . '</span>';
}

include __DIR__ . '/includes/appstack_head.php';
?>

<div class="mw-page-header">
  <div class="mw-page-header-left">
    <h1 class="mw-page-title">System Log</h1>
    <p class="mw-page-subtitle">Internal errors, warnings, and events recorded by the system</p>
  </div>
  <div class="mw-page-actions">
    <button class="btn btn-outline-danger btn-sm" onclick="clearLog()" title="Clear all log entries">
      <i data-feather="trash-2"></i> Clear Log
    </button>
  </div>
</div>

<?php if (!empty($tableError)): ?>
    <div class="alert alert-warning">
        <strong>system_log table not found.</strong>
        <a href="/crm/api/run-migration-system-log.php" target="_blank" class="alert-link">Run the migration</a> to create it, then refresh.
    </div>
<?php else: ?>

<!-- Filters -->
<div class="card mw-filter-card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="form-inline">
            <div class="form-group mr-3">
                <label class="mr-2 text-muted" style="font-size:.8rem;">Level</label>
                <select name="level" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">All levels</option>
                    <?php foreach (['error','warning','info'] as $lvl): ?>
                        <option value="<?= $lvl ?>" <?= $filterLevel === $lvl ? 'selected' : '' ?>><?= ucfirst($lvl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-3">
                <label class="mr-2 text-muted" style="font-size:.8rem;">Source</label>
                <select name="source" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">All sources</option>
                    <?php foreach ($sources as $src): ?>
                        <option value="<?= htmlspecialchars($src) ?>" <?= $filterSource === $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filterLevel || $filterSource): ?>
                <a href="/crm/system-log_appstack.php" class="btn btn-link btn-sm text-muted">Clear filters</a>
            <?php endif; ?>
            <span class="ml-auto text-muted" style="font-size:.8rem;"><?= number_format($total) ?> entries</span>
        </form>
    </div>
</div>

<?php if (empty($logs)): ?>
    <div class="card">
        <div class="card-body text-center py-5 text-muted">
            <i data-feather="check-circle" style="width:40px;height:40px;color:var(--mw-lime);margin-bottom:12px;"></i>
            <p class="mb-0">No log entries<?= ($filterLevel || $filterSource) ? ' matching these filters' : '' ?>.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
                <thead class="thead-light">
                    <tr>
                        <th style="width:130px;">Time</th>
                        <th style="width:80px;">Level</th>
                        <th style="width:80px;">Source</th>
                        <th>Message</th>
                        <th style="width:30px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php $ctx = $log['context'] ? json_decode($log['context'], true) : null; ?>
                        <tr class="mw-log-row <?= $log['level'] === 'error' ? 'table-danger' : ($log['level'] === 'warning' ? 'table-warning' : '') ?>">
                            <td class="text-muted" style="white-space:nowrap;"><?= date('M j H:i:s', strtotime($log['created_at'])) ?></td>
                            <td><?= levelBadge($log['level']) ?></td>
                            <td><code style="font-size:.78rem;"><?= htmlspecialchars($log['source']) ?></code></td>
                            <td style="word-break:break-word;"><?= htmlspecialchars($log['message']) ?></td>
                            <td>
                                <?php if ($ctx): ?>
                                    <button class="btn btn-link btn-sm p-0 text-muted mw-log-ctx-btn" data-id="<?= $log['id'] ?>" title="Show context">
                                        <i data-feather="info" style="width:14px;height:14px;"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($ctx): ?>
                            <tr id="ctx-<?= $log['id'] ?>" style="display:none;" class="<?= $log['level'] === 'error' ? 'table-danger' : ($log['level'] === 'warning' ? 'table-warning' : '') ?>">
                                <td colspan="5" style="padding-left:2rem;">
                                    <pre style="font-size:.78rem;margin:0;background:rgba(0,0,0,.04);padding:8px;border-radius:4px;"><?= htmlspecialchars(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-center mt-3">
            <nav>
                <ul class="pagination pagination-sm">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?level=<?= urlencode($filterLevel) ?>&source=<?= urlencode($filterSource) ?>&page=<?= $page - 1 ?>">‹</a></li>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?level=<?= urlencode($filterLevel) ?>&source=<?= urlencode($filterSource) ?>&page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="?level=<?= urlencode($filterLevel) ?>&source=<?= urlencode($filterSource) ?>&page=<?= $page + 1 ?>">›</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.mw-log-ctx-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var row = document.getElementById('ctx-' + this.dataset.id);
        if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
    });
});

function clearLog() {
    if (!confirm('Delete all system log entries? This cannot be undone.')) return;
    fetch('/crm/api/system-log-clear.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) location.reload();
            else alert('Error: ' + (d.error || 'unknown'));
        });
}
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
