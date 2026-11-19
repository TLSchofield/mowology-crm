/**
 * Bank balance check page (/crm/accounting/bank-balance-check.php), 2026-10-07.
 * Reads /crm/api/bank-balance-check.php; books only the group Tim approves (the server rebuilds
 * the group and refuses it if it changed — the browser sends the group name + signature only).
 */
(function () {
    'use strict';
    var API = '/crm/api/bank-balance-check.php';
    var GROUPS = {
        part_payments: { title: 'Invoice payments missing from the journal', blurb: 'Each part payment becomes its own entry (DR Bank / CR Receivable). Where the old one-per-invoice entry doesn\'t add up, it is reversed and every payment posted by itself.' },
        opening: { title: 'Opening balance', blurb: 'Starts each account where its first statement starts, against 3900 Opening Balance Equity.' }
    };
    var REVIEW = {
        savings_one_sided: 'Savings lines whose money reached the journal on neither side (no entry, no chequing twin)',
        unposted_bank_lines: 'Bank lines the nightly sync should have posted but hasn\'t',
        orphan_bank_entries: 'Journal entries for bank lines that were deleted or rolled back',
        statement_gaps: 'Breaks in a statement\'s running balance — lines never imported',
        jobber_deposits: 'Jan–Mar 2026 deposits with no CRM invoice, not in the journal — book them on the Jobber import page (against the Jobber invoices they paid)',
        crm_deposits: 'Deposits Feb 25 – Mar 31 that match a CRM invoice — link them on the income clean-up page, never posted here',
        payment_review: 'Invoices whose payments on record are more than the invoice says was paid'
    };
    var data = null;
    var current = null;   // account code shown

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) {
        if (v === null || v === undefined) return '—';
        var n = Number(v);
        return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function cls(v) { return v === null || v === undefined ? '' : (Math.abs(v) < 0.01 ? 'mw-bbc-ok' : 'mw-bbc-off'); }
    function say(msg, kind) {
        var el = $('bbc-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function ymLabel(ym) {
        var p = ym.split('-');
        return new Date(Number(p[0]), Number(p[1]) - 1, 1).toLocaleString('en-CA', { month: 'short', year: 'numeric' });
    }

    function load(msg, kind) {
        return fetch(API + '?mode=report', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'Could not read the check.', 'danger'); return; }
                data = d;
                if (!current || !d.accounts.some(function (a) { return a.code === current; })) current = d.accounts.length ? d.accounts[0].code : null;
                render();
                say(msg || '', kind);
            })
            .catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() {
        $('bbc-ready').innerHTML = data.ready ? '' :
            '<div class="alert alert-warning">Migration 1236 hasn\'t run yet — the check is shown, but nothing can be approved until it has (every fix is logged so it can be undone).</div>';
        renderTabs();
        renderMonths();
        renderCauses();
        renderGroups();
        renderReview();
        renderLog();
    }

    function account() {
        for (var i = 0; i < data.accounts.length; i++) if (data.accounts[i].code === current) return data.accounts[i];
        return null;
    }

    function renderTabs() {
        var el = $('bbc-tabs');
        if (!data.accounts.length) { el.innerHTML = ''; return; }
        el.innerHTML = data.accounts.map(function (a) {
            var d = a.latest ? a.latest.drift : null;
            return '<li class="nav-item"><button type="button" class="nav-link' + (a.code === current ? ' active' : '') + '" data-code="' + esc(a.code) + '">' +
                esc(a.code) + ' ' + esc(a.name) + ' <span class="badge ' + (d !== null && Math.abs(d) >= 0.01 ? 'bg-warning text-dark' : 'bg-success') + '">' + money(d) + '</span></button></li>';
        }).join('');
        Array.prototype.forEach.call(el.querySelectorAll('button[data-code]'), function (b) {
            b.addEventListener('click', function () { current = b.getAttribute('data-code'); render(); });
        });
    }

    function renderMonths() {
        var a = account();
        if (!a) {
            $('bbc-months').innerHTML = '<div class="p-3 text-muted small">No statement lines with running balances yet — import a statement first.</div>';
            $('bbc-acct-note').textContent = '';
            return;
        }
        $('bbc-acct-note').textContent = (a.kind === 'card' ? 'Card: balance owed. ' : '') + 'From January 2026 (FY2025 is filed). ' +
            (a.carried !== null && a.carried !== undefined ? 'Carried in at Dec 31: ' + money(a.carried) + (a.opening_booked ? ' after the FY2026 opening. ' : ' — book the FY2026 opening. ') : '') +
            'Drift = books − statement.';
        var rows = a.months.slice().reverse().map(function (m) {
            return '<tr><td>' + esc(ymLabel(m.ym)) + '</td>' +
                '<td class="mw-bbc-num">' + money(m.statement) + '</td>' +
                '<td class="mw-bbc-num">' + money(m.books) + '</td>' +
                '<td class="mw-bbc-num ' + cls(m.drift) + '">' + money(m.drift) + '</td>' +
                '<td class="mw-bbc-num">' + (m.delta === null ? '—' : money(m.delta)) + '</td>' +
                '<td class="mw-bbc-num ' + cls(m.after) + '">' + money(m.after) + '</td>' +
                '<td class="mw-bbc-num ' + cls(m.remainder) + '">' + money(m.remainder) + '</td></tr>';
        }).join('');
        $('bbc-months').innerHTML = '<table class="table table-sm mb-0 mw-bbc-table"><thead><tr><th>Month end</th><th class="mw-bbc-num">Statement</th>' +
            '<th class="mw-bbc-num">Books</th><th class="mw-bbc-num">Drift</th><th class="mw-bbc-num">Change</th>' +
            '<th class="mw-bbc-num" title="Drift once every fix group below is approved">After fixes</th>' +
            '<th class="mw-bbc-num" title="Drift none of the causes explains">Unexplained</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function renderCauses() {
        var a = account();
        var el = $('bbc-causes');
        if (!a || !a.latest) { el.innerHTML = '<div class="p-3 text-muted small">—</div>'; return; }
        var m = a.latest;
        var rows = Object.keys(data.causes).map(function (k) {
            var v = m.causes[k];
            if (v === undefined) return '';
            return '<tr><td>' + esc(data.causes[k].label) + (data.causes[k].fix ? ' <span class="badge bg-success">fix</span>' : ' <span class="badge bg-secondary">review</span>') +
                '</td><td class="mw-bbc-num">' + money(v) + '</td></tr>';
        }).join('');
        el.innerHTML = '<table class="table table-sm mb-0 mw-bbc-table"><tbody>' +
            '<tr class="mw-bbc-strong"><td>Drift at ' + esc(ymLabel(m.ym)) + '</td><td class="mw-bbc-num">' + money(m.drift) + '</td></tr>' + rows +
            '<tr class="mw-bbc-strong"><td>Unexplained</td><td class="mw-bbc-num ' + cls(m.remainder) + '">' + money(m.remainder) + '</td></tr>' +
            '</tbody></table><div class="p-2 small text-muted">Each line is its share of the drift (the books are short where it is negative).</div>';
    }

    function renderGroups() {
        var html = Object.keys(GROUPS).map(function (g) {
            var grp = data.groups[g];
            if (!grp) return '';
            var preview = grp.preview.length ? grp.preview.map(function (p) {
                return '<span class="mw-bbc-preview">' + esc(p.code) + ': ' + money(p.before) + ' → <strong>' + money(p.after) + '</strong></span>';
            }).join(' ') : '<span class="text-muted">No change to the latest drift.</span>';
            var items = grp.items.slice(0, 60).map(function (it) {
                return '<tr><td>' + esc(it.label) + (it.locked ? ' <span class="badge bg-secondary">locked</span>' : '') +
                    '<div class="small text-muted">' + esc(it.reason) + '</div></td><td class="mw-bbc-num">' + money(it.effect) + '</td></tr>';
            }).join('');
            var more = grp.items.length > 60 ? '<div class="p-2 small text-muted">… and ' + (grp.items.length - 60) + ' more.</div>' : '';
            var can = data.ready && grp.count > 0;
            return '<div class="card mw-card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                '<div><h5 class="card-title mb-0">' + esc(GROUPS[g].title) + '</h5><div class="small text-muted">' + esc(GROUPS[g].blurb) + '</div></div>' +
                '<div class="text-end"><div><strong>' + grp.count + '</strong> to book, ' + money(grp.total) + (grp.locked ? ' · ' + grp.locked + ' in locked months skipped' : '') + '</div>' +
                '<button type="button" class="btn btn-sm btn-success mt-1" data-approve="' + g + '"' + (can ? '' : ' disabled') + '>Approve ' + grp.count + '</button></div></div>' +
                '<div class="card-body py-2 small">Latest drift: ' + preview + '</div>' +
                (items ? '<details class="mw-bbc-details"><summary class="px-3 py-2 small">Show what would be booked</summary><div class="mw-bbc-scroll"><table class="table table-sm mb-0 mw-bbc-table"><tbody>' + items + '</tbody></table>' + more + '</div></details>' : '') +
                '</div>';
        }).join('');
        $('bbc-groups').innerHTML = html;
        Array.prototype.forEach.call(document.querySelectorAll('[data-approve]'), function (b) {
            b.addEventListener('click', function () { approve(b.getAttribute('data-approve'), b); });
        });
    }

    function approve(g, btn) {
        var grp = data.groups[g];
        if (!window.confirm('Book ' + grp.count + ' fix(es) in "' + GROUPS[g].title + '" (' + money(grp.total) + ')? You can undo it afterwards.')) return;
        btn.disabled = true;
        say('Booking…', 'info');
        post({ mode: 'approve', group: g, signature: grp.signature }).then(function (r) {
            load(r.message || r.error || 'Done.', r.ok ? 'success' : 'warning');
        }).catch(function () { say('Could not reach the server — nothing was confirmed. Reload to see where it stands.', 'danger'); });
    }

    function renderReview() {
        var html = Object.keys(REVIEW).map(function (k) {
            var r = data.review[k];
            if (!r || !r.items.length) return '';
            var rows = r.items.slice(0, 100).map(function (it) {
                var label = it.invoice_number ? 'Invoice ' + it.invoice_number : (it.description || it.from || '');
                return '<tr><td>' + esc(it.date || '') + '</td><td>' + esc(it.account || '') + '</td><td>' + esc(label) +
                    (it.reason ? '<div class="small text-muted">' + esc(it.reason) + '</div>' : '') + '</td><td class="mw-bbc-num">' +
                    (it.amount !== undefined ? money(it.amount) : '') + '</td></tr>';
            }).join('');
            return '<details class="card mw-card mb-2 mw-bbc-details"><summary class="card-header small">' + esc(REVIEW[k]) +
                ' — <strong>' + r.items.length + '</strong>' + (r.total ? ', ' + money(r.total) : '') + '</summary>' +
                '<div class="mw-bbc-scroll"><table class="table table-sm mb-0 mw-bbc-table"><tbody>' + rows + '</tbody></table></div></details>';
        }).join('');
        $('bbc-review').innerHTML = html || '<p class="text-muted small">Nothing.</p>';
    }

    function renderLog() {
        var el = $('bbc-log');
        if (!data.log.length) { el.innerHTML = '<div class="p-3 text-muted small">Nothing booked yet.</div>'; return; }
        el.innerHTML = '<table class="table table-sm mb-0 mw-bbc-table"><tbody>' + data.log.map(function (b) {
            return '<tr><td>' + esc(b.created_at) + '</td><td>' + esc((GROUPS[b.group] || {}).title || b.group) + '</td><td>' + b.steps + ' step(s)</td>' +
                '<td class="mw-bbc-num">' + money(b.amount) + '</td><td class="text-end">' +
                (b.undone ? '<span class="badge bg-secondary">undone</span>' : '<button type="button" class="btn btn-sm btn-outline-secondary" data-undo="' + esc(b.batch_id) + '">Undo</button>') +
                '</td></tr>';
        }).join('') + '</tbody></table>';
        Array.prototype.forEach.call(el.querySelectorAll('[data-undo]'), function (b) {
            b.addEventListener('click', function () {
                if (!window.confirm('Undo this approval? Its entries are reversed (and anything it reversed is posted again).')) return;
                b.disabled = true;
                post({ mode: 'undo', batch_id: b.getAttribute('data-undo') }).then(function (r) {
                    load(r.message || r.error || 'Done.', r.ok ? 'success' : 'warning');
                });
            });
        });
    }

    load();
})();
