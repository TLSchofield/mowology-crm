<?php
/**
 * Penny's income clean-up (2026-10-07) — bank deposits still counted as income next to the
 * invoices they paid. Penny proposes, grouped; Tim approves a group or a line; booking goes
 * through the existing reconciliation paths and every booking can be undone.
 * Also: the Credit Card Payable (2400) audit, report only.
 * Data: /crm/api/income-cleanup.php (IncomeCleanupService, CreditCardPayableAuditService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Income clean-up';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Income clean-up</h1>
        <p class="text-muted mb-0 small">Penny: bank deposits still counted as income next to the invoices they paid. I propose, you approve, every booking can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/payment-match.php" class="btn btn-sm btn-primary">Payments ↔ invoices</a>
        <a href="/crm/accounting/income-statement.php" class="btn btn-sm btn-outline-secondary">Income statement</a>
        <a href="/crm/accounting/trial-balance.php" class="btn btn-sm btn-outline-secondary">Trial balance</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can review the income clean-up.</div>
<?php else: ?>
    <div id="ic-msg" class="alert d-none" role="status"></div>

    <div class="row g-3 mb-3" id="ic-totals">
        <div class="col-12 text-muted small">Reading the bank lines…</div>
    </div>

    <div id="ic-groups"></div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Income by month — before → after</h5></div>
                <div class="card-body p-0" id="ic-months"></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">GST line 101</h5></div>
                <div class="card-body" id="ic-gst"></div>
            </div>
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Journal check</h5></div>
                <div class="card-body small" id="ic-journal"></div>
            </div>
        </div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Credit Card Payable (2400) — audit</h5>
            <span class="badge bg-secondary">report only</span>
        </div>
        <div class="card-body" id="ic-cc"><span class="text-muted small">Reading 2400…</span></div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">What I've booked</h5></div>
        <div class="card-body p-0" id="ic-log"></div>
    </div>

    <script src="/crm/js/income-cleanup.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/income-cleanup.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
