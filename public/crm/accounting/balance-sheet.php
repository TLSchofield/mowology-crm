<?php
/**
 * Balance Sheet — from the double-entry journal (Phase 1).
 * Assets == Liabilities + Equity + Net Income (tie-out shown).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

// Load ReportingService (locate app root for local + production).
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
require_once APP_ROOT . '/Modules/Accounting/Services/ReportingService.php';

$asOf = $_GET['as_of'] ?? date('Y-m-d');
$bs   = (new ReportingService(getDB()))->getBalanceSheet($asOf);

$money = static fn($n) => '$' . number_format((float)$n, 2);

$pageTitle  = 'Balance Sheet';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Balance Sheet</h1>
        <p class="text-muted mb-0 small">As at <?= htmlspecialchars($asOf) ?> &middot; double-entry journal</p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
        <input type="date" name="as_of" value="<?= htmlspecialchars($asOf) ?>" class="form-control form-control-sm">
        <button class="btn btn-sm btn-primary">Update</button>
        <a href="/crm/accounting/trial-balance.php" class="btn btn-sm btn-outline-secondary">Trial Balance</a>
    </form>
</div>

<?php if (!$bs['balances']): ?>
<div class="alert alert-warning py-2 small">
    Out of balance: assets <?= $money($bs['total_assets']) ?> vs liabilities + equity <?= $money($bs['liabilities_plus_equity']) ?>.
    If empty, run the nightly sync and post opening balances (migration 1067).
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card mw-card h-100">
            <div class="card-header"><strong>Assets</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($bs['assets'] as $a): ?>
                        <tr><td><?= htmlspecialchars($a['code'] . ' ' . $a['name']) ?></td>
                            <td class="text-end"><?= $money($a['balance']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold border-top"><td>Total Assets</td>
                            <td class="text-end"><?= $money($bs['total_assets']) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-3">
        <div class="card mw-card h-100">
            <div class="card-header"><strong>Liabilities &amp; Equity</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($bs['liabilities'] as $l): ?>
                        <tr><td><?= htmlspecialchars($l['code'] . ' ' . $l['name']) ?></td>
                            <td class="text-end"><?= $money($l['balance']) ?></td></tr>
                    <?php endforeach; ?>
                        <tr class="fw-semibold"><td>Total Liabilities</td>
                            <td class="text-end"><?= $money($bs['total_liabilities']) ?></td></tr>
                    <?php foreach ($bs['equity'] as $e): ?>
                        <tr><td><?= htmlspecialchars($e['code'] . ' ' . $e['name']) ?></td>
                            <td class="text-end"><?= $money($e['balance']) ?></td></tr>
                    <?php endforeach; ?>
                        <tr><td>Current-year earnings</td>
                            <td class="text-end"><?= $money($bs['net_income']) ?></td></tr>
                        <tr class="fw-semibold"><td>Total Equity</td>
                            <td class="text-end"><?= $money($bs['equity_with_earnings']) ?></td></tr>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold border-top"><td>Liabilities + Equity</td>
                            <td class="text-end"><?= $money($bs['liabilities_plus_equity']) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
