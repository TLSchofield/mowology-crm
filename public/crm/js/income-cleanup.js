/**
 * Penny's income clean-up page (/crm/accounting/income-cleanup.php), 2026-10-07.
 * Reads /crm/api/income-cleanup.php; books only what Tim approves (the server rebuilds the
 * proposal and refuses any line that changed — the browser sends ids + signatures only).
 */
(function () {
    'use strict';
    var API = '/crm/api/income-cleanup.php';
    var GROUPS = {
        stripe:    { title: 'Stripe payouts', blurb: 'Card payments already counted on their invoices. Stripe is checked when you approve; a payout that doesn\'t add up to the cent is left with the reason.' },
        exact:     { title: 'One invoice', blurb: 'The deposit is exactly one invoice — already paid (link it) or still owing (record it).' },
        exact_sum: { title: 'Several invoices, exact sum', blurb: 'One client\'s invoices adding up to the cent (e.g. 2 × $402.02).' },
        needs_you: { title: 'Needs you', blurb: 'Partials, unknowns and conflicts. Nothing is booked here — closest candidates shown.' },
        jobber:    { title: 'Jobber era (before the CRM)', blurb: 'Before 2026-02-25 the deposit is the only record of the income — it stays income. Flagged lines look like they aren\'t income at all.' }
    };
    var ACTIONS = { link_payments: 'Link recorded payment', already_recorded: 'Link paid invoice', record_payment: 'Record payment', stripe_payout: 'Split payout', none: '—' };
    var data = null;
    var ticked = {};      // txId => true

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) { var n = Number(v || 0); return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function say(msg, kind) {
        var el = $('ic-msg');
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        if (!msg) el.className = 'alert d-none';
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function bookable(l) { return ['stripe', 'exact', 'exact_sum'].indexOf(l.bucket) >= 0 && l.action !== 'none' && !l.skipped && !l.locked; }

    function load(msg, kind) {
        return fetch(API + '?mode=proposal', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'Could not read the proposal.', 'danger'); return; }
                data = d;
                ticked = {};
                d.lines.forEach(function (l) { if (bookable(l)) ticked[l.id] = true; });
                render();
                if (msg) say(msg, kind);
            })
            .catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() {
        renderTotals();
        renderGroups();
        renderMonths();
        renderGst();
        renderJournal();
        renderLog();
    }

    function renderTotals() {
        var t = data.income.total;
        var card = function (label, value, sub) {
            return '<div class="col-6 col-lg-3"><div class="card mw-card h-100"><div class="card-body">' +
                '<div class="text-muted small">' + esc(label) + '</div><div class="h4 mb-0">' + money(value) + '</div>' +
                (sub ? '<div class="small text-muted">' + sub + '</div>' : '') + '</div></div></div>';
        };
        $('ic-totals').innerHTML =
            card(data.year + ' income now', t.before, 'what the P&amp;L adds up') +
            card('After approving everything ticked', t.after, money(t.delta) + ' of double counting removed') +
            card('If the “needs you” lines are double too', t.after_if_needs_you_double, money(t.needs_you) + ' still to look at') +
            card('Jobber-era lines flagged', t.jobber_flagged, 'look like transfers / loans / refunds — not booked here');
    }

    function renderGroups() {
        var html = '';
        Object.keys(GROUPS).forEach(function (b) {
            var g = data.groups[b], lines = data.lines.filter(function (l) { return l.bucket === b; });
            if (!lines.length) return;
            var n = lines.filter(function (l) { return ticked[l.id]; }).length;
            var canBook = ['stripe', 'exact', 'exact_sum'].indexOf(b) >= 0;
            html += '<div class="card mw-card mb-3" data-group="' + b + '"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                '<div><h5 class="card-title mb-0">' + esc(GROUPS[b].title) + ' <span class="badge bg-light text-dark">' + g.count + ' · ' + money(g.total) + '</span>' +
                (g.flagged ? ' <span class="badge bg-warning text-dark">' + g.flagged + ' flagged · ' + money(g.flagged_total) + '</span>' : '') +
                (g.locked ? ' <span class="badge bg-secondary">' + g.locked + ' in locked months</span>' : '') + '</h5>' +
                '<div class="small text-muted">' + esc(GROUPS[b].blurb) + (canBook ? ' Income effect: <b>' + money(g.income_delta) + '</b>.' : '') + '</div></div>' +
                (canBook ? '<button type="button" class="btn btn-sm btn-primary" data-ic="group" data-bucket="' + b + '"' + (n ? '' : ' disabled') + '>Approve this group (' + n + ')</button>' : '') +
                '</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr>' +
                (canBook ? '<th></th>' : '') + '<th>Date</th><th class="text-end">Amount</th><th>Bank line</th><th>Penny</th><th></th></tr></thead><tbody>' +
                lines.map(function (l) { return row(l, canBook); }).join('') + '</tbody></table></div></div></div>';
        });
        $('ic-groups').innerHTML = html || '<div class="alert alert-success">Nothing left — every ' + data.year + ' deposit counts once.</div>';
    }

    function row(l, canBook) {
        var cands = (l.candidates || []).map(function (c) {
            return '<div class="small text-muted">' + esc(c.kind) + ': ' + esc(c.invoice || (c.invoices || []).join(', ')) + (c.payer ? ' (' + esc(c.payer) + ')' : '') +
                (c.amount != null ? ' ' + money(c.amount) + (c.diff ? ' · ' + (c.diff > 0 ? '+' : '') + money(c.diff) : '') : '') + (c.date ? ' · ' + esc(c.date) : '') + (c.how ? ' · ' + esc(c.how) : '') + '</div>';
        }).join('');
        var muted = l.skipped || l.locked;
        return '<tr' + (muted ? ' class="text-muted"' : '') + '>' +
            (canBook ? '<td><input type="checkbox" data-ic="tick" data-id="' + l.id + '"' + (ticked[l.id] ? ' checked' : '') + (bookable(l) ? '' : ' disabled') + ' aria-label="Approve this line"></td>' : '') +
            '<td class="text-nowrap">' + esc(l.date) + '</td><td class="text-end text-nowrap">' + money(l.amount) + '</td>' +
            '<td class="small">' + esc(l.description) + '</td>' +
            '<td class="small"><span class="badge bg-light text-dark">' + esc(ACTIONS[l.action] || l.action) + '</span> ' + esc(l.say) +
            (l.note ? '<div class="text-muted">' + esc(l.note) + '</div>' : '') +
            (l.flag ? '<div><span class="badge bg-warning text-dark">' + esc(l.flag) + '</span></div>' : '') +
            (l.locked ? '<div><span class="badge bg-secondary">locked month</span></div>' : '') +
            (l.skipped ? '<div><span class="badge bg-secondary">skipped</span> ' + esc(l.skip_note || '') + '</div>' : '') + cands + '</td>' +
            '<td class="text-nowrap">' +
            (l.skipped ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-ic="unskip" data-id="' + l.id + '">Un-skip</button>'
                       : (l.bucket !== 'jobber' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-ic="skip" data-id="' + l.id + '">Skip</button>' : '')) +
            (bookable(l) ? ' <button type="button" class="btn btn-sm btn-outline-primary" data-ic="one" data-id="' + l.id + '">Approve</button>' : '') +
            '</td></tr>';
    }

    function renderMonths() {
        var rows = data.income.months.map(function (m) {
            return '<tr><td>' + esc(m.month) + '</td><td class="text-end">' + money(m.before) + '</td><td class="text-end">' + (m.delta ? money(m.delta) : '') +
                '</td><td class="text-end"><b>' + money(m.after) + '</b></td><td class="text-end text-muted">' + (m.needs_you ? money(m.needs_you) : '') + '</td></tr>';
        }).join('');
        var t = data.income.total;
        $('ic-months').innerHTML = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Month</th><th class="text-end">Now</th><th class="text-end">Change</th><th class="text-end">After</th><th class="text-end">Needs you</th></tr></thead><tbody>' +
            rows + '</tbody><tfoot><tr class="fw-bold"><td>' + data.year + '</td><td class="text-end">' + money(t.before) + '</td><td class="text-end">' + money(t.delta) + '</td><td class="text-end">' + money(t.after) +
            '</td><td class="text-end">' + money(t.needs_you) + '</td></tr></tfoot></table></div>' +
            '<p class="small text-muted m-2">“Now” is every income row the P&amp;L adds (invoices + deposits). Recording a payment on an open invoice moves the income from the bank line to the invoice — no change.</p>';
    }

    function renderGst() {
        var g = data.gst;
        var rows = g.periods.map(function (p) {
            return '<tr><td>' + esc(p.label) + '</td><td class="text-end">' + money(p.ledger_before) + '</td><td class="text-end">' + money(p.ledger_after) + '</td><td class="text-end">' + (p.ledger_delta ? money(p.ledger_delta) : '') + '</td></tr>';
        }).join('');
        var filed = g.filed.map(function (f) {
            return '<li>' + esc(f.from) + ' → ' + esc(f.to) + ' (filed ' + esc(f.filed_on || '?') + ', from ' + esc(f.basis) + '): ' +
                (f.adjust ? '<span class="badge bg-warning text-dark">adjust on next return</span> ' : '') + esc(f.say) + '</li>';
        }).join('');
        $('ic-gst').innerHTML =
            '<p class="small mb-2">The GST report now takes line 101 from <b>invoices</b>, so booking changes nothing there. The old ledger figure (every income row — what the GST tab showed before 7 Oct) drops like this:</p>' +
            '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Period</th><th class="text-end">Ledger now</th><th class="text-end">After</th><th class="text-end">Change</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            (g.filings_known ? '<ul class="small">' + filed + '</ul>'
                             : '<p class="small text-muted">No filed returns recorded. Tell me which periods you filed and where line 101 came from, and I\'ll show any adjustment for your next return:</p>') +
            '<form class="row g-2 small" data-ic="filing">' +
            '<div class="col-6 col-md-3"><label class="form-label">From</label><input class="form-control form-control-sm" name="from" placeholder="2026-01-01" required pattern="\\d{4}-\\d{2}-\\d{2}"></div>' +
            '<div class="col-6 col-md-3"><label class="form-label">To</label><input class="form-control form-control-sm" name="to" placeholder="2026-03-31" required pattern="\\d{4}-\\d{2}-\\d{2}"></div>' +
            '<div class="col-6 col-md-3"><label class="form-label">Line 101 came from</label><select class="form-select form-select-sm" name="basis">' +
            '<option value="ledger">CRM GST tab (ledger)</option><option value="invoices">invoices</option><option value="accountant">my accountant</option><option value="unknown">not sure</option></select></div>' +
            '<div class="col-6 col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Note it as filed</button></div></form>';
    }

    function renderJournal() {
        var j = data.journal;
        $('ic-journal').innerHTML = j.ok === null ? 'Couldn\'t check the journal.'
            : (j.count ? '<span class="badge bg-warning text-dark">' + j.count + ' lines · ' + money(j.total) + '</span> Deposits already tied to invoices without a single invoice on the bank line are in the journal as money <b>out</b>. Report only — ask before fixing.'
                       : '<span class="badge bg-success">OK</span> No tied deposit is posted in the journal. Every line I book keeps its invoice on the bank line, so the journal stays clean.');
    }

    function renderLog() {
        var rows = (data.log || []).map(function (r) {
            return '<tr><td class="small text-nowrap">' + esc((r.booked_at || '').slice(0, 16)) + '</td><td class="small">#' + r.transaction_id + '</td><td class="small">' + esc(ACTIONS[r.action] || r.action) +
                '</td><td class="text-end small">' + money(r.amount) + '</td><td class="small">' + esc(r.invoice_numbers || '') + (r.note ? ' — ' + esc(r.note) : '') + '</td><td class="small">' + esc(r.status) +
                '</td><td>' + (r.status === 'booked' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-ic="undo" data-log="' + r.id + '">Undo</button>' : '') + '</td></tr>';
        }).join('');
        $('ic-log').innerHTML = rows ? '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>' + rows + '</tbody></table></div>' : '<p class="small text-muted m-3">Nothing booked yet.</p>';
    }

    function loadCc() {
        fetch(API + '?mode=cc2400', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (c) {
            if (!c || !c.ok) { $('ic-cc').innerHTML = '<span class="text-muted small">' + esc((c && (c.message || c.error)) || 'Could not read 2400.') + '</span>'; return; }
            var src = c.by_source.map(function (s) { return '<tr><td>' + esc(s.source) + '</td><td class="text-end">' + money(s.debit) + '</td><td class="text-end">' + money(s.credit) + '</td><td class="text-end">' + money(s.net) + '</td></tr>'; }).join('');
            var mo = c.by_month.map(function (m) { return '<tr><td>' + esc(m.month) + '</td><td class="text-end">' + money(m.debit) + '</td><td class="text-end">' + money(m.credit) + '</td><td class="text-end">' + money(m.running) + '</td></tr>'; }).join('');
            var kinds = c.bank_rows.by_kind.map(function (k) { return '<li>' + esc(k.kind.replace(/_/g, ' ')) + ': ' + k.n + ' · ' + money(k.total) + '</li>'; }).join('');
            var sus = c.suspects.slice(0, 25).map(function (s) { return '<tr><td class="text-nowrap">' + esc(s.date) + '</td><td class="text-end">' + money(s.amount) + '</td><td>' + esc(s.description) + '</td><td>' + esc(s.why) + '</td></tr>'; }).join('');
            var dup = c.duplicates.map(function (d) { return '<li>' + esc(d.date) + ' ' + money(d.amount) + ' — ' + esc(d.description) + ' (lines #' + d.ids.join(', #') + ')</li>'; }).join('');
            var cs = c.card_statements.sessions.map(function (s) { return '<li>' + esc(s.bank || s.filename) + ' ' + esc(s.from || '') + ' → ' + esc(s.to || '') + ' (' + esc(s.status) + ')</li>'; }).join('');
            $('ic-cc').innerHTML = '<p><span class="badge ' + (c.balance_side === 'debit' ? 'bg-danger' : 'bg-success') + '">' + esc(c.balance_side) + ' ' + money(Math.abs(c.balance)) + '</span> ' + esc(c.say) + '</p>' +
                '<div class="row g-3"><div class="col-lg-6"><h6>Journal by source</h6><table class="table table-sm"><thead><tr><th>Source</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Net</th></tr></thead><tbody>' + src + '</tbody></table>' +
                '<h6>Bank list rows on 2400</h6><ul class="small">' + kinds + '</ul>' +
                '<h6>Card statements imported</h6>' + (cs ? '<ul class="small">' + cs + '</ul>' : '<p class="small text-danger">None — card purchases reach 2400 only through receipts marked paid by card.</p>') + '</div>' +
                '<div class="col-lg-6"><h6>By month (running balance)</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Month</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Running</th></tr></thead><tbody>' + mo + '</tbody></table></div></div></div>' +
                (sus ? '<h6>Looks misfiled (' + c.suspects.length + ' · ' + money(c.suspects_total) + ')</h6><div class="table-responsive"><table class="table table-sm small"><tbody>' + sus + '</tbody></table></div>' : '<p class="small">No misfiled-looking lines.</p>') +
                (dup ? '<h6>Possible duplicate payoffs (' + money(c.duplicates_total) + ')</h6><ul class="small">' + dup + '</ul>' : '');
        }).catch(function () { $('ic-cc').innerHTML = '<span class="text-muted small">Could not read 2400.</span>'; });
    }

    function approve(ids, label) {
        var approved = {}, total = 0;
        ids.forEach(function (id) {
            var l = data.lines.find(function (x) { return x.id === id; });
            if (l && bookable(l)) { approved[id] = l.signature; total += l.amount; }
        });
        var n = Object.keys(approved).length;
        if (!n) return;
        if (!window.confirm('Book ' + n + ' line' + (n === 1 ? '' : 's') + ' (' + money(total) + ')' + (label ? ' — ' + label : '') + '?\nEach one is logged and can be undone.')) return;
        say('Booking ' + n + '…', 'info');
        post({ mode: 'book', approved: approved }).then(function (r) {
            if (!r.ok) { say(r.error || 'Not booked.', 'danger'); return; }
            var msg = 'Booked ' + r.booked.length + ' — ' + money(r.income_removed) + ' no longer counted twice.';
            if (r.failed.length) msg += ' ' + r.failed.length + ' left: ' + r.failed.slice(0, 5).map(function (f) { return '#' + f.id + ' ' + f.message; }).join(' · ');
            load(msg, r.failed.length ? 'warning' : 'success');
        }).catch(function () { say('Could not reach the server — reload to see what was booked.', 'danger'); });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ic]');
        if (!b || !data) return;
        var k = b.getAttribute('data-ic'), id = Number(b.getAttribute('data-id'));
        if (k === 'tick') { ticked[id] = b.checked; renderGroupButtons(); return; }
        if (k === 'group') {
            var bucket = b.getAttribute('data-bucket');
            approve(data.lines.filter(function (l) { return l.bucket === bucket && ticked[l.id]; }).map(function (l) { return l.id; }), GROUPS[bucket].title);
        } else if (k === 'one') {
            approve([id], '');
        } else if (k === 'skip') {
            var note = window.prompt('Skip this line — it stays exactly as it is. Why? (e.g. "Jobber invoice paid late — real income")', '');
            if (note === null) return;
            post({ mode: 'skip', transaction_id: id, note: note }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        } else if (k === 'unskip') {
            post({ mode: 'unskip', transaction_id: id }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        } else if (k === 'undo') {
            if (!window.confirm('Undo this booking? The deposit goes back to exactly how it was.')) return;
            post({ mode: 'reverse', log_id: Number(b.getAttribute('data-log')) }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        }
    });

    document.addEventListener('submit', function (e) {
        var f = e.target.closest('form[data-ic="filing"]');
        if (!f) return;
        e.preventDefault();
        post({ mode: 'filing', from: f.from.value, to: f.to.value, basis: f.basis.value }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
    });

    function renderGroupButtons() {
        document.querySelectorAll('[data-ic="group"]').forEach(function (btn) {
            var b = btn.getAttribute('data-bucket');
            var n = data.lines.filter(function (l) { return l.bucket === b && ticked[l.id]; }).length;
            btn.textContent = 'Approve this group (' + n + ')';
            btn.disabled = !n;
        });
    }

    load();
    loadCc();
})();
