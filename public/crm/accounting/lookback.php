<?php
/**
 * Penny's look-back review of 2026 (backlog item 7, migration 1250) — every 2026 receipt,
 * bank / card line and journal entry re-checked with what Penny knows now. Proposals only:
 * Tim approves or skips each one (or a whole high-confidence group); approvals go through
 * ExpenseGate / BankLineMoveService (append-only journal), every change can be undone, locked
 * months are refused, a filed GST quarter is shown as "adjust on the next return".
 * Data: /crm/api/lookback.php (LookbackService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Look-back 2026';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Look-back 2026</h1>
        <p class="text-muted mb-0 small">Penny re-checked everything booked in 2026 with what she knows now. Nothing changes until you approve it; every change can be undone. 2025 is filed and never touched.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/crm/accounting/income-cleanup.php" class="btn btn-sm btn-outline-secondary">Income clean-up</a>
        <a href="/crm/accounting/trial-balance.php" class="btn btn-sm btn-outline-secondary">Trial balance</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can review the look-back.</div>
<?php else: ?>
    <div id="lb-msg" class="alert d-none" role="status"></div>

    <div class="row g-3 mb-3" id="lb-totals">
        <div class="col-12 text-muted small">Reading the look-back…</div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <span class="small text-muted me-auto" id="lb-scan-info"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="lb-scan">Look again (rules only, free)</button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="lb-scan-ai">Look again + ask Claude (up to 10 vendors / payees)</button>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div id="lb-groups"></div>
        </div>
        <div class="col-xl-4">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">GST effect per quarter</h5></div>
                <div class="card-body p-0" id="lb-gst"></div>
            </div>
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Handled elsewhere — count only</h5></div>
                <div class="card-body p-0" id="lb-info"></div>
            </div>
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Applied — undo</h5></div>
                <div class="card-body p-0" id="lb-applied"></div>
            </div>
        </div>
    </div>

    <script src="/crm/js/lookback.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/lookback.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
