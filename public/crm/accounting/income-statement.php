<?php
/**
 * Income Statement — from the double-entry journal (Phase 1). Revenue − Expenses.
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

$from = $_GET['date_from'] ?? date('Y-01-01');
$to   = $_GET['date_to']   ?? date('Y-m-d');
$is   = (new ReportingService(getDB()))->getIncomeStatement($from, $to);
$money = static fn($n) => '$' . number_format((float)$n, 2);

$pageTitle  = 'Income Statement';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Income Statement</h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?> &middot; double-entry journal</p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
        <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#is-date-from"
                data-mw-dp-range-group="is-range" data-mw-dp-range-role="start" aria-haspopup="true" aria-expanded="false">
            <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="mw-datepicker-date" data-mw-dp-label></span>
            <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <input type="date" id="is-date-from" name="date_from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm" hidden>
        <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#is-date-to"
                data-mw-dp-range-group="is-range" data-mw-dp-range-role="end" aria-haspopup="true" aria-expanded="false">
            <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="mw-datepicker-date" data-mw-dp-label></span>
            <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <input type="date" id="is-date-to" name="date_to" value="<?= htmlspecialchars($to) ?>" class="form-control form-control-sm" hidden>
        <button class="btn btn-sm btn-primary">Update</button>
        <a href="/crm/accounting/balance-sheet.php" class="btn btn-sm btn-outline-secondary">Balance Sheet</a>
    </form>
</div>

<div class="card mw-card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <tbody>
                <tr class="table-light fw-semibold"><td colspan="2">Revenue</td></tr>
                <?php foreach ($is['revenue'] as $r): ?>
                    <tr><td class="ps-4"><?= htmlspecialchars($r['code'] . ' ' . $r['name']) ?></td>
                        <td class="text-end"><?= $money($r['amount']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="fw-semibold"><td>Total Revenue</td><td class="text-end"><?= $money($is['total_revenue']) ?></td></tr>

                <tr class="table-light fw-semibold"><td colspan="2">Expenses</td></tr>
                <?php foreach ($is['expenses'] as $e): ?>
                    <tr><td class="ps-4"><?= htmlspecialchars($e['code'] . ' ' . $e['name']) ?></td>
                        <td class="text-end"><?= $money($e['amount']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="fw-semibold"><td>Total Expenses</td><td class="text-end"><?= $money($is['total_expenses']) ?></td></tr>
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top"><td>Net Income</td>
                    <td class="text-end"><?= $money($is['net_income']) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
