<?php
/**
 * Statement vs books (2026-10-14) — for one bank/card account and one statement month: why the books differ
 * from the statement, line by line, adding up to the month's gap to the cent. Read only.
 * Logic: StatementBooksCheckService. Linked from the bank balance check.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('ledger.view');
$user = getCurrentUser();

require_once APP_ROOT . '/Modules/Accounting/Services/StatementBooksCheckService.php';
$svc = new StatementBooksCheckService(getDB());
$accounts = $svc->accounts();
$accountId = (int)($_GET['account'] ?? 0);
if (!isset($accounts[$accountId])) {
    foreach ($accounts as $id => $a) { if ($a['code'] === '1010') { $accountId = $id; break; } }
    if (!isset($accounts[$accountId])) $accountId = (int)array_key_first($accounts);
}
$months = $accountId ? $svc->months($accountId) : [];
$pick = (string)($_GET['month'] ?? '');
$sec = null;
foreach ($months as $m) { if (substr($m['period_from'], 0, 7) === $pick) $sec = $m; }
if (!$sec && $months) $sec = end($months);
$r = $sec ? $svc->check($accountId, $sec['period_from'], $sec['period_to']) : null;

$money = fn($v) => $v === null ? '—' : (($v < 0 ? '−$' : '$') . number_format(abs((float)$v), 2));
$pageTitle  = 'Statement vs books';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Statement vs books</h1>
        <p class="text-muted mb-0 small">One statement month, line by line: what the bank did, what the books did, and every difference — adding up to the month's gap to the cent. Nothing here changes the books.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/bank-balance-check.php" class="btn btn-sm btn-outline-secondary">Bank balance check</a>
        <a href="/crm/accounting/transactions.php" class="btn btn-sm btn-outline-secondary">Transactions</a>
    </div>
</div>

<form method="get" class="d-flex flex-wrap gap-2 mb-3">
    <select name="account" class="form-control form-control-sm w-auto" onchange="this.form.submit()" aria-label="Account">
        <?php foreach ($accounts as $id => $a): ?>
            <option value="<?= (int)$id ?>" <?= $id === $accountId ? 'selected' : '' ?>><?= h($a['code'] . ' ' . $a['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="month" class="form-control form-control-sm w-auto" onchange="this.form.submit()" aria-label="Statement month">
        <?php foreach (array_reverse($months) as $m): $ym = substr($m['period_from'], 0, 7); ?>
            <option value="<?= h($ym) ?>" <?= $sec && $m['period_from'] === $sec['period_from'] ? 'selected' : '' ?>><?= h(date('F Y', strtotime($m['period_from']))) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$r): ?>
    <div class="alert alert-info">No statements imported for this account yet.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3">
            <div class="text-muted small">Statement moved</div>
            <div class="h5 mb-0"><?= $money($r['statement']['move'] ?? null) ?></div>
            <div class="small text-muted"><?= (int)($r['line_counts']['statement'] ?? 0) ?> lines</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3">
            <div class="text-muted small">Books moved</div>
            <div class="h5 mb-0"><?= $money($r['book_move']) ?></div>
            <div class="small text-muted"><?= (int)$r['line_counts']['books'] ?> bank lines held</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3">
            <div class="text-muted small">Books minus bank</div>
            <div class="h5 mb-0 <?= ($r['gap'] !== null && abs($r['gap']) < 0.005) ? 'text-success' : 'text-danger' ?>"><?= $money($r['gap']) ?></div>
            <div class="small text-muted"><?= $r['adds_up'] ? 'Explained to the cent below' : ($r['adds_up'] === false ? 'Does not add up — tell Penny' : 'No closing balance') ?></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3">
            <div class="text-muted small">Lines not imported</div>
            <div class="h5 mb-0"><?= $money($r['missing'] === null ? null : -$r['missing']) ?></div>
            <div class="small text-muted">statement total our lines don't cover</div>
        </div></div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>1. Statement lines the books treat differently</strong>
            <span class="text-muted small"> — <?= count($r['lines']) ?> lines, <?= $money($r['lines_total']) ?></span></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>Line</th><th class="text-end">Bank</th><th class="text-end">Books</th><th class="text-end">Difference</th><th>Why</th></tr></thead>
                <tbody>
                <?php if (!$r['lines']): ?><tr><td colspan="6" class="text-muted">Every line is in the books as the bank shows it.</td></tr><?php endif; ?>
                <?php foreach ($r['lines'] as $l): ?>
                    <tr>
                        <td class="text-nowrap"><?= h($l['date']) ?></td>
                        <td><?= h(mb_substr($l['description'], 0, 60)) ?><?= $l['receipt'] ? ' <a href="/crm/expenses_appstack.php?edit=' . (int)$l['receipt'] . '" class="small">receipt #' . (int)$l['receipt'] . '</a>' : '' ?></td>
                        <td class="text-end text-nowrap"><?= $money($l['expected']) ?></td>
                        <td class="text-end text-nowrap"><?= $money($l['actual']) ?></td>
                        <td class="text-end text-nowrap"><?= $money($l['diff']) ?></td>
                        <td class="small"><?= h($l['why']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>2. Booked to this account but not a statement line</strong>
            <span class="text-muted small"> — <?= count($r['others']) ?> entries, <?= $money($r['others_total']) ?></span></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>From</th><th>What</th><th class="text-end">Effect</th></tr></thead>
                <tbody>
                <?php if (!$r['others']): ?><tr><td colspan="4" class="text-muted">Nothing else touches this account this month.</td></tr><?php endif; ?>
                <?php foreach ($r['others'] as $o): ?>
                    <tr>
                        <td class="text-nowrap"><?= h($o['date']) ?></td>
                        <td class="small"><?= h(str_replace('_', ' ', $o['source'])) ?></td>
                        <td><?= h($o['memo']) ?></td>
                        <td class="text-end text-nowrap"><?= $money($o['effect']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="small text-muted">
        Books minus bank = section 1 (<?= $money($r['lines_total']) ?>) + section 2 (<?= $money($r['others_total']) ?>)<?= abs($r['opening_entries'] ?? 0) > 0.005 ? ' + the year\'s opening entries (' . $money($r['opening_entries']) . ')' : '' ?>
        − lines not imported (<?= $money($r['missing']) ?>) = <?= $money($r['gap']) ?>.
    </p>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
