<?php
/**
 * Cost Drill-Down (Great Game of Business) — pivot expenses from the journal by
 * cost_type, service line, or job, over a date range. Same ledger, any axis.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
require_once APP_ROOT . '/Modules/Accounting/Services/ReportingService.php';

$from    = $_GET['date_from'] ?? date('Y-01-01');
$to      = $_GET['date_to']   ?? date('Y-m-d');
$groupBy = in_array($_GET['group_by'] ?? '', ['cost_type', 'service_type', 'job'], true) ? $_GET['group_by'] : 'cost_type';
$labels  = ['cost_type' => 'Cost type', 'service_type' => 'Service line', 'job' => 'Job'];

$dd = (new ReportingService(getDB()))->getCostDrillDown($from, $to, $groupBy);
$money = static fn($n) => '$' . number_format((float)$n, 2);
$pct   = static fn($n, $t) => $t > 0 ? number_format(($n / $t) * 100, 1) . '%' : '—';

$pageTitle  = 'Cost Drill-Down';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Cost Drill-Down</h1>
        <p class="text-muted mb-0 small">Expenses by <?= htmlspecialchars($labels[$groupBy]) ?> &middot;
            <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
        <select name="group_by" class="form-select form-select-sm" style="width:auto">
            <?php foreach ($labels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $groupBy === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm">
        <input type="date" name="date_to"   value="<?= htmlspecialchars($to) ?>"   class="form-control form-control-sm">
        <button class="btn btn-sm btn-primary">Update</button>
        <a href="/crm/accounting/balance-sheet.php" class="btn btn-sm btn-outline-secondary">Balance Sheet</a>
    </form>
</div>

<div class="card mw-card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th><?= htmlspecialchars($labels[$groupBy]) ?></th>
                <th class="text-end">Amount</th><th class="text-end">% of total</th></tr></thead>
            <tbody>
            <?php foreach ($dd['groups'] as $g): ?>
                <tr><td><?= htmlspecialchars((string)$g['key']) ?></td>
                    <td class="text-end"><?= $money($g['amount']) ?></td>
                    <td class="text-end text-muted"><?= $pct($g['amount'], $dd['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($dd['groups'])): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No expense data for this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top"><td>Total</td>
                    <td class="text-end"><?= $money($dd['total']) ?></td><td></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
