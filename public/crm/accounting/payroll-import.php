<?php
/**
 * Payroll import (2026-10-07) — Wave's monthly Wage & Tax Report into the books, the bank lines
 * that paid it (Wave's CRA remittances, the e-Transfers) moved off wages, the hours checked
 * against the time clock, and the shareholder account (1300) Penny keeps.
 * Data: /crm/api/payroll-import.php (WavePayrollImportService, ShareholderAccountService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Payroll import';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Payroll import</h1>
        <p class="text-muted mb-0 small">Each month: download Wave's <strong>Wages &amp; taxes</strong> report for that month (Payroll → Reports, paydays 1st to last of the month) and drop the PDF here. Penny books the month and moves the bank lines that paid it — nothing is booked until you approve, and every approval can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/bank-balance-check.php" class="btn btn-sm btn-outline-secondary">Bank balance check</a>
        <a href="/crm/accounting/trial-balance.php" class="btn btn-sm btn-outline-secondary">Trial balance</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only the owner can import payroll.</div>
<?php else: ?>
    <div id="pi-msg" class="alert d-none" role="status"></div>
    <div id="pi-problems"></div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">Add a Wave report</h5></div>
        <div class="card-body">
            <form id="pi-upload" class="d-flex flex-wrap gap-2 align-items-center" enctype="multipart/form-data">
                <input type="file" name="report" id="pi-file" accept="application/pdf,.pdf" class="form-control form-control-sm mw-pi-file" required>
                <button type="submit" class="btn btn-sm btn-primary">Read the report</button>
            </form>
            <details class="mt-2 mw-pi-details">
                <summary class="small text-muted">No PDF? Paste the report's text instead</summary>
                <textarea id="pi-text" class="form-control form-control-sm mt-2" rows="5" placeholder="Select all in the PDF, copy, paste here"></textarea>
                <button type="button" id="pi-paste" class="btn btn-sm btn-outline-primary mt-2">Read the pasted text</button>
            </details>
        </div>
    </div>

    <h2 class="h5 mt-2 mb-2">Months</h2>
    <div id="pi-months"><div class="text-muted small">Reading…</div></div>

    <h2 class="h5 mt-4 mb-2" id="shareholder">Shareholder account (1300 Due from Shareholder)</h2>
    <div id="pi-shareholder"></div>

    <div class="card mw-card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">The accountant's clearing</h5></div>
        <div class="card-body">
            <p class="small text-muted mb-2">When the accountant clears the shareholder balance (a dividend or a bonus declared after the year end), enter it here from their figures — Penny doesn't guess it. Dividend: DR 3400 Dividends Declared / CR 1300. Bonus: DR 5100 wages (amount + withholdings) / CR 2510 withholdings / CR 1300.</p>
            <form id="pi-clearing" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small mb-1" for="pi-cl-type">Type</label>
                    <select id="pi-cl-type" class="form-select form-select-sm">
                        <option value="dividend">Dividend</option>
                        <option value="bonus">Bonus</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Date</label>
                    <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#pi-cl-date" aria-haspopup="true" aria-expanded="false">
                        <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span class="mw-datepicker-date" data-mw-dp-label></span>
                        <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <input type="date" id="pi-cl-date" class="form-control" hidden value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small mb-1" for="pi-cl-amount">Amount cleared</label>
                    <input type="number" id="pi-cl-amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small mb-1" for="pi-cl-wh">Withholdings (bonus)</label>
                    <input type="number" id="pi-cl-wh" class="form-control form-control-sm" step="0.01" min="0" value="0">
                </div>
                <div class="col-sm-8 col-lg-3">
                    <label class="form-label small mb-1" for="pi-cl-note">Note</label>
                    <input type="text" id="pi-cl-note" class="form-control form-control-sm" maxlength="250" placeholder="e.g. per accountant's letter">
                </div>
                <div class="col-sm-4 col-lg-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Book</button>
                </div>
            </form>
            <div id="pi-clearings" class="mt-2"></div>
        </div>
    </div>

    <script src="/crm/js/payroll-import.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/payroll-import.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
