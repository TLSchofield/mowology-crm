<?php
/**
 * Trial Balance — from the double-entry journal (Phase 1). ΣDR must equal ΣCR.
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

$asOf = $_GET['as_of'] ?? date('Y-m-d');
$tb   = (new ReportingService(getDB()))->getTrialBalance($asOf);
$money = static fn($n) => '$' . number_format((float)$n, 2);

$pageTitle  = 'Trial Balance';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Trial Balance</h1>
        <p class="text-muted mb-0 small">As at <?= htmlspecialchars($asOf) ?>
            &middot; <?= $tb['in_balance'] ? '<span class="text-success">in balance ✓</span>' : '<span class="text-danger">OUT OF BALANCE</span>' ?></p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
        <input type="date" name="as_of" value="<?= htmlspecialchars($asOf) ?>" class="form-control form-control-sm">
        <button class="btn btn-sm btn-primary">Update</button>
        <a href="/crm/accounting/balance-sheet.php" class="btn btn-sm btn-outline-secondary">Balance Sheet</a>
    </form>
</div>

<div class="card mw-card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
            <?php foreach ($tb['accounts'] as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['code'] . ' ' . $a['name']) ?></td>
                    <td class="text-end"><?= $a['debit']  > 0 ? $money($a['debit'])  : '' ?></td>
                    <td class="text-end"><?= $a['credit'] > 0 ? $money($a['credit']) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top">
                    <td>Totals</td>
                    <td class="text-end"><?= $money($tb['total_debit']) ?></td>
                    <td class="text-end"><?= $money($tb['total_credit']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
