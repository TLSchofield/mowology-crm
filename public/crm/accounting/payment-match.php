<?php
/**
 * Payments ↔ invoices (2026-10-07) — every unmatched 2026 bank deposit matched to the invoice(s) it
 * paid, CRM invoices AND imported Jobber invoices / payments, or left for Tim with the closest
 * candidates; every open invoice shows whether its money is sitting in the bank.
 * Left: deposits, proposals grouped by confidence (Approve / Skip; approve all high). Right: open
 * invoices (CRM + Jobber) — tick a deposit, tick what it paid, the difference shows, book it.
 * Booking goes through the income clean-up / Jobber ledger paths; every approval can be undone.
 * Data: /crm/api/payment-match.php (PaymentMatchService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Payments ↔ invoices';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Payments ↔ invoices</h1>
        <p class="text-muted mb-0 small">Penny: each 2026 deposit matched to the invoice(s) it paid — CRM and Jobber. I propose, you approve; a deposit that paid a 2025 Jobber invoice settles the opening receivable, never 2026 income. Every booking can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/income-cleanup.php" class="btn btn-sm btn-outline-secondary">Income clean-up</a>
        <a href="/crm/accounting/jobber-import.php" class="btn btn-sm btn-outline-secondary">Jobber import</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can match payments to invoices.</div>
<?php else: ?>
    <div id="pm-msg" class="alert d-none" role="status"></div>
    <div class="row g-3 mb-3" id="pm-totals"><div class="col-12 text-muted small">Reading the bank lines and every open invoice…</div></div>

    <div class="row g-3 mw-pm">
        <div class="col-xl-7">
            <div id="pm-pick" class="mw-pm-pick d-none" aria-live="polite"></div>
            <div id="pm-groups"></div>
        </div>
        <div class="col-xl-5">
            <div class="card mw-card mb-3 mw-pm-right">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="card-title mb-0">Open invoices — is the money in the bank?</h5>
                        <span class="small text-muted" id="pm-open-sum"></span>
                    </div>
                    <div class="d-flex gap-2 mt-2 flex-wrap">
                        <input type="search" class="form-control form-control-sm mw-pm-search" id="pm-search" placeholder="Client, strata plan, invoice #" aria-label="Filter invoices">
                        <select class="form-select form-select-sm mw-pm-filter" id="pm-filter" aria-label="Which items">
                            <option value="open">Open — CRM + Jobber</option>
                            <option value="none">Open, no money found</option>
                            <option value="all">Everything a deposit can pay</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0" id="pm-open"></div>
            </div>
        </div>
    </div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">What I've booked</h5></div>
        <div class="card-body p-0" id="pm-log"></div>
    </div>

    <script src="/crm/js/payment-match.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/payment-match.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
