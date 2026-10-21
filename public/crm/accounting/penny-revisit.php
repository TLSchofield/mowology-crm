<?php
/**
 * Penny's revisit list (2026-10-21) — bank lines she now thinks belong on a different account, grouped by payee.
 * She proposes; the owner approves a payee group, says "not now", or undoes. Lines in a closed (locked) period are
 * never moved: they are listed for the accountant.
 * Data: /crm/api/penny-revisit.php (PennyRevisitService). Script: /crm/js/penny-revisit.js.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = "Penny's revisit";
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Penny's revisit</h1>
        <p class="text-muted mb-0 small">When Penny learns something new (a vendor you added, a payee you identified, a rule), she looks again at bank lines already filed. She proposes; you decide, one payee at a time. Nothing in a closed period is ever moved.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/payroll-import.php" class="btn btn-sm btn-outline-secondary">Payroll import</a>
        <a href="/crm/accounting/transactions.php" class="btn btn-sm btn-outline-secondary">Transactions</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only the owner can use Penny's revisit.</div>
<?php else: ?>
    <div id="pr-msg" class="alert d-none" role="status"></div>

    <div class="card mw-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0">Look again</h5>
            <span id="pr-mode" class="small text-muted"></span>
        </div>
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <button type="button" id="pr-preview" class="btn btn-sm btn-outline-primary">Preview what she would propose</button>
            <button type="button" id="pr-scan" class="btn btn-sm btn-primary">Look again and record</button>
            <span id="pr-scan-note" class="small text-muted"></span>
        </div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">Waiting for you <span id="pr-count" class="badge bg-secondary ms-1"></span></h5></div>
        <div class="card-body" id="pr-waiting"><span class="text-muted">Loading…</span></div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0">For the accountant <span id="pr-acc-count" class="badge bg-secondary ms-1"></span></h5>
            <button type="button" id="pr-copy" class="btn btn-sm btn-outline-secondary">Copy as text</button>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-2">Lines in closed periods that Penny now thinks are on the wrong account. She has not moved them.</p>
            <pre id="pr-accountant" class="small mb-0" style="white-space:pre-wrap;"></pre>
        </div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">Done <span id="pr-done-count" class="badge bg-secondary ms-1"></span></h5></div>
        <div class="card-body" id="pr-applied"><span class="text-muted">Nothing yet.</span></div>
    </div>

    <script>window.PENNY_REVISIT_CSRF = <?= json_encode(function_exists('generateCSRFToken') ? generateCSRFToken() : '') ?>;</script>
    <script src="/crm/js/penny-revisit.js?v=20261021a" defer></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
