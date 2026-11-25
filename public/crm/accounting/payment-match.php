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
$extraHead  = '<link rel="stylesheet" href="/crm/css/payment-match.css?v=' . (int)@filemtime(dirname(__DIR__) . '/css/payment-match.css') . '">';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Payments ↔ invoices</h1>
        <p class="text-muted mb-0 small">Penny matches each 2026 deposit to the invoices it paid. Nothing is booked until you approve it, and every booking can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/income-cleanup.php" class="btn btn-sm btn-outline-secondary">Income clean-up</a>
        <a href="/crm/accounting/jobber-import.php" class="btn btn-sm btn-outline-secondary">Jobber import</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can match payments to invoices.</div>
<?php else: ?>
    <div class="it-pm">
        <div class="it-pm-penny" id="pm-penny"></div>
        <div id="pm-batch"></div>
        <div id="pm-deck"></div>
        <details class="it-pm-history">
            <summary>What I've booked, and what you left alone</summary>
            <div id="pm-log"></div>
        </details>
    </div>

    <script src="/crm/js/payment-match.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/payment-match.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
