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

$pageTitle  = 'Jobber line items';
$activePage = 'accounting';
$isAdmin    = isAdmin();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header mb-3">
    <h1 class="h3 mb-0">Jobber line items</h1>
    <p class="text-muted mb-0 small">Fills in the services billed on each Jobber invoice so the "Price history by service" panel has something to show. Only empty line items are filled in; status, balance, tax and everything the books read stay exactly as they are. Safe to run more than once.</p>
</div>

<?php if (!$isAdmin): ?>
    <div class="alert alert-warning">Only an admin can do this.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <label for="jli-file" class="form-label">Jobber Invoices export (.csv)</label>
            <input type="file" accept=".csv,text/csv" class="form-control form-control-sm mb-3" id="jli-file">
            <button type="button" class="btn btn-primary btn-sm" id="jli-go" disabled>Fill in line items</button>
            <div id="jli-msg" class="alert d-none mt-3" role="status"></div>
        </div>
    </div>
    <script>
    (function () {
        var f = document.getElementById('jli-file'), go = document.getElementById('jli-go'), msg = document.getElementById('jli-msg');
        f.addEventListener('change', function () { go.disabled = !f.files.length; });
        go.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('csv_file', f.files[0]);
            fd.append('csrf_token', window.MW_CSRF_TOKEN || '');
            go.disabled = true; msg.className = 'alert alert-info mt-3'; msg.textContent = 'Working…';
            fetch('/crm/api/jobber-price-history.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.ok) { msg.className = 'alert alert-danger mt-3'; msg.textContent = d.error || 'Failed.'; }
                    else {
                        msg.className = 'alert alert-success mt-3';
                        msg.textContent = 'Filled in ' + d.filled + ' invoices (' + d.already_had + ' already had line items, '
                            + d.not_found + ' invoice numbers not in the CRM history, ' + d.no_lines + ' with no line items in the export).';
                    }
                })
                .catch(function () { msg.className = 'alert alert-danger mt-3'; msg.textContent = 'Network error.'; })
                .then(function () { go.disabled = !f.files.length; });
        });
    })();
    </script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
