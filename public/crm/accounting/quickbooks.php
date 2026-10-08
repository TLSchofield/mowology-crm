<?php
/**
 * Settings → QuickBooks (2026-10-07). QuickBooks Online = the accountant's books.
 * Phase 1: connect (OAuth 2.0), read-only discovery of what is in the company file, and the
 * chart-of-accounts map Tim confirms. Phase 2 (push, flag OFF) shows its dry-run preview here.
 * Data: /crm/api/quickbooks.php; connect: /crm/api/quickbooks/oauth-start.php.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'QuickBooks';
$activePage = 'settings';
$isAdmin    = isAdmin();
$flash      = isset($_GET['error']) ? (string)$_GET['error'] : '';
$justConnected = !empty($_GET['connected']);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">QuickBooks</h1>
        <p class="text-muted mb-0 small">QuickBooks is the accountant's books. The CRM stays the working system; approved records go across one way, and only after you have seen what is already in the file.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/settings.php" class="btn btn-sm btn-outline-secondary">Settings</a>
        <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">Accounting</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can connect QuickBooks.</div>
<?php else: ?>
    <?php if ($flash !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div id="qbo-msg" class="alert d-none" role="status"></div>

    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card mw-card mb-3" id="qbo-connection-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Connection</h5>
                    <span class="badge mw-qbo-env" id="qbo-env"></span>
                </div>
                <div class="card-body" id="qbo-connection"><div class="text-muted small">Checking…</div></div>
            </div>

            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">How it works</h5></div>
                <div class="card-body small">
                    <ol class="mb-2 pl-3">
                        <li><strong>Connect</strong> — you sign in to QuickBooks once and allow Mowology CRM. Tokens are stored encrypted; they refresh themselves. The connection lapses if unused for 100 days.</li>
                        <li><strong>Read</strong> — the CRM reads the company file and shows what is in it: company settings, tax codes, the chart of accounts, how many transactions per year. Nothing is written.</li>
                        <li><strong>Map</strong> — each CRM account gets its QuickBooks twin; the code suggests, you confirm.</li>
                        <li><strong>Push</strong> (later, switched off today) — approved expenses, invoices and payments go across one way, dated 2026 onward only, never into a closed period, never deleted.</li>
                    </ol>
                    <div class="text-muted">The accountant gets a normal QuickBooks Accountant user; their adjustments stay in QuickBooks and are read back into a CRM report only.</div>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card mw-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title mb-0">What is in the company file</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted" id="qbo-discovery-at"></span>
                        <button type="button" class="btn btn-sm btn-primary" id="qbo-refresh" disabled>Read the file</button>
                    </div>
                </div>
                <div class="card-body" id="qbo-discovery"><div class="text-muted small">Connect first, then read the file.</div></div>
            </div>
        </div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0">Chart of accounts — CRM ↔ QuickBooks</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="qbo-accept-all" disabled>Accept all strong matches</button>
            </div>
        </div>
        <div class="card-body p-0" id="qbo-map"><div class="p-3 text-muted small">Needs the file read first.</div></div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0">Push (phase 2) — switched <span id="qbo-push-state">off</span></h5>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="qbo-preview-btn" disabled>Dry run: show what would go</button>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">Nothing here writes to QuickBooks. These are the choices the push will need; the dry run builds each record exactly as it would be sent and says why any would be held back.</p>
            <div id="qbo-choices"></div>
            <div id="qbo-preview" class="mt-3"></div>
        </div>
    </div>

    <script>window.MW_QBO = { justConnected: <?= $justConnected ? 'true' : 'false' ?> };</script>
    <script src="/crm/js/quickbooks.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/quickbooks.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
