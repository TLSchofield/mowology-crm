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
        } elseif ($action === 'save_map') {
            $bad = 0;
            foreach (['account', 'tax_code'] as $kind) {
                foreach ((array)($_POST['map'][$kind] ?? []) as $key => $qboId) {
                    if (!$svc->setMapping($kind, (string)$key, (string)$qboId, (int)($user['id'] ?? 0))) $bad++;
                }
            }
            $_SESSION['qbo_flash'] = ['ok' => $bad === 0, 'message' => $bad === 0 ? 'Mapping saved.' : $bad . ' choice(s) were not saved — read from QuickBooks again and pick them once more.'];
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

        <h2 class="h5 mt-3">Accounts</h2>
        <p class="small text-muted">Each Ittai account → the QuickBooks account its amounts go to. "(suggested)" is a guess by name — check it.</p>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th scope="col">Ittai account</th><th scope="col">QuickBooks account</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a):
                $cur = $maps['account'][$a['code']]['qbo_id'] ?? null;
                $guess = $cur === null ? QboConnectionService::suggestAccount((string)$a['name'], $qAccounts) : null; ?>
                <tr>
                    <th scope="row" class="fw-normal"><label for="acct-<?= h($a['code']) ?>"><?= h($a['code'] . ' ' . $a['name']) ?></label></th>
                    <td>
                        <select class="form-select form-select-sm" id="acct-<?= h($a['code']) ?>" name="map[account][<?= h($a['code']) ?>]">
                            <option value="">— choose —</option>
                            <?php foreach ($qAccounts as $q): $sel = ($cur ?? $guess) === $q['qbo_id']; ?>
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
