/**
 * Payments ↔ invoices (/crm/accounting/payment-match.php), 2026-10-07.
 * Reads /crm/api/payment-match.php?mode=scan. Books only what Tim approves: the browser sends
 * deposit ids + signatures (or a deposit + the items he ticked); the server rebuilds the match
 * and refuses anything that changed. Every booking can be undone from "What I've booked".
 */
(function () {
    'use strict';
    var API = '/crm/api/payment-match.php';
    var GROUPS = {
        high:      { title: 'High confidence', blurb: 'The memo names the payer (or the strata plan, or an e-Transfer ref) and the amount adds up to the cent, one way only.', all: true },
        medium:    { title: 'Medium', blurb: 'Adds up to the cent, but the memo doesn\'t say who paid — or a part-payment of the payer it names. Check, then approve.' },
        low:       { title: 'Low', blurb: 'Several payers could fit, a wide date gap, or a part-payment where the payer has several open invoices.' },
        needs_you: { title: 'Needs you', blurb: 'Nothing adds up. Pick by hand (tick what it paid on the right) or skip it with a note.' },
        skipped:   { title: 'Skipped', blurb: 'You left these as they are.' },
        stripe:    { title: 'Stripe payouts', blurb: 'After the cutover: the income clean-up books these from Stripe itself. Not touched here.' }
    };
    var KIND = { crm_open: 'CRM · owing', crm_paid: 'CRM · paid, not linked', crm_rec: 'CRM · payment recorded', jb_inv: 'Jobber · owing', jb_pay: 'Jobber · payment' };
    var data = null;
    var pick = null;      // { id, targets: {key: amount} }

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) { var n = Number(v || 0); return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function cents(v) { return Math.round(Number(v || 0) * 100); }
    function say(msg, kind) {
        var el = $('pm-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function bookable(l) { return ['high', 'medium', 'low'].indexOf(l.outcome) >= 0 && !l.locked && !l.blocked; }
    function line(id) { return data.lines.find(function (l) { return l.id === id; }); }

    function load(msg, kind) {
        return fetch(API + '?mode=scan', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'Could not read the bank lines.', 'danger'); return; }
                data = d;
                if (pick && !line(pick.id)) pick = null;
                render();
                if (msg) say(msg, kind);
                else if (!d.ready) say('Run migration 1260 before booking — the matching below is read-only until then.', 'warning');
            })
            .catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() { renderTotals(); renderGroups(); renderPick(); renderOpen(); renderLog(); }

    function renderTotals() {
        var s = data.summary, o = s.open_invoices;
        var card = function (label, value, sub, cls) {
            return '<div class="col-6 col-lg-3"><div class="card mw-card mw-pm-stat h-100' + (cls ? ' ' + cls : '') + '"><div class="card-body">' +
                '<div class="text-muted small">' + esc(label) + '</div><div class="h4 mb-0">' + value + '</div>' +
                (sub ? '<div class="small text-muted">' + sub + '</div>' : '') + '</div></div></div>';
        };
        $('pm-totals').innerHTML =
            card('Deposits not tied to an invoice', s.deposits.count + ' · ' + money(s.deposits.total), '2026, every account') +
            card('Matched, waiting for you', s.proposed.count + ' · ' + money(s.proposed.total), s.by_outcome.high.count + ' high · ' + s.by_outcome.medium.count + ' medium · ' + s.by_outcome.low.count + ' low', 'is-good') +
            card('Settles 2025 receivable', money(s.fy2025_settled), 'paid 2025 Jobber invoices — not 2026 income') +
            card('Open invoices with money found', (o.crm.covered.count + o.jobber.covered.count) + ' of ' + (o.crm.open.count + o.jobber.open.count),
                 money(o.crm.none.total + o.jobber.none.total) + ' owing with nothing in the bank', 'is-warn');
    }

    function renderGroups() {
        var html = '';
        Object.keys(GROUPS).forEach(function (g) {
            var lines = data.lines.filter(function (l) { return l.outcome === g; });
            if (!lines.length) return;
            var total = lines.reduce(function (a, l) { return a + l.amount; }, 0);
            var ok = lines.filter(bookable);
            html += '<div class="card mw-card mb-3 mw-pm-group is-' + g + '"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                '<div><h5 class="card-title mb-0">' + esc(GROUPS[g].title) + ' <span class="badge bg-light text-dark">' + lines.length + ' · ' + money(total) + '</span></h5>' +
                '<div class="small text-muted">' + esc(GROUPS[g].blurb) + '</div></div>' +
                (GROUPS[g].all && ok.length ? '<button type="button" class="btn btn-sm btn-primary" data-pm="all" data-group="' + g + '">Approve all ' + ok.length + ' (' + money(ok.reduce(function (a, l) { return a + l.amount; }, 0)) + ')</button>' : '') +
                '</div><div class="card-body p-0"><ul class="list-unstyled mb-0 mw-pm-list">' + lines.map(row).join('') + '</ul></div></div>';
        });
        $('pm-groups').innerHTML = html || '<div class="alert alert-success">Every 2026 deposit is tied to what it paid.</div>';
    }

    function row(l) {
        var targets = (l.targets || []).map(function (t) {
            return '<li><span class="mw-pm-kind">' + esc(KIND[t.kind] || t.kind) + '</span> <b>' + esc(t.number) + '</b> ' + esc(t.payer) +
                ' <span class="text-nowrap">' + money(t.amount) + (t.owing && Math.abs(t.owing - t.amount) > 0.005 ? ' of ' + money(t.owing) : '') + '</span>' +
                (t.year && t.year < '2026' ? ' <span class="badge bg-warning text-dark">' + esc(t.year) + ' invoice</span>' : '') + '</li>';
        }).join('');
        var cands = (l.candidates || []).map(function (c) {
            return '<li class="text-muted">' + esc(KIND[c.kind] || c.kind) + ' ' + esc(c.number) + ' ' + esc(c.payer) + ' ' + money(c.amount) +
                ' <span class="mw-pm-diff">' + (c.diff > 0 ? '+' : '') + money(c.diff) + '</span>' + (c.named ? ' · ' + esc(c.named) : '') + '</li>';
        }).join('');
        var on = pick && pick.id === l.id;
        return '<li class="mw-pm-row' + (on ? ' is-picked' : '') + '">' +
            '<div class="mw-pm-dep"><span class="text-nowrap">' + esc(l.date) + '</span> <b class="text-nowrap">' + money(l.amount) + '</b> <span class="small text-muted">' + esc(l.description) + '</span></div>' +
            '<div class="small">' + esc(l.why) +
            (l.note ? ' <span class="text-muted">' + esc(l.note) + '</span>' : '') + '</div>' +
            (targets ? '<ul class="mw-pm-targets small">' + targets + '</ul>' : '') +
            (cands ? '<div class="small text-muted mt-1">Closest:</div><ul class="mw-pm-targets small">' + cands + '</ul>' : '') +
            (l.flag ? '<span class="badge bg-warning text-dark">' + esc(l.flag) + '</span> ' : '') +
            (l.flags || []).map(function (f) { return '<div class="small text-muted">⚑ ' + esc(f) + '</div>'; }).join('') +
            (l.blocked ? '<div class="small text-danger">' + esc(l.blocked) + '</div>' : '') +
            (l.locked ? '<span class="badge bg-secondary">locked month</span> ' : '') +
            '<div class="mw-pm-actions">' +
            (bookable(l) ? '<button type="button" class="btn btn-sm btn-success" data-pm="one" data-id="' + l.id + '">Approve</button>' : '') +
            (l.outcome === 'skipped' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-pm="unskip" data-id="' + l.id + '">Un-skip</button>'
                : (l.outcome !== 'stripe' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-pm="skip" data-id="' + l.id + '">Skip</button>' +
                   '<button type="button" class="btn btn-sm btn-outline-primary" data-pm="pick" data-id="' + l.id + '">' + (on ? 'Picking…' : 'Pick by hand') + '</button>' : '')) +
            '</div></li>';
    }

    function renderPick() {
        var el = $('pm-pick');
        if (!pick) { el.className = 'mw-pm-pick d-none'; el.innerHTML = ''; return; }
        var l = line(pick.id);
        var keys = Object.keys(pick.targets);
        var sum = keys.reduce(function (a, k) { return a + cents(pick.targets[k]); }, 0);
        var diff = cents(l.amount) - sum;
        el.className = 'mw-pm-pick card mw-card mb-3';
        el.innerHTML = '<div class="card-body"><div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">' +
            '<div><b>Picking for ' + esc(l.date) + ' ' + money(l.amount) + '</b> <span class="small text-muted">' + esc(l.description) + '</span>' +
            '<div class="small">Tick what it paid on the right. Ticked: ' + keys.length + ' · ' + money(sum / 100) +
            ' · <span class="mw-pm-diff ' + (diff === 0 ? 'is-zero' : '') + '">difference ' + money(diff / 100) + '</span></div></div>' +
            '<div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-success" data-pm="manual"' + (diff === 0 && keys.length ? '' : ' disabled') + '>Book this</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-pm="cancel">Cancel</button></div></div></div>';
    }

    function openRows() {
        var f = $('pm-filter').value, q = ($('pm-search').value || '').toLowerCase().trim();
        var cov = {};
        data.open.forEach(function (o) { cov[o.key] = o; });
        var list = f === 'all' ? data.candidates : data.open;
        if (f === 'none') list = list.filter(function (o) { return o.status === 'none'; });
        if (q) list = list.filter(function (o) { return (o.number + ' ' + o.payer + ' ' + (o.names || []).join(' ')).toLowerCase().indexOf(q) >= 0; });
        return { list: list, cov: cov };
    }

    function renderOpen() {
        var s = data.summary.open_invoices;
        $('pm-open-sum').textContent = 'CRM ' + s.crm.open.count + ' · ' + money(s.crm.open.total) + ' — Jobber ' + s.jobber.open.count + ' · ' + money(s.jobber.open.total);
        var r = openRows();
        var rows = r.list.slice(0, 400).map(function (o) {
            var c = r.cov[o.key];
            var owing = o.owing != null ? o.owing : o.amount;
            var st = c ? c.status : '';
            var ticked = pick && pick.targets[o.key] != null;
            var partial = o.kind === 'crm_open' || o.kind === 'jb_inv';
            var bank = c && c.deposits.length ? c.deposits.map(function (d) { return esc(d.date) + ' ' + money(d.amount) + ' (' + esc(d.confidence) + ')'; }).join(', ') : '';
            return '<tr class="' + (ticked ? 'is-ticked' : '') + '">' +
                (pick ? '<td><input type="checkbox" data-pm="tick" data-key="' + esc(o.key) + '"' + (ticked ? ' checked' : '') + ' aria-label="This deposit paid ' + esc(o.number) + '"></td>' : '') +
                '<td class="small"><b>' + esc(o.number) + '</b><div class="text-muted">' + esc(KIND[o.kind] || o.kind) + (o.issued ? ' · ' + esc(o.issued) : (o.date ? ' · ' + esc(o.date) : '')) + '</div></td>' +
                '<td class="small">' + esc(o.payer) + '</td>' +
                '<td class="text-end small text-nowrap">' + money(owing) +
                (ticked && partial ? '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm mw-pm-amt" data-pm="amt" data-key="' + esc(o.key) + '" value="' + Number(pick.targets[o.key]).toFixed(2) + '" aria-label="Amount paid">' : '') + '</td>' +
                '<td class="small">' + (st === 'covered' ? '<span class="badge bg-success">in bank</span>' : st === 'partly' ? '<span class="badge bg-info text-dark">part in bank</span>' :
                    st === 'none' ? '<span class="badge bg-light text-dark">not found</span>' : '') + (bank ? '<div class="text-muted">' + bank + '</div>' : '') + '</td></tr>';
        }).join('');
        $('pm-open').innerHTML = rows ? '<div class="table-responsive mw-pm-open"><table class="table table-sm mb-0 align-middle"><tbody>' + rows + '</tbody></table></div>' +
            (r.list.length > 400 ? '<p class="small text-muted m-2">Showing 400 of ' + r.list.length + ' — narrow it with the search.</p>' : '')
            : '<p class="small text-muted m-3">Nothing here.</p>';
    }

    function renderLog() {
        var rows = (data.log || []).map(function (r) {
            return '<tr><td class="small text-nowrap">' + esc((r.created_at || '').slice(0, 16)) + '</td><td class="small">#' + r.transaction_id + '</td>' +
                '<td class="small">' + esc(r.source || '') + ' · ' + esc(r.action) + ' · ' + esc(r.confidence || '') + '</td><td class="text-end small">' + money(r.amount) + '</td>' +
                '<td class="small">' + esc((r.numbers || []).join(', ')) + (Number(r.fy2025_part) > 0 ? ' — ' + money(r.fy2025_part) + ' FY2025' : '') + (r.note ? ' — ' + esc(r.note) : '') + '</td>' +
                '<td class="small">' + esc(r.status) + '</td><td>' + (r.status === 'booked' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-pm="undo" data-log="' + r.id + '">Undo</button>' : '') + '</td></tr>';
        }).join('');
        $('pm-log').innerHTML = rows ? '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>' + rows + '</tbody></table></div>' : '<p class="small text-muted m-3">Nothing booked yet.</p>';
    }

    function approve(ids, label) {
        var approved = {}, total = 0;
        ids.forEach(function (id) { var l = line(id); if (l && bookable(l)) { approved[id] = l.signature; total += l.amount; } });
        var n = Object.keys(approved).length;
        if (!n) return;
        if (!window.confirm('Book ' + n + ' deposit' + (n === 1 ? '' : 's') + ' (' + money(total) + ')' + (label ? ' — ' + label : '') + '?\nEach one is logged and can be undone.')) return;
        say('Booking ' + n + '…', 'info');
        post({ mode: 'book', approved: approved }).then(function (r) {
            if (r.error) { say(r.error, 'danger'); return; }
            var msg = 'Booked ' + r.booked.length + '.';
            if (r.failed.length) msg += ' ' + r.failed.length + ' not: ' + r.failed.slice(0, 5).map(function (f) { return '#' + f.id + ' ' + f.message; }).join(' · ');
            load(msg, r.failed.length ? 'warning' : 'success');
        }).catch(function () { say('Could not reach the server — reload to see what was booked.', 'danger'); });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pm]');
        if (!b || !data) return;
        var k = b.getAttribute('data-pm'), id = Number(b.getAttribute('data-id'));
        if (k === 'tick') {
            var key = b.getAttribute('data-key');
            var c = data.candidates.find(function (x) { return x.key === key; });
            if (b.checked && c) {
                var l = line(pick.id), sum = Object.keys(pick.targets).reduce(function (a, x) { return a + cents(pick.targets[x]); }, 0);
                var rest = (cents(l.amount) - sum) / 100;
                pick.targets[key] = c.partial_ok && rest > 0 && rest < c.amount ? rest : c.amount;
            } else delete pick.targets[key];
            renderPick(); renderOpen();
        } else if (k === 'all') {
            var g = b.getAttribute('data-group');
            approve(data.lines.filter(function (l) { return l.outcome === g && bookable(l); }).map(function (l) { return l.id; }), GROUPS[g].title);
        } else if (k === 'one') {
            approve([id], '');
        } else if (k === 'pick') {
            pick = { id: id, targets: {} };
            if ($('pm-filter').value === 'open') $('pm-filter').value = 'all';
            render();
            $('pm-pick').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else if (k === 'cancel') {
            pick = null; render();
        } else if (k === 'manual') {
            var targets = Object.keys(pick.targets).map(function (key) { return { key: key, amount: pick.targets[key] }; });
            b.disabled = true;
            post({ mode: 'manual', transaction_id: pick.id, targets: targets }).then(function (r) {
                if (r.ok) { pick = null; load(r.message, 'success'); } else { b.disabled = false; say(r.message || r.error || 'Not booked.', 'danger'); }
            });
        } else if (k === 'skip') {
            var note = window.prompt('Skip this deposit — it stays exactly as it is. Why? (e.g. "owner loan", "Jobber invoice not imported")', '');
            if (note === null) return;
            post({ mode: 'skip', transaction_id: id, note: note }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        } else if (k === 'unskip') {
            post({ mode: 'unskip', transaction_id: id }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        } else if (k === 'undo') {
            if (!window.confirm('Undo this booking? The deposit and the invoices go back to exactly how they were.')) return;
            post({ mode: 'undo', log_id: Number(b.getAttribute('data-log')) }).then(function (r) { load(r.message, r.ok ? 'success' : 'danger'); });
        }
    });

    document.addEventListener('change', function (e) {
        var i = e.target.closest('[data-pm="amt"]');
        if (!i || !pick) return;
        pick.targets[i.getAttribute('data-key')] = Math.max(0, Number(i.value || 0));
        renderPick();
    });
    $('pm-search').addEventListener('input', function () { if (data) renderOpen(); });
    $('pm-filter').addEventListener('change', function () { if (data) renderOpen(); });

    load();
})();
