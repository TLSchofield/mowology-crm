<?php
/**
 * Jobber import (2026-10-07) — Jobber's exports in as history, and the 2026 money into the books.
 *   1. Upload an export (Invoices; Transaction List; Jobber Payments) — columns are found by their
 *      headers and can be re-pointed before importing; clients are matched to the CRM (address,
 *      email, phone, name) and contacts are only created for the clients Tim chooses.
 *   2. 2026 only: revenue for 2026-issued Jobber invoices, and each 2026 deposit booked against the
 *      Jobber payments it carried (DR bank / CR receivable, fees to 6800). Proposals → Tim approves;
 *      every approval can be undone. 2017–2025 never touch the ledger.
 *   3. Invoices still owing at cutover → recreate in the CRM and link.
 * Data: /crm/api/jobber-import.php (JobberImportService + JobberLedgerService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Jobber import';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Jobber import</h1>
        <p class="text-muted mb-0 small">Jobber's invoices come in as history (never as CRM invoices, so nothing is counted twice). Only 2026 reaches the books: revenue for invoices issued in 2026, and each 2026 deposit against the Jobber payments it carried. Nothing is booked until you approve it; every approval can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/payment-match.php" class="btn btn-sm btn-primary">Payments ↔ invoices</a>
        <a href="/crm/accounting/fy-opening.php" class="btn btn-sm btn-outline-secondary">FY2026 opening</a>
        <a href="/crm/accounting/bank-balance-check.php" class="btn btn-sm btn-outline-secondary">Bank balance check</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can import from Jobber.</div>
<?php else: ?>
    <div id="jbi-msg" class="alert d-none" role="status"></div>
    <div id="jbi-warnings"></div>

    <div class="card mw-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0">1. Upload a Jobber export</h5>
                <div class="small text-muted">In this order: Reports → Invoices; Reports → Transaction List; Jobber Payments → Transaction List. Re-importing the same file only updates.</div>
            </div>
            <input type="file" accept=".csv,text/csv" class="form-control form-control-sm mw-jbi-file" id="jbi-file">
        </div>
        <div class="card-body" id="jbi-preview"><p class="text-muted small mb-0">Choose a CSV — you'll see what was found before anything is written.</p></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Imported</h5></div>
                <div class="card-body p-0" id="jbi-summary"></div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Receivable at Dec 31, 2025 — Jobber vs the filed figure</h5></div>
                <div class="card-body p-0" id="jbi-ar"></div>
            </div>
        </div>
    </div>

    <h2 class="h5 mt-2 mb-2">2. 2026 into the books</h2>
    <div id="jbi-revenue"></div>
    <div id="jbi-deposits"></div>
    <div id="jbi-left"></div>

    <h2 class="h5 mt-3 mb-2">3. Still owing in Jobber</h2>
    <div id="jbi-open"></div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">What I've booked</h5></div>
        <div class="card-body p-0" id="jbi-log"></div>
    </div>

    <script src="/crm/js/jobber-import.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/jobber-import.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
