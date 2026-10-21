<?php
/**
 * QuickBooks Online — connect, read, and map (plan 2026-10-20, step 1). Nothing is sent to QuickBooks
 * from this page. Thin controller: QboConnectionService does the work.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('settings.edit');
$user = getCurrentUser();
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboConnectionService.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboSyncService.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboExpenseSyncService.php';

$db  = getDB();
$svc = new QboConnectionService($db);
$flash = $_SESSION['qbo_flash'] ?? null;
unset($_SESSION['qbo_flash']);

// Connect is a LINK, not a form: the site's CSP has form-action 'self', and Chrome applies it to a
// form's redirect too, so a POST that redirects to Intuit was silently blocked (2026-10-20).
// The link carries the CSRF token, checked here; it only starts a sign-in.
if (($_GET['connect'] ?? '') === '1') {
    if (!verifyCSRFToken((string)($_GET['t'] ?? ''))) {
        $_SESSION['qbo_flash'] = ['ok' => false, 'message' => 'The page was open too long. Press Connect again.'];
        header('Location: quickbooks.php'); exit;
    }
    try {
        $to = $svc->startUrl($_SESSION);
        error_log('QuickBooks: sending the owner to Intuit sign-in (link)');
        header('Location: ' . $to); exit;
    } catch (Throwable $e) {
        $_SESSION['qbo_flash'] = ['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not start the QuickBooks sign-in.'];
        header('Location: quickbooks.php'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['qbo_flash'] = ['ok' => false, 'message' => 'The page was open too long. Try again.'];
        header('Location: quickbooks.php'); exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'pull') {
            $_SESSION['qbo_flash'] = $svc->pull();
        } elseif ($action === 'disconnect') {
            $_SESSION['qbo_flash'] = $svc->disconnect();
        } elseif ($action === 'send_invoice') {
            $num = trim((string)($_POST['invoice_number'] ?? ''));
            $q = $db->prepare('SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1');
            $q->execute([$num]);
            $iid = (int)$q->fetchColumn();
            $_SESSION['qbo_flash'] = $iid > 0
                ? (new QboSyncService($svc))->sendInvoice($iid)
                : ['ok' => false, 'message' => 'No invoice ' . $num . '.'];
        } elseif ($action === 'auto') {
            $on = ($_POST['auto'] ?? '') === '1';
            $from = trim((string)($_POST['from'] ?? '')) ?: null;
            $qs = new QboSyncService($svc);
            $ok = $qs->setAuto($on, $from);
            if ($ok && $on) $qs->releaseHeld();
            $_SESSION['qbo_flash'] = ['ok' => $ok, 'message' => !$ok ? 'Pick the date to start from.'
                : ($on ? 'Automatic sending is on: invoices issued from ' . $from . ' go to QuickBooks within 15 minutes, with their payments.' : 'Automatic sending is off.')];
        } elseif ($action === 'receipts') {
            $on = ($_POST['receipts'] ?? '') === '1';
            $xs = new QboExpenseSyncService($svc);
            $xs->setReceipts($on);
            if ($on) $xs->releaseHeld();
            $_SESSION['qbo_flash'] = ['ok' => true, 'message' => $on ? 'Receipts are on: approved receipts from the same start date go to QuickBooks as expenses, with their photo.' : 'Receipts are off.'];
        } elseif ($action === 'retry_held') {
            $n = (new QboSyncService($svc))->releaseHeld();
            $_SESSION['qbo_flash'] = ['ok' => true, 'message' => $n . ' held item(s) will be tried again within 15 minutes.'];
        } elseif ($action === 'save_map') {
            $bad = 0;
            foreach (['account', 'tax_code', 'pay_account'] as $kind) {
                foreach ((array)($_POST['map'][$kind] ?? []) as $key => $qboId) {
                    if (!$svc->setMapping($kind, (string)$key, (string)$qboId, (int)($user['id'] ?? 0))) $bad++;
                }
            }
            // A held invoice or receipt waiting on a mapping is tried again now that the mapping changed.
            $released = (new QboSyncService($svc))->releaseHeld();     // 0 when not connected
            $_SESSION['qbo_flash'] = ['ok' => $bad === 0, 'message' => ($bad === 0 ? 'Mapping saved.' : $bad . ' choice(s) were not saved — read from QuickBooks again and pick them once more.')
                                      . ($released ? ' ' . $released . ' held item(s) will be tried again.' : '')];
        }
    } catch (Throwable $e) {
        error_log('QuickBooks page: ' . $e->getMessage());
        $_SESSION['qbo_flash'] = ['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Nothing was changed in QuickBooks.'];
    }
    header('Location: quickbooks.php'); exit;
}

$missing  = $svc->missing();
$status   = $svc->tablesReady() ? $svc->status() : ['connected' => false, 'status' => 'none'];
$cfg      = QboConnectionService::config();
$maps     = $svc->tablesReady() ? $svc->mappings() : ['account' => [], 'tax_code' => []];
$accounts = $status['connected'] ? $svc->ittaiAccounts() : [];
$qAccounts = $status['connected'] ? $svc->remote('account') : [];
$qTax      = $status['connected'] ? $svc->remote('tax_code') : [];
$csrf = generateCSRFToken();
$sync = ($status['connected'] && $svc->tablesReady()) ? new QboSyncService($svc) : null;
$canSend = $sync !== null && $sync->guard() === null;
$sent = []; $problems = []; $auto = ['on' => false, 'from' => null]; $receiptsOn = false;
try {
    $sent = $sync ? $sync->recent(15) : [];
    $problems = $sync ? $sync->problems() : [];
    $auto = $sync ? $sync->auto() : $auto;
    if ($sync) {
        $xsync = new QboExpenseSyncService($svc);
        $receiptsOn = $xsync->receiptsOn();
        $problems = array_merge($problems, $xsync->expenseProblems());
    }
} catch (Throwable $e) { /* migration 1383 not run yet */ }
// What receipts are paid from in Ittai (1010 chequing, 2400 card; 1300 = paid personally, held).
$payCodes = [];
try {
    $payCodes = $db->query("SELECT DISTINCT c.code, c.name FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id
                            JOIN chart_of_accounts c ON c.id = jl.account_id
                            WHERE je.source_type = 'expense' AND je.status = 'posted' AND jl.credit > 0 AND c.code NOT LIKE '130%'
                            ORDER BY c.code")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) { /* no ledger */ }

$pageTitle  = 'QuickBooks';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">QuickBooks Online</h1>
        <p class="text-muted mb-0 small">Keep QuickBooks — Ittai does the typing. This step connects and reads only; nothing is sent to QuickBooks yet.</p>
    </div>
    <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">← Accounting</a>
</div>

<?php if ($flash): ?>
    <div class="alert <?= !empty($flash['ok']) ? 'alert-success' : 'alert-warning' ?>" role="status"><?= h((string)$flash['message']) ?></div>
<?php endif; ?>

<?php if ($missing): ?>
<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">Set up first</h2>
        <ol class="mb-3">
            <?php foreach ($missing as $m): ?><li><?= h($m) ?></li><?php endforeach; ?>
        </ol>
        <p class="small text-muted mb-1">In your Intuit developer app (developer.intuit.com → your app → Keys &amp; credentials), set the redirect URI to exactly:</p>
        <p class="mb-0"><code><?= h($cfg['redirect_uri']) ?></code></p>
    </div>
</div>
<?php else: ?>
<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">Connection</h2>
        <?php if ($status['connected']): ?>
            <p class="mb-1"><strong>Connected</strong> to <?= h((string)($status['company'] ?? 'QuickBooks')) ?>
                (<?= h($status['environment'] === 'production' ? 'live company' : 'sandbox — test company') ?>).</p>
            <p class="small text-muted mb-3">
                Last read <?= $status['last_pull_at'] ? h(date('M j, g:i a', strtotime((string)$status['last_pull_at']))) : 'never' ?>
                <?php if (!empty($status['refresh_expires_at'])): ?> · sign-in good until <?= h(date('M j, Y', strtotime((string)$status['refresh_expires_at']))) ?><?php endif; ?>
            </p>
            <?php if (!empty($status['last_error'])): ?><div class="alert alert-warning small"><?= h((string)$status['last_error']) ?></div><?php endif; ?>
            <form method="post" class="d-flex gap-2 flex-wrap">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <button class="btn btn-primary" name="action" value="pull">Read from QuickBooks again</button>
                <button class="btn btn-outline-danger" name="action" value="disconnect">Disconnect</button>
            </form>
        <?php else: ?>
            <?php if (($status['status'] ?? '') !== 'none'): ?>
                <div class="alert alert-warning small"><?= h((string)($status['last_error'] ?? 'Not connected.')) ?></div>
            <?php endif; ?>
            <p>Connect your QuickBooks Online company. You sign in at Intuit; Ittai never sees your QuickBooks password.</p>
            <a class="btn btn-primary" href="quickbooks.php?connect=1&amp;t=<?= h(rawurlencode($csrf)) ?>">Connect QuickBooks</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($canSend): ?>
<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">Automatic sending</h2>
        <p class="small text-muted">Every 15 minutes, issued invoices (from the date you pick) and their payments go to QuickBooks by themselves. Invoices that can't go as they are wait below with the reason.</p>
        <form method="post" class="d-flex gap-2 flex-wrap align-items-end mb-3">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="auto">
            <?php if ($auto['on']): ?>
                <p class="mb-0 me-2"><strong>On</strong> — invoices issued from <?= h(date('M j, Y', strtotime((string)$auto['from']))) ?>.</p>
                <button class="btn btn-outline-secondary" name="auto" value="0">Turn off</button>
            <?php else: ?>
                <div>
                    <span class="form-label small mb-1 d-block" id="qbo-from-label">Send invoices issued from</span>
                    <!-- The CRM's date picker (CLAUDE.md rule 12); the hidden date input carries the value. -->
                    <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#qbo-from" aria-haspopup="true" aria-expanded="false" aria-labelledby="qbo-from-label">
                        <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span class="mw-datepicker-date" data-mw-dp-label></span>
                        <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <input type="date" id="qbo-from" name="from" class="form-control" hidden value="<?= h(date('Y-m-d')) ?>">
                </div>
                <button class="btn btn-primary" name="auto" value="1">Turn on</button>
            <?php endif; ?>
        </form>

        <form method="post" class="d-flex gap-2 flex-wrap align-items-center mb-3">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="receipts">
            <?php if ($receiptsOn): ?>
                <p class="mb-0 me-2"><strong>Receipts on</strong> — approved receipts go up as expenses with their photo.</p>
                <button class="btn btn-outline-secondary btn-sm" name="receipts" value="0">Receipts off</button>
            <?php elseif ($auto['on']): ?>
                <p class="mb-0 me-2">Receipts are not sent yet. Map <em>Paid from</em> below first.</p>
                <button class="btn btn-outline-primary btn-sm" name="receipts" value="1">Send receipts too</button>
            <?php endif; ?>
        </form>

        <?php if ($problems): ?>
        <form method="post" class="float-end">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button class="btn btn-sm btn-outline-secondary" name="action" value="retry_held">Try held ones again</button>
        </form>
        <h3 class="h6">Needs you</h3>
        <ul class="small mb-3">
            <?php foreach ($problems as $p): ?><li><strong><?= h($p['invoice']) ?></strong> — <?= h($p['why']) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h2 class="h5">Send an invoice to the test company</h2>
        <p class="small text-muted">Sends one Ittai invoice to QuickBooks with its customer and payments, then checks QuickBooks' total against Ittai's. Sending the same invoice again never makes a second copy.</p>
        <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="send_invoice">
            <div>
                <label class="form-label small mb-1" for="qbo-inv">Invoice number</label>
                <input class="form-control" id="qbo-inv" name="invoice_number" placeholder="INV-2026-0459" required>
            </div>
            <button class="btn btn-primary">Send to QuickBooks</button>
        </form>
        <?php if ($sent): ?>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
                <thead><tr><th scope="col">Sent</th><th scope="col">What</th><th scope="col">Result</th></tr></thead>
                <tbody>
                <?php foreach ($sent as $l): ?>
                    <tr>
                        <td class="small"><?= $l['sent_at'] ? h(date('M j, g:i a', strtotime((string)$l['sent_at']))) : '' ?></td>
                        <td><?= h($l['entity_type'] === 'invoice' ? ('Invoice ' . ($l['invoice_number'] ?? '#' . $l['entity_id'])) : ('Payment #' . $l['entity_id'])) ?></td>
                        <td class="small"><?= h($l['status'] === 'sent' ? ('In QuickBooks' . ($l['qbo_total'] !== null ? ' · $' . number_format((float)$l['qbo_total'], 2) : '')) : (($l['status'] === 'mismatch' ? 'Totals differ: ' : 'Not sent: ') . (string)$l['message'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($status['connected'] && ($qAccounts || $qTax)): ?>
<form method="post" class="card mb-3">
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_map">
        <h2 class="h5">Tax codes</h2>
        <p class="small text-muted">QuickBooks Canada needs a tax code on every line. Pick the one for each case once.</p>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <tbody>
            <?php foreach (QboConnectionService::TAX_KEYS as $key => $label):
                $cur = $maps['tax_code'][$key]['qbo_id'] ?? null;
                $guess = $cur === null ? QboConnectionService::suggestTaxCode($key, $qTax) : null; ?>
                <tr>
                    <th scope="row" class="fw-normal"><label for="tax-<?= h($key) ?>"><?= h($label) ?></label></th>
                    <td>
                        <select class="form-select form-select-sm" id="tax-<?= h($key) ?>" name="map[tax_code][<?= h($key) ?>]">
                            <option value="">— choose —</option>
                            <?php foreach ($qTax as $t): $sel = ($cur ?? $guess) === $t['qbo_id']; ?>
                                <option value="<?= h($t['qbo_id']) ?>"<?= $sel ? ' selected' : '' ?>><?= h($t['name'] . ($t['detail'] ? ' — ' . $t['detail'] : '')) ?><?= ($sel && $cur === null) ? ' (suggested)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($payCodes): $qPay = QboConnectionService::fitting('pay', $qAccounts); ?>
        <h2 class="h5 mt-3">Paid from</h2>
        <p class="small text-muted">For receipts: the Ittai account a purchase was paid from → the QuickBooks bank or card account. The same account your bank feed downloads into, so QuickBooks offers each receipt as a match.</p>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <tbody>
            <?php foreach ($payCodes as $code => $pname):
                $cur = $maps['pay_account'][$code]['qbo_id'] ?? null; ?>
                <tr>
                    <th scope="row" class="fw-normal"><label for="pay-<?= h($code) ?>"><?= h($code . ' ' . $pname) ?></label></th>
                    <td>
                        <select class="form-select form-select-sm" id="pay-<?= h($code) ?>" name="map[pay_account][<?= h($code) ?>]">
                            <option value="">— choose —</option>
                            <?php foreach ($qPay as $q): ?>
                                <option value="<?= h($q['qbo_id']) ?>"<?= $cur === $q['qbo_id'] ? ' selected' : '' ?>><?= h($q['name'] . ($q['detail'] ? ' (' . $q['detail'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <h2 class="h5 mt-3">Accounts</h2>
        <p class="small text-muted">Each Ittai account → the QuickBooks account its amounts go to. "(suggested)" is a guess by name — check it.</p>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th scope="col">Ittai account</th><th scope="col">QuickBooks account</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a):
                // Only accounts of the same kind (income → income, expense → expense, equipment → fixed asset).
                $fit = QboConnectionService::fitting((string)$a['type'], $qAccounts);
                $cur = $maps['account'][$a['code']]['qbo_id'] ?? null;
                $guess = $cur === null ? QboConnectionService::suggestAccount((string)$a['name'], $fit) : null; ?>
                <tr>
                    <th scope="row" class="fw-normal"><label for="acct-<?= h($a['code']) ?>"><?= h($a['code'] . ' ' . $a['name']) ?></label></th>
                    <td>
                        <select class="form-select form-select-sm" id="acct-<?= h($a['code']) ?>" name="map[account][<?= h($a['code']) ?>]">
                            <option value="">— choose —</option>
                            <?php foreach ($fit as $q): $sel = ($cur ?? $guess) === $q['qbo_id']; ?>
                                <option value="<?= h($q['qbo_id']) ?>"<?= $sel ? ' selected' : '' ?>><?= h($q['name'] . ($q['detail'] ? ' (' . $q['detail'] . ')' : '')) ?><?= ($sel && $cur === null) ? ' (suggested)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button class="btn btn-primary">Save mapping</button>
    </div>
</form>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
