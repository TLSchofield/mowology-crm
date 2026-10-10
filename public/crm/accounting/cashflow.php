<?php
/**
 * Cash-flow forecast (2026-10-10) — Penny's next 13 weeks: the last bank statement balance,
 * money in (open invoices on the day each client really pays, booked and projected visits),
 * money out (monthly and biweekly bills, tax instalments, everyday spending), and a second
 * "like last year" line using the same weeks' deposits a year ago. Every line says where it
 * came from. Data: CashFlowForecastService.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Cash-flow forecast';
$activePage = 'accounting';
$isAdmin    = isAdmin();
$f = null;
$err = null;
if ($isAdmin) {
    try {
        require_once APP_ROOT . '/Modules/Accounting/Services/RecurringBillService.php';
        require_once APP_ROOT . '/Modules/Accounting/Services/CashFlowForecastService.php';
        $f = (new CashFlowForecastService(getDB()))->forecast();
    } catch (Throwable $e) {
        error_log('cashflow page: ' . $e->getMessage());
        $err = 'Penny could not build the forecast just now.';
    }
}
$money = fn($v) => ($v < 0 ? '−$' : '$') . number_format(abs((float)$v), 0);
$kindLabel = ['invoice' => 'Invoice', 'visit' => 'Booked visit', 'visit_projected' => 'Expected visit', 'bill' => 'Bill', 'tax' => 'Tax', 'everyday' => 'Everyday'];
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Cash-flow forecast</h1>
        <p class="text-muted mb-0 small">Penny's next 13 weeks: what's in the bank, what's coming in and when, and what goes out. Open a week to see every line and where it came from.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/bank-balance-check.php" class="btn btn-sm btn-outline-secondary">Bank balance check</a>
        <a href="/crm/accounting/reports.php" class="btn btn-sm btn-outline-secondary">Reports</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can see the cash-flow forecast.</div>
<?php elseif ($err || !$f): ?>
    <div class="alert alert-danger"><?= h($err ?? 'No forecast.') ?></div>
<?php else:
    $ly = $f['like_last_year'] ?? [];
    $lyBy = [];
    foreach ($ly as $w) $lyBy[$w['start']] = $w;
    $lowLy = null;
    foreach ($ly as $w) if ($lowLy === null || $w['closing'] < $lowLy['closing']) $lowLy = $w;
    $max = 1.0;
    foreach ($f['weeks'] as $w) $max = max($max, abs($w['closing']), abs($lyBy[$w['start']]['closing'] ?? 0));
?>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card mw-cf-stat"><div class="card-body">
            <div class="mw-cf-k">In the bank</div>
            <div class="mw-cf-v"><?= $money($f['opening']) ?></div>
            <div class="mw-cf-n">
                <?php foreach ($f['opening_accounts'] as $a): ?>
                    <div class="<?= $a['counted'] ? '' : 'text-muted' ?>"><?= h($a['name']) ?>: <?= $money($a['balance']) ?> · statement <?= h(date('M j, Y', strtotime($a['as_of']))) ?><?= $a['counted'] ? '' : ' (too old, not counted)' ?></div>
                <?php endforeach; ?>
            </div>
        </div></div></div>
        <div class="col-md-4"><div class="card mw-cf-stat<?= $f['low']['closing'] < $f['floor'] ? ' is-alert' : '' ?>"><div class="card-body">
            <div class="mw-cf-k">Low point — booked money only</div>
            <div class="mw-cf-v"><?= $money($f['low']['closing']) ?></div>
            <div class="mw-cf-n">Week of <?= h(date('M j', strtotime($f['low']['start']))) ?>. Counts only invoices, booked visits and plan visits — no snow, no new sales.</div>
        </div></div></div>
        <div class="col-md-4"><div class="card mw-cf-stat<?= $lowLy && $lowLy['closing'] < $f['floor'] ? ' is-alert' : '' ?>"><div class="card-body">
            <div class="mw-cf-k">Low point — if it goes like last year</div>
            <div class="mw-cf-v"><?= $lowLy ? $money($lowLy['closing']) : '—' ?></div>
            <div class="mw-cf-n"><?= $lowLy ? 'Week of ' . h(date('M j', strtotime($lowLy['start']))) . '. Money in = what was deposited the same weeks last year.' : 'Not enough bank history from last year.' ?></div>
        </div></div></div>
    </div>

    <?php if ($f['chase']): ?>
    <div class="card mb-3"><div class="card-body">
        <h2 class="h6 mb-2">Chase these first</h2>
        <ul class="mb-0">
            <?php foreach ($f['chase'] as $c): ?>
                <li><a href="/crm/invoices/view.php?id=<?= (int)$c['id'] ?>"><?= h($c['label']) ?></a> — <?= $money($c['amount']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div></div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body">
        <h2 class="h6 mb-2">Week by week</h2>
        <div class="table-responsive">
        <table class="table table-sm mw-cf-table">
            <thead><tr><th>Week of</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Balance (booked)</th><th class="text-end">Balance (like last year)</th><th class="mw-cf-barcol"></th></tr></thead>
            <tbody>
            <?php foreach ($f['weeks'] as $w): $l = $lyBy[$w['start']] ?? null; ?>
                <tr class="<?= $w['closing'] < $f['floor'] ? 'mw-cf-low' : '' ?>">
                    <td>
                        <details class="mw-cf-week">
                            <summary><?= h(date('M j', strtotime($w['start']))) ?></summary>
                            <ul class="mw-cf-items">
                                <?php foreach ($w['items'] as $i): ?>
                                    <li class="<?= $i['amount'] < 0 ? 'is-out' : 'is-in' ?>">
                                        <span class="mw-cf-kind"><?= h($kindLabel[$i['kind']] ?? $i['kind']) ?></span>
                                        <?= h($i['label']) ?> <b><?= $money($i['amount']) ?></b>
                                        <small class="text-muted"><?= h($i['why'] ?? '') ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </td>
                    <td class="text-end"><?= $money($w['in']) ?></td>
                    <td class="text-end"><?= $money(-$w['out']) ?></td>
                    <td class="text-end"><b><?= $money($w['closing']) ?></b></td>
                    <td class="text-end"><?= $l ? $money($l['closing']) : '—' ?></td>
                    <td class="mw-cf-barcol"><span class="mw-cf-bar<?= $w['closing'] < 0 ? ' is-neg' : '' ?>" style="width: <?= (int)round(min(100, abs($w['closing']) / $max * 100)) ?>%"></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div></div>

    <div class="row g-3">
        <div class="col-md-6"><div class="card"><div class="card-body">
            <h2 class="h6 mb-2">Everyday spending — biggest payees, last 8 weeks</h2>
            <p class="small text-muted">About <?= $money($f['everyday_weekly']) ?> a week recently. Winter weeks use the same month last year when the bank history has it.</p>
            <ul class="small mb-0"><?php foreach ($f['everyday_top'] as $t): ?><li><?= h($t['payee']) ?> — <?= $money($t['amount']) ?></li><?php endforeach; ?></ul>
        </div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body">
            <h2 class="h6 mb-2">Not counted</h2>
            <ul class="small mb-0">
                <?php foreach ($f['notes'] as $n): ?><li><?= h($n) ?></li><?php endforeach; ?>
                <?php foreach ($f['doubtful'] as $d): ?><li>Doubtful (<?= (int)$d['days'] ?> days overdue): <a href="/crm/invoices/view.php?id=<?= (int)$d['id'] ?>"><?= h($d['label']) ?></a> — <?= $money($d['amount']) ?></li><?php endforeach; ?>
                <li>Tax instalments, payment terms, the alert floor (<?= $money($f['floor']) ?>) and an optional fixed weekly spend are settings (cashflow_*).</li>
            </ul>
        </div></div></div>
    </div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
