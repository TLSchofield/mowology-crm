<?php
/**
 * Bank balance check (2026-10-07) — does each bank account's journal balance match its statement
 * at every month end? Drift per month, what explains it, and fixes Tim approves group by group
 * (every fix logged and undoable; locked months skipped).
 * Data: /crm/api/bank-balance-check.php (BankBalanceCheckService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Bank balance check';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Bank balance check</h1>
        <p class="text-muted mb-0 small">The books' bank balance against the statement's, every month end — the gap, what explains it, and the fixes. Nothing is booked until you approve; every approval can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/fy-opening.php" class="btn btn-sm btn-outline-secondary">FY2026 opening</a>
        <a href="/crm/accounting/jobber-import.php" class="btn btn-sm btn-outline-secondary">Jobber import</a>
        <a href="/crm/accounting/income-cleanup.php" class="btn btn-sm btn-outline-secondary">Income clean-up</a>
        <a href="/crm/accounting/trial-balance.php" class="btn btn-sm btn-outline-secondary">Trial balance</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can review the bank balance check.</div>
<?php else: ?>
    <div id="bbc-msg" class="alert d-none" role="status"></div>

    <div id="bbc-ready"></div>

    <ul class="nav nav-tabs mb-3" id="bbc-tabs" role="tablist"></ul>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mw-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title mb-0">Month ends — statement vs books</h5>
                    <span class="small text-muted" id="bbc-acct-note"></span>
                </div>
                <div class="card-body p-0 mw-bbc-scroll" id="bbc-months"><div class="p-3 text-muted small">Reading the statements and the journal…</div></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">What explains the gap</h5></div>
                <div class="card-body p-0" id="bbc-causes"></div>
            </div>
        </div>
    </div>

    <h2 class="h5 mt-2 mb-2">Fixes — approve a group</h2>
    <div id="bbc-groups"></div>

    <h2 class="h5 mt-3 mb-2">For you to look at</h2>
    <div id="bbc-review"></div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">What I've booked</h5></div>
        <div class="card-body p-0" id="bbc-log"></div>
    </div>

    <script src="/crm/js/bank-balance-check.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/bank-balance-check.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
