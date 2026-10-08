/**
 * Jobber import page (/crm/accounting/jobber-import.php), 2026-10-07.
 * Upload → preview (columns + matches, nothing written) → import. Then the 2026 proposals from
 * /crm/api/jobber-import.php; every booking sends keys + signatures only (the server rebuilds).
 */
(function () {
    'use strict';
    var API = '/crm/api/jobber-import.php';
    var KIND = { invoices: 'Invoices export', transactions: 'Transaction List', card: 'Jobber Payments transactions' };
    var HOW = { address: 'service address', email: 'email', phone: 'phone', name: 'name', company: 'company', created: 'contact created', none: 'not matched' };
    var STATUS = { paid: 'Paid', past_due: 'Past due', bad_debt: 'Bad debt', draft: 'Draft', open: 'Awaiting payment', void: 'Void', unknown: '?' };
    var file = null;      // {name, text}
    var preview = null;
    var mapping = {};
    var data = null;

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) {
        if (v === null || v === undefined) return '—';
        var n = Number(v);
        return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function say(msg, kind) {
        var el = $('jbi-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        if (msg) el.scrollIntoView({ block: 'nearest' });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function table(head, rows) {
        return '<table class="table table-sm mb-0 mw-bbc-table"><thead><tr>' + head.map(function (h) {
            return '<th' + (h.charAt(0) === '$' ? ' class="mw-bbc-num"' : '') + '>' + esc(h.charAt(0) === '$' ? h.slice(1) : h) + '</th>';
        }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
    }
    function done(r) { load(r.message || r.error || 'Done.', r.ok ? 'success' : 'warning'); }

    // ── upload / preview ────────────────────────────────────────────────────

    $('jbi-file').addEventListener('change', function () {
        var f = this.files && this.files[0];
        if (!f) return;
        var reader = new FileReader();
        reader.onload = function () { file = { name: f.name, text: String(reader.result || '') }; mapping = {}; runPreview(); };
        reader.readAsText(f);
    });

    function runPreview() {
        $('jbi-preview').innerHTML = '<p class="text-muted small mb-0">Reading ' + esc(file.name) + '…</p>';
        post({ mode: 'preview', csv: file.text, mapping: mapping }).then(function (p) {
            if (!p.ok) { $('jbi-preview').innerHTML = '<div class="alert alert-danger mb-0">' + esc(p.error || 'Could not read it.') + '</div>'; return; }
            preview = p;
            renderPreview();
        }).catch(function () { $('jbi-preview').innerHTML = '<div class="alert alert-danger mb-0">Could not reach the server.</div>'; });
    }

    function renderPreview() {
        var p = preview;
        var h = '';
        if (!p.kind) {
            $('jbi-preview').innerHTML = '<div class="alert alert-warning mb-0">This doesn\'t look like a Jobber Invoices, Transaction List or Jobber Payments export. Headers found: ' + esc((p.headers || []).join(', ')) + '</div>';
            return;
        }
        h += '<p class="mb-2"><strong>' + esc(KIND[p.kind]) + '</strong> — ' + p.rows + ' rows' + (p.bad ? ', ' + p.bad + ' unreadable' : '') + (p.skipped ? ', ' + p.skipped + ' preamble/total lines skipped' : '') + '.</p>';
        // Column mapping
        var opts = function (sel) {
            return '<option value="">— none —</option>' + p.headers.map(function (hd) { return '<option' + (hd === sel ? ' selected' : '') + '>' + esc(hd) + '</option>'; }).join('');
        };
        h += '<details class="mw-bbc-details mb-2"' + (p.missing.length ? ' open' : '') + '><summary class="small">Columns (' + p.fields.filter(function (f) { return f.header; }).length + ' found' +
            (p.missing.length ? ' — <span class="mw-bbc-off">missing: ' + esc(p.missing.join(', ')) + '</span>' : '') + ') — change one and the preview re-reads</summary>' +
            '<div class="mw-jbi-map">' + p.fields.map(function (f) {
                return '<label class="small">' + esc(f.label) + (f.required ? ' *' : '') + '<select class="form-select form-select-sm" data-field="' + esc(f.field) + '">' + opts(f.header) + '</select></label>';
            }).join('') + '</div></details>';
        if (p.missing.length) { $('jbi-preview').innerHTML = h; bindMap(); return; }

        if (p.kind === 'invoices') {
            var m = p.match || {};
            h += '<div class="row g-3"><div class="col-lg-6">' + table(['Matched to the CRM by', '$Invoices'], Object.keys(m).map(function (k) {
                return '<tr><td>' + esc(HOW[k] || k) + '</td><td class="mw-bbc-num">' + m[k] + '</td></tr>';
            })) + '</div><div class="col-lg-6">' + table(['Issued', '$Invoices'], Object.keys(p.years).map(function (y) {
                return '<tr><td>' + esc(y) + '</td><td class="mw-bbc-num">' + p.years[y] + '</td></tr>';
            })) + '</div></div>';
            h += '<p class="small mt-2 mb-1">Status: ' + Object.keys(p.status).map(function (s) { return esc(STATUS[s] || s) + ' ' + p.status[s]; }).join(' · ') +
                '. ' + p.new + ' new, ' + p.update + ' already imported (updated).</p>';
            h += '<p class="small mb-1">Still owing: past due ' + p.open.past_due.count + ' (' + money(p.open.past_due.total) + '), marked paid but with a balance ' +
                p.open.paid_with_balance.count + ' (' + money(p.open.paid_with_balance.total) + ')' + (p.open.open.count ? ', awaiting payment ' + p.open.open.count + ' (' + money(p.open.open.total) + ')' : '') +
                '. Bad debts come in as history only, never as a receivable.</p>';
            h += '<p class="small mb-2">Issued in 2026: ' + p.issued_2026.count + ' invoices, ' + money(p.issued_2026.total) +
                (p.issued_2026.computed_tax ? ' — GST not in this export: computed as 5/105 of the total for ' + p.issued_2026.computed_tax + ' (the Transaction List brings Jobber\'s own split)' : '') + '.</p>';
            var u = p.unmatched;
            h += '<details class="mw-bbc-details mb-2"><summary class="small"><strong>' + u.clients + '</strong> clients not in the CRM (' + u.recent + ' invoiced since 2025 or still owing) — show</summary><div class="mw-bbc-scroll">' +
                table(['Client', 'Email / phone', 'Address', '$Invoices', 'Last', '$Owing'], u.list.map(function (c) {
                    return '<tr><td>' + esc(c.name) + (c.recent ? ' <span class="badge bg-info text-dark">recent</span>' : '') + '</td><td class="small">' + esc(c.email || '') + ' ' + esc(c.phone || '') +
                        '</td><td class="small">' + esc(c.address || '') + '</td><td class="mw-bbc-num">' + c.invoices + '</td><td>' + esc(c.last || '') + '</td><td class="mw-bbc-num">' + money(c.owing) + '</td></tr>';
                })) + '</div></details>';
            h += '<div class="mb-2 small">Contacts for clients not in the CRM: ' +
                '<label class="me-3"><input type="radio" name="jbi-create" value="none" checked> none (keep the name on the Jobber invoice)</label>' +
                '<label class="me-3"><input type="radio" name="jbi-create" value="recent"> recent only (' + u.recent + ')</label>' +
                '<label><input type="radio" name="jbi-create" value="all"> all (' + u.clients + ')</label></div>';
        } else {
            h += '<p class="small mb-1">' + Object.keys(p.types).map(function (t) { return esc(t) + ' ' + p.types[t]; }).join(' · ') +
                (Object.keys(p.methods || {}).length ? ' — ' + Object.keys(p.methods).map(function (k) { return esc(k || '?') + ' ' + p.methods[k]; }).join(' · ') : '') + '</p>';
            h += '<p class="small mb-1">Received ' + money(p.received) + (p.fees ? ', fees ' + money(p.fees) + ' in ' + p.payouts + ' payouts' : '') + '; ' + p.in_2026 + ' payments in 2026.</p>';
            if (p.invoices_not_imported) h += '<p class="small mb-1 mw-bbc-off">' + p.invoices_not_imported + ' Jobber invoice #s here aren\'t imported yet (e.g. ' + esc(p.invoices_not_imported_sample.slice(0, 6).join(', ')) + ') — import the Invoices export first, then this one again.</p>';
        }
        h += '<details class="mw-bbc-details mb-2"><summary class="small">First rows as read</summary><pre class="small mw-jbi-pre">' + esc(JSON.stringify(p.samples, null, 1)) + '</pre></details>';
        h += '<button type="button" class="btn btn-success btn-sm" id="jbi-import"' + (p.ready ? '' : ' disabled') + '>Import ' + p.rows + ' rows</button>' +
            (p.ready ? '' : ' <span class="small mw-bbc-off">Run migration 1239 first.</span>');
        $('jbi-preview').innerHTML = h;
        bindMap();
        var b = $('jbi-import');
        if (b) b.addEventListener('click', function () {
            var c = document.querySelector('input[name="jbi-create"]:checked');
            var create = c ? c.value : 'none';
            if (!window.confirm('Import ' + p.rows + ' rows from ' + file.name + (create !== 'none' ? ' and create contacts (' + create + ')' : '') + '? Nothing reaches the books from this step.')) return;
            b.disabled = true;
            say('Importing…', 'info');
            post({ mode: 'import', csv: file.text, mapping: mapping, create: create, filename: file.name }).then(function (r) {
                if (r.ok) { $('jbi-preview').innerHTML = '<p class="small mb-0 mw-bbc-ok">' + esc(r.message) + '</p>'; $('jbi-file').value = ''; }
                done(r);
            }).catch(function () { say('Could not reach the server — reload to see where it stands.', 'danger'); });
        });
    }

    function bindMap() {
        Array.prototype.forEach.call(document.querySelectorAll('#jbi-preview select[data-field]'), function (s) {
            s.addEventListener('change', function () { mapping[s.getAttribute('data-field')] = s.value || null; runPreview(); });
        });
    }

    // ── report ──────────────────────────────────────────────────────────────

    function load(msg, kind) {
        return fetch(API + '?mode=report', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'Could not read the import.', 'danger'); return; }
                data = d;
                render();
                say(msg || '', kind);
            })
            .catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() {
        var s = data.summary, l = data.ledger;
        if (!s.ready || !l.ready) {
            $('jbi-warnings').innerHTML = '<div class="alert alert-warning">Migration 1239 hasn\'t run yet — you can preview a file, but nothing can be imported until it has.</div>';
            ['jbi-summary', 'jbi-ar', 'jbi-revenue', 'jbi-deposits', 'jbi-left', 'jbi-open', 'jbi-log'].forEach(function (id) { $(id).innerHTML = '<div class="p-3 text-muted small">—</div>'; });
            return;
        }
        $('jbi-warnings').innerHTML = l.warnings.map(function (w) { return '<div class="alert alert-warning py-2 small">' + esc(w) + '</div>'; }).join('');
        renderSummary(s);
        renderAr(s.ar_check);
        renderRevenue(l.revenue);
        renderDeposits(l.proposals);
        renderLeft(l.unmatched_deposits, l.unmatched_units);
        renderOpen(s.open);
        renderLog(l.log);
    }

    function renderSummary(s) {
        var rows = s.years.map(function (y) { return '<tr><td>' + esc(y.year) + '</td><td class="mw-bbc-num">' + y.count + '</td><td class="mw-bbc-num">' + money(y.total) + '</td></tr>'; });
        $('jbi-summary').innerHTML = (rows.length ? table(['Issued', '$Invoices', '$Total'], rows) : '<div class="p-3 text-muted small">No Jobber invoices yet.</div>') +
            '<div class="p-2 small">Matched: ' + Object.keys(s.match).map(function (k) { return esc(HOW[k] || k) + ' ' + s.match[k]; }).join(' · ') +
            '<br>Payments: ' + s.payments.count + ' (' + money(s.payments.total) + ')' + (s.payments.payouts ? ', ' + s.payments.payouts + ' Jobber Payments payouts, fees ' + money(s.payments.fees) : '') + '</div>';
    }

    function renderAr(a) {
        $('jbi-ar').innerHTML = table(['', '$Amount'], [
            '<tr><td>Still owing on 2025-and-earlier invoices</td><td class="mw-bbc-num">' + money(a.still_owing) + '</td></tr>',
            '<tr><td>+ paid on them in 2026 (Transaction List)</td><td class="mw-bbc-num">' + money(a.paid_in_2026) + '</td></tr>',
            '<tr class="mw-bbc-strong"><td>Jobber receivable at Dec 31 (' + a.invoices + ' invoices)</td><td class="mw-bbc-num">' + money(a.total) + '</td></tr>',
            '<tr><td>Filed accounts receivable</td><td class="mw-bbc-num">' + money(a.filed) + '</td></tr>',
            '<tr class="mw-bbc-strong"><td>Difference</td><td class="mw-bbc-num ' + (Math.abs(a.difference) < 1 ? 'mw-bbc-ok' : 'mw-bbc-off') + '">' + money(a.difference) + '</td></tr>'
        ]) + '<div class="p-2 small text-muted">Bad debts left out (' + money(a.bad_debt_excluded) + '). A difference is for the accountant: invoices marked paid in Jobber without a payment, or receivables written off.</div>';
    }

    function renderRevenue(r) {
        var rows = r.items.slice(0, 300).map(function (it) {
            return '<tr><td>#' + esc(it.number) + '</td><td>' + esc(it.client) + '</td><td>' + esc(it.issued) + '</td><td class="mw-bbc-num">' + money(it.total) + '</td><td class="mw-bbc-num">' + money(it.tax) +
                (it.tax_source === 'computed' ? ' <span class="badge bg-secondary" title="5/105 of the total">computed</span>' : '') + '</td></tr>';
        });
        var held = r.held.map(function (it) {
            return '<tr><td>#' + esc(it.number) + '</td><td>' + esc(it.client) + '</td><td>' + esc(it.issued) + '</td><td class="mw-bbc-num">' + money(it.total) + '</td><td class="small">' + esc(it.reason) + '</td></tr>';
        });
        $('jbi-revenue').innerHTML = '<div class="card mw-card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
            '<div><h5 class="card-title mb-0">Revenue — Jobber invoices issued in 2026</h5><div class="small text-muted">DR 1100 Receivable / CR 4900 + CR 2200 GST, dated the issue date. 2025 invoices are in the FY2025 numbers and never posted.' +
            (r.computed_tax ? ' GST computed (5/105) for ' + r.computed_tax + ' — import the Transaction List for Jobber\'s own split.' : '') + '</div></div>' +
            '<div class="text-end"><div><strong>' + r.count + '</strong> to book, ' + money(r.total) + '</div><button type="button" class="btn btn-sm btn-success mt-1" id="jbi-book-rev"' + (r.count ? '' : ' disabled') + '>Book ' + r.count + '</button></div></div>' +
            (rows.length ? '<details class="mw-bbc-details"><summary class="px-3 py-2 small">Show the invoices</summary><div class="mw-bbc-scroll">' + table(['Invoice', 'Client', 'Issued', '$Total', '$GST'], rows) + '</div></details>' : '') +
            (held.length ? '<details class="mw-bbc-details"><summary class="px-3 py-2 small">' + held.length + ' held back — for you to decide</summary><div class="mw-bbc-scroll">' + table(['Invoice', 'Client', 'Issued', '$Total', 'Why'], held) + '</div></details>' : '') +
            '</div>';
        var b = $('jbi-book-rev');
        if (b) b.addEventListener('click', function () {
            if (!window.confirm('Book ' + r.count + ' Jobber invoices as 2026 revenue (' + money(r.total) + ')?')) return;
            b.disabled = true;
            post({ mode: 'book_revenue', signature: r.signature }).then(done);
        });
    }

    function renderDeposits(list) {
        if (!list.length) { $('jbi-deposits').innerHTML = '<div class="card mw-card mb-3"><div class="card-body small text-muted">No 2026 deposits matched to Jobber payments right now.</div></div>'; return; }
        var rows = list.map(function (p, i) {
            var pays = p.payment_list.map(function (x) {
                return esc(x.date) + ' ' + esc(x.client) + ' ' + money(x.amount) + (x.fee ? ' (fee ' + money(x.fee) + ')' : '') + ' — ' + esc(x.method || '') + (x.invoices ? ' #' + esc(x.invoices) : '') + (x.kind === 'deposit' ? ' <strong>prepayment</strong>' : '');
            }).join('<br>');
            var notes = p.blocked.map(function (b) { return '<div class="small mw-bbc-off">' + esc(b) + '</div>'; }).join('') +
                p.flags.map(function (f) { return '<div class="small text-warning">' + esc(f) + '</div>'; }).join('');
            return '<tr><td><input type="checkbox" data-i="' + i + '"' + (p.preselect ? ' checked' : '') + (p.blocked.length || p.locked ? ' disabled' : '') + '></td>' +
                '<td>' + esc(p.date) + '</td><td>' + esc(p.description) + '<div class="small text-muted">' + esc(p.how) + ' · <span class="badge ' + (p.confidence === 'strong' ? 'bg-success' : (p.confidence === 'good' ? 'bg-info text-dark' : 'bg-secondary')) + '">' + esc(p.confidence) + '</span></div>' +
                '<details class="small"><summary>' + p.payment_list.length + ' Jobber payment(s)</summary>' + pays + '</details>' + notes + '</td>' +
                '<td class="mw-bbc-num">' + money(p.amount) + '</td><td class="mw-bbc-num">' + (p.fee ? money(p.fee) : '') + '</td>' +
                '<td class="mw-bbc-num">' + (p.fy2025_part ? money(p.fy2025_part) : '') + '</td><td class="mw-bbc-num">' + money(p.income_after) + '</td>' +
                '<td class="small">' + (p.revenue_numbers.length ? '#' + esc(p.revenue_numbers.join(', #')) : '') + '</td></tr>';
        });
        var pre = list.filter(function (p) { return p.preselect; }).length;
        $('jbi-deposits').innerHTML = '<div class="card mw-card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
            '<div><h5 class="card-title mb-0">Deposits matched to Jobber payments — ' + list.length + '</h5><div class="small text-muted">Each: DR bank (+ DR 6800 fees) / CR 1100 for the invoices paid. The part that paid 2025 invoices stops counting as 2026 income; revenue for the 2026 invoices it paid is posted with it if it isn\'t yet. Strong and good matches are ticked; flagged ones are yours to decide.</div></div>' +
            '<button type="button" class="btn btn-sm btn-success" id="jbi-book-dep">Book ticked (' + pre + ')</button></div>' +
            '<div class="mw-bbc-scroll">' + table(['', 'Date', 'Deposit / matched to', '$Amount', '$Fees', '$FY2025 part', '$2026 income after', 'Revenue posted with it'], rows) + '</div></div>';
        var btn = $('jbi-book-dep');
        var boxes = document.querySelectorAll('#jbi-deposits input[data-i]');
        Array.prototype.forEach.call(boxes, function (c) {
            c.addEventListener('change', function () {
                btn.textContent = 'Book ticked (' + document.querySelectorAll('#jbi-deposits input[data-i]:checked').length + ')';
            });
        });
        btn.addEventListener('click', function () {
            var picks = [];
            Array.prototype.forEach.call(document.querySelectorAll('#jbi-deposits input[data-i]:checked'), function (c) {
                var p = list[Number(c.getAttribute('data-i'))];
                picks.push({ key: p.key, signature: p.signature });
            });
            if (!picks.length) { say('Tick the deposits to book.', 'warning'); return; }
            if (!window.confirm('Book ' + picks.length + ' deposit(s) against their Jobber invoices? You can undo it afterwards.')) return;
            btn.disabled = true;
            say('Booking…', 'info');
            post({ mode: 'book_deposits', picks: picks }).then(done);
        });
    }

    function renderLeft(deps, units) {
        var drows = deps.slice(0, 200).map(function (d) {
            return '<tr><td>' + esc(d.date) + '</td><td>' + esc(d.description) +
                (d.crm_invoice ? '<div class="small text-muted">Same amount as CRM invoice ' + esc(d.crm_invoice) + ' — link it on the income clean-up page instead</div>' : '') +
                (d.bbc_booked ? '<div class="small text-muted">Booked as income by the bank balance check</div>' : '') + '</td>' +
                '<td class="mw-bbc-num">' + money(d.amount) + '</td><td class="mw-jbi-act"><input class="form-control form-control-sm" placeholder="Jobber #s" data-tx="' + d.id + '">' +
                '<button type="button" class="btn btn-sm btn-outline-success" data-link="' + d.id + '">Link</button>' +
                (d.bbc_booked ? '' : '<button type="button" class="btn btn-sm btn-outline-secondary" data-income="' + d.id + '" title="No Jobber invoice: DR bank / CR revenue + GST">Income, no invoice</button>') + '</td></tr>';
        });
        var urows = units.slice(0, 200).map(function (u) {
            return '<tr><td>' + esc(u.date) + '</td><td>' + esc(u.method) + (u.payout ? ' ' + esc(u.payout) : '') + '</td><td>' + esc(u.clients.join(', ')) + '</td><td class="mw-bbc-num">' + u.count +
                '</td><td class="mw-bbc-num">' + money(u.net) + (u.fee ? '<div class="small text-muted">' + money(u.gross) + ' − ' + money(u.fee) + ' fees</div>' : '') + '</td></tr>';
        });
        $('jbi-left').innerHTML =
            '<details class="card mw-card mb-2 mw-bbc-details"><summary class="card-header small">2026 deposits with no Jobber match — <strong>' + deps.length + '</strong> (after-cutover ones are mostly CRM invoices)</summary>' +
            '<div class="mw-bbc-scroll">' + (drows.length ? table(['Date', 'Deposit', '$Amount', ''], drows) : '<div class="p-3 small text-muted">None.</div>') + '</div></details>' +
            '<details class="card mw-card mb-3 mw-bbc-details"><summary class="card-header small">2026 Jobber payments not found on a statement — <strong>' + units.length + '</strong></summary>' +
            '<div class="mw-bbc-scroll">' + (urows.length ? table(['Date', 'How paid', 'Client(s)', '$Payments', '$To the bank'], urows) : '<div class="p-3 small text-muted">None.</div>') + '</div></details>';
        Array.prototype.forEach.call(document.querySelectorAll('#jbi-left [data-link]'), function (b) {
            b.addEventListener('click', function () {
                var tx = b.getAttribute('data-link');
                var nums = document.querySelector('#jbi-left input[data-tx="' + tx + '"]').value;
                if (!nums.trim()) { say('Type the Jobber invoice #s this deposit paid.', 'warning'); return; }
                if (!window.confirm('Book this deposit against Jobber #' + nums + '?')) return;
                b.disabled = true;
                post({ mode: 'link_manual', transaction_id: Number(tx), numbers: nums }).then(done);
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll('#jbi-left [data-income]'), function (b) {
            b.addEventListener('click', function () {
                if (!window.confirm('No Jobber invoice behind this deposit — book it as income (DR bank / CR revenue + GST)?')) return;
                b.disabled = true;
                post({ mode: 'book_income', transaction_id: Number(b.getAttribute('data-income')) }).then(done);
            });
        });
    }

    function renderOpen(o) {
        var sec = [['past_due', 'Past due'], ['paid_with_balance', 'Marked paid in Jobber but with a balance'], ['open', 'Awaiting payment']];
        var html = sec.map(function (s) {
            var list = o[s[0]] || [];
            if (!list.length) return '';
            var total = list.reduce(function (a, x) { return a + x.balance; }, 0);
            var rows = list.map(function (x) {
                var act = x.crm_invoice ? '<span class="badge bg-success">recreated as ' + esc(x.crm_invoice) + '</span>' :
                    '<a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener" href="/crm/invoices/create.php' + (x.contact_id ? '?contact_id=' + x.contact_id : '') + '">Recreate</a>' +
                    '<input class="form-control form-control-sm" placeholder="CRM INV-…" data-jid="' + x.id + '"><button type="button" class="btn btn-sm btn-outline-secondary" data-crm="' + x.id + '">Link</button>';
                return '<tr><td>#' + esc(x.number) + '</td><td>' + esc(x.client) + '<div class="small text-muted">' + esc(x.address) + '</div></td><td>' + esc(x.issued) +
                    (x.in_opening ? ' <span class="badge bg-secondary" title="Part of the Dec 31 receivable">2025</span>' : '') + '</td>' +
                    '<td class="mw-bbc-num">' + money(x.total) + '</td><td class="mw-bbc-num">' + money(x.balance) + '</td><td class="mw-jbi-act">' + act + '</td></tr>';
            });
            return '<details class="card mw-card mb-2 mw-bbc-details"' + (s[0] === 'past_due' ? ' open' : '') + '><summary class="card-header small">' + esc(s[1]) + ' — <strong>' + list.length + '</strong>, ' + money(total) + '</summary>' +
                '<div class="mw-bbc-scroll">' + table(['Invoice', 'Client', 'Issued', '$Total', '$Owing', ''], rows) + '</div></details>';
        }).join('');
        $('jbi-open').innerHTML = (html || '<p class="small text-muted">Nothing owing in Jobber.</p>') +
            '<p class="small text-muted">Recreate opens a new CRM invoice for the client; then type its number here and Link. A 2025 invoice is already in the opening receivable — linking posts a carry-over entry so its revenue isn\'t counted again in 2026.</p>';
        Array.prototype.forEach.call(document.querySelectorAll('#jbi-open [data-crm]'), function (b) {
            b.addEventListener('click', function () {
                var id = b.getAttribute('data-crm');
                var num = document.querySelector('#jbi-open input[data-jid="' + id + '"]').value.trim();
                if (!num) { say('Type the CRM invoice number first.', 'warning'); return; }
                b.disabled = true;
                post({ mode: 'link_crm', jobber_id: Number(id), invoice_number: num }).then(done);
            });
        });
    }

    function renderLog(log) {
        var el = $('jbi-log');
        if (!log.length) { el.innerHTML = '<div class="p-3 text-muted small">Nothing booked yet.</div>'; return; }
        el.innerHTML = table(['When', 'What', ''], log.map(function (b) {
            var what = [];
            if (b.revenue) what.push(b.revenue + ' invoice(s) revenue');
            if (b.deposits) what.push(b.deposits + ' deposit(s)');
            if (b.income) what.push(b.income + ' as income');
            if (b.links) what.push('CRM link');
            return '<tr><td>' + esc(b.created_at) + '</td><td>' + esc(what.join(', ') || b.steps + ' step(s)') + '</td><td class="text-end">' +
                (b.undone ? '<span class="badge bg-secondary">undone</span>' : '<button type="button" class="btn btn-sm btn-outline-secondary" data-undo="' + esc(b.batch_id) + '">Undo</button>') + '</td></tr>';
        }));
        Array.prototype.forEach.call(el.querySelectorAll('[data-undo]'), function (b) {
            b.addEventListener('click', function () {
                if (!window.confirm('Undo this approval? Its entries are reversed and the bank lines put back.')) return;
                b.disabled = true;
                post({ mode: 'undo', batch_id: b.getAttribute('data-undo') }).then(done);
            });
        });
    }

    load();
})();
