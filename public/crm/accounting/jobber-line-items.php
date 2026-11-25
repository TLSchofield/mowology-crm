<?php
/**
 * Jobber line items backfill — the first Jobber invoice import kept no line items, so the "Price history
 * by service" panel is empty. Upload the Jobber Invoices export again: only EMPTY line_items are filled in;
 * nothing else on any invoice changes. Data: /crm/api/jobber-price-history.php (JobberPriceHistory).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Jobber history top-ups';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header mb-3">
    <h1 class="h3 mb-0">Jobber history top-ups</h1>
    <p class="text-muted mb-0 small">Two read-only history imports for the "Price history by service" and "Quotes" panels. Neither touches invoices, payments or the books. Safe to run more than once.</p>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can do this.</div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">1. Invoice line items</h2>
            <p class="text-muted small">Upload the Jobber <strong>Invoices</strong> export. Only empty line items are filled in; status, balance, tax and everything the books read stay as they are.</p>
            <input type="file" accept=".csv,text/csv" class="form-control form-control-sm mb-3 jli-file" data-kind="lines">
            <button type="button" class="btn btn-primary btn-sm jli-go" data-kind="lines" disabled>Fill in line items</button>
            <div class="alert d-none mt-3 jli-msg" data-kind="lines" role="status"></div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h2 class="h5">2. Quotes</h2>
            <p class="text-muted small">Upload the Jobber <strong>Quotes</strong> export (needs migration 1392 first). Each quote is kept as history, including archived and unanswered ones, and linked to its client. Re-uploading updates in place.</p>
            <input type="file" accept=".csv,text/csv" class="form-control form-control-sm mb-3 jli-file" data-kind="quotes">
            <button type="button" class="btn btn-primary btn-sm jli-go" data-kind="quotes" disabled>Import quotes</button>
            <div class="alert d-none mt-3 jli-msg" data-kind="quotes" role="status"></div>
        </div>
    </div>
    <script>
    (function () {
        function q(sel, kind) { return document.querySelector(sel + '[data-kind="' + kind + '"]'); }
        ['lines', 'quotes'].forEach(function (kind) {
            var f = q('.jli-file', kind), go = q('.jli-go', kind), msg = q('.jli-msg', kind);
            f.addEventListener('change', function () { go.disabled = !f.files.length; });
            go.addEventListener('click', function () {
                var fd = new FormData();
                fd.append('csv_file', f.files[0]);
                fd.append('kind', kind);
                fd.append('csrf_token', window.MW_CSRF_TOKEN || '');
                go.disabled = true; msg.className = 'alert alert-info mt-3 jli-msg'; msg.textContent = 'Working…';
                fetch('/crm/api/jobber-price-history.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) { msg.className = 'alert alert-danger mt-3 jli-msg'; msg.textContent = d.error || 'Failed.'; return; }
                        msg.className = 'alert alert-success mt-3 jli-msg';
                        msg.textContent = kind === 'quotes'
                            ? 'Quotes: ' + d.inserted + ' added, ' + d.updated + ' updated, ' + d.linked + ' of ' + d.rows + ' linked to a CRM client.'
                            : 'Filled in ' + d.filled + ' invoices (' + d.already_had + ' already had line items, ' + d.not_found + ' not in the CRM history, ' + d.no_lines + ' with no line items in the export).';
                    })
                    .catch(function () { msg.className = 'alert alert-danger mt-3 jli-msg'; msg.textContent = 'Network error.'; })
                    .then(function () { go.disabled = !f.files.length; });
            });
        });
    })();
    </script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
