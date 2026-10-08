<?php
/**
 * FY2026 opening (2026-10-07) — start 2026 from the accountant's filed balance sheet (Signed FS
 * YE2025). One adjusting entry dated 2026-01-01: every balance-sheet account from its CRM balance
 * to the filed figure, 2025's revenue and expenses closed, the difference on retained earnings.
 * Booked only on Tim's click; undo = reversal.
 * Data: /crm/api/fy-opening.php (Fy2026OpeningService).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'FY2026 opening';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">FY2026 opening</h1>
        <p class="text-muted mb-0 small">Start 2026 from the accountant's numbers: each account moves from what the CRM had at Dec 31, 2025 to the filed balance sheet, and 2025's revenue and expenses close to retained earnings. One entry dated Jan 1, 2026 — nothing is booked until you click Book, and it can be undone.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/accounting/bank-balance-check.php" class="btn btn-sm btn-outline-secondary">Bank balance check</a>
        <a href="/crm/accounting/jobber-import.php" class="btn btn-sm btn-outline-secondary">Jobber import</a>
        <a href="/crm/accounting/balance-sheet.php" class="btn btn-sm btn-outline-secondary">Balance sheet</a>
    </div>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can book the opening.</div>
<?php else: ?>
    <div id="fyo-msg" class="alert d-none" role="status"></div>
    <div id="fyo-status"></div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mw-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title mb-0">Every line of the entry</h5>
                    <span class="small text-muted" id="fyo-totals"></span>
                </div>
                <div class="card-body p-0 mw-bbc-scroll mw-fyo-scroll" id="fyo-lines"><div class="p-3 text-muted small">Reading the journal…</div></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Retained earnings</h5></div>
                <div class="card-body p-0" id="fyo-re"></div>
            </div>
            <div class="card mw-card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">For you and the accountant</h5></div>
                <div class="card-body small" id="fyo-notes"></div>
            </div>
            <div class="card mw-card mb-3">
                <div class="card-body" id="fyo-actions"></div>
            </div>
        </div>
    </div>

    <script src="/crm/js/fy-opening.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/js/fy-opening.js') ?>"></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
