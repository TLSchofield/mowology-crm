/**
 * Penny's look-back review page (/crm/accounting/lookback.php), migration 1250.
 * Reads /crm/api/lookback.php. The browser sends ids only — the server re-reads each record,
 * refuses one that changed since the proposal (stale) or sits in a locked month.
 */
(function () {
    'use strict';
    var API = '/crm/api/lookback.php';
    var FAMILY = { receipt: 'Receipts', bank: 'Bank & card lines', journal: 'Journal' };
    var LINKS = {
        deposit_invoice: { href: '/crm/accounting/income-cleanup.php', text: 'Income clean-up →' },
        payroll: null, cra_other: null, missing_receipt: null, pst_check: null, journal_unbalanced: null
    };
    var summary = null;
    var loaded = {};          // kind => proposals

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v, dp) {
        var n = Number(v || 0), d = dp === 0 ? 0 : 2;
        return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
    function say(msg, kind) {
        var el = $('lb-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function get(q) { return fetch(API + '?' + q, { cache: 'no-store' }).then(function (r) { return r.json(); }); }

    function load(msg, kind) {
        return get('mode=summary').then(function (d) {
            if (!d || !d.ok) { say((d && d.error) || 'Could not read the look-back.', 'danger'); return; }
            summary = d;
            loaded = {};
            render();
            if (msg) say(msg, kind);
        }).catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() {
        renderTotals();
        renderGroups();
        renderGst();
        renderInfo();
        renderApplied();
        var ai = summary.ai || {};
        $('lb-scan-info').innerHTML = 'Last look: <b>' + esc(summary.last_scan || 'never') + '</b> · Claude spend '
            + money(ai.spent) + ' of ' + money(ai.budget) + ' budget (' + (ai.calls || 0) + ' calls)'
            + (ai.key ? '' : ' · <span class="text-warning">no Anthropic key — rules only</span>');
    }

    function renderTotals() {
        var t = summary.totals;
        var card = function (label, value, sub) {
            return '<div class="col-6 col-lg-3"><div class="card mw-card h-100"><div class="card-body">' +
                '<div class="text-muted small">' + esc(label) + '</div><div class="h4 mb-0">' + value + '</div>' +
                (sub ? '<div class="small text-muted">' + sub + '</div>' : '') + '</div></div></div>';
        };
        $('lb-totals').innerHTML =
            card('Open proposals', String(t.open), 'waiting for you') +
            card('$ they reclassify', money(t.open_amount, 0), 'moved between accounts / categories') +
            card('GST (ITC) effect', money(t.open_gst), t.open_gst < 0 ? 'less GST claimed back' : 'more GST claimed back') +
            card('Applied', String(t.applied), t.info + ' more listed for other pages');
    }

    function renderGroups() {
        var html = '', fam = null, first = 0;
        summary.kinds.forEach(function (k) {
            if (!k.actionable || (k.open + k.stale) === 0) return;
            if (k.family !== fam) { fam = k.family; html += '<h5 class="mt-3 mb-2">' + esc(FAMILY[fam] || fam) + '</h5>'; }
            var bulk = k.open_high > 0
                ? '<button type="button" class="btn btn-sm btn-success" data-bulk="' + esc(k.kind) + '">Approve all ' + k.open_high + ' at ≥' + summary.bulk_confidence + '%</button>' : '';
            html += '<details class="card mw-card mb-2" data-kind="' + esc(k.kind) + '"' + (first++ < 2 ? ' open' : '') + '>' +
                '<summary class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                '<span><b>' + esc(k.label) + '</b> <span class="badge bg-secondary">' + k.open + '</span>' +
                (k.stale ? ' <span class="badge bg-warning text-dark" title="changed since proposed">' + k.stale + ' stale</span>' : '') + '</span>' +
                '<span class="small text-muted">' + money(k.open_amount, 0) + ' · GST ' + money(k.open_gst) + '</span>' + bulk +
                '</summary><div class="card-body p-0" data-list="' + esc(k.kind) + '"><div class="p-3 small text-muted">Loading…</div></div></details>';
        });
        if (!html) html = '<div class="card mw-card"><div class="card-body">Nothing to review — everything Penny can check in 2026 looks right. ✓</div></div>';
        $('lb-groups').innerHTML = html;
        Array.prototype.forEach.call(document.querySelectorAll('#lb-groups details[open]'), function (d) { loadKind(d.getAttribute('data-kind')); });
    }

    function loadKind(kind) {
        if (loaded[kind]) return;
        loaded[kind] = [];
        get('mode=proposals&kind=' + encodeURIComponent(kind)).then(function (d) {
            loaded[kind] = (d && d.proposals) || [];
            renderList(kind);
        });
    }

    function change(before, after) {
        var keys = Object.keys(after).filter(function (k) { return k !== 'copy_of' && k !== 'action' && k !== 'entry_id' && k !== 'expense_id' && k !== 'tx_id'; });
        if (after.action) return '<span class="text-muted">' + esc({ reverse_entry: 'reverse the entry', repost_expense: 're-post from the receipt', rejournal_bank: 're-post from the bank line' }[after.action] || after.action) + '</span>';
        return keys.map(function (k) {
            if (k === 'allocations') {
                return (after.allocations || []).map(function (a) {
                    var to = a.is_stock ? 'stock' : (a.job_id ? 'job #' + a.job_id : (a.accounting_category || 'receipt'));
                    return '<div>line ' + a.line_item_id + ' → <b>' + esc(to) + '</b>' + (a.asset_tag ? ' (' + esc(a.asset_tag) + ')' : '') + '</div>';
                }).join('');
            }
            var f = function (v) { return typeof v === 'number' && /amount|gst/.test(k) ? money(v) : (v == null || v === '' ? '—' : esc(v)); };
            var label = { accounting_category: 'category', asset_tag: 'for', gst_amount: 'GST', amount: 'net', account: 'account', status: 'status', job_id: 'job' }[k] || k;
            return '<div><span class="text-muted">' + label + ':</span> ' + f(before[k]) + ' → <b>' + f(after[k]) + '</b></div>';
        }).join('');
    }

    function badge(c) {
        var cls = c >= 85 ? 'bg-success' : (c >= 65 ? 'bg-info text-dark' : 'bg-warning text-dark');
        return '<span class="badge ' + cls + '" title="confidence">' + c + '%</span>';
    }

    function link(p) {
        if (p.subject_type === 'expense') return '<a href="/crm/expenses_appstack.php?edit=' + p.subject_id + '" target="_blank" rel="noopener">receipt #' + p.subject_id + '</a>';
        if (p.subject_type === 'bank') return '<a href="/crm/accounting/transactions.php?highlight=' + p.subject_id + '" target="_blank" rel="noopener">bank line #' + p.subject_id + '</a>';
        return 'entry #' + p.subject_id;
    }

    function renderList(kind) {
        var box = document.querySelector('[data-list="' + kind + '"]');
        if (!box) return;
        var ps = loaded[kind] || [];
        if (!ps.length) { box.innerHTML = '<div class="p-3 small text-muted">Nothing open.</div>'; return; }
        box.innerHTML = '<div class="table-responsive"><table class="table table-sm mb-0 align-top"><tbody>' + ps.map(function (p) {
            var tags = (p.locked ? ' <span class="badge bg-dark">locked month</span>' : '') +
                (p.gst_filed ? ' <span class="badge bg-warning text-dark" title="That GST return is filed — the change goes on your next return">GST filed: adjust next return</span>' : '') +
                (p.source === 'ai' ? ' <span class="badge bg-light text-dark border">Claude</span>' : (p.source === 'learned' ? ' <span class="badge bg-light text-dark border">learned</span>' : ''));
            return '<tr data-id="' + p.id + '"><td class="small text-nowrap">' + esc(p.txn_date || '') + '<br>' + link(p) + '</td>' +
                '<td><div><b>' + esc(p.title) + '</b> ' + badge(Number(p.confidence)) + tags + '</div>' +
                '<div class="small mt-1">' + change(p.before, p.after) + '</div>' +
                '<ul class="small text-muted mb-0 ps-3">' + p.evidence.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></td>' +
                '<td class="small text-end text-nowrap">' + money(p.amount_impact) + (Math.abs(p.gst_impact) >= 0.005 ? '<br><span class="text-muted">GST ' + money(p.gst_impact) + '</span>' : '') + '</td>' +
                '<td class="text-end text-nowrap">' + (p.status === 'open'
                    ? '<button type="button" class="btn btn-sm btn-primary me-1" data-approve="' + p.id + '"' + (p.locked ? ' disabled' : '') + '>Approve</button>' +
                      '<button type="button" class="btn btn-sm btn-outline-secondary" data-skip="' + p.id + '">Skip</button>'
                    : '<span class="badge bg-secondary">' + esc(p.status) + '</span>') + '</td></tr>';
        }).join('') + '</tbody></table></div>' + (ps.length >= 100 ? '<div class="p-2 small text-muted">Showing the first 100 — approve or skip some to see more.</div>' : '');
    }

    function renderGst() {
        $('lb-gst').innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Quarter</th><th class="text-end">Open</th><th class="text-end">Applied</th></tr></thead><tbody>' +
            summary.gst.map(function (q) {
                return '<tr><td>' + esc(q.label) + (q.filed ? ' <span class="badge bg-secondary">filed</span>' : '') + '</td>' +
                    '<td class="text-end">' + money(q.open) + '</td><td class="text-end">' + money(q.applied) + '</td></tr>' +
                    (q.say ? '<tr><td colspan="3" class="small text-warning">' + esc(q.say) + '</td></tr>' : '');
            }).join('') + '</tbody></table><div class="p-2 small text-muted">ITC change: negative = less GST claimed back. A filed quarter is never changed — the difference goes on your next return.</div>';
    }

    function renderInfo() {
        var rows = summary.kinds.filter(function (k) { return k.info > 0; });
        $('lb-info').innerHTML = rows.length ? '<table class="table table-sm mb-0"><tbody>' + rows.map(function (k) {
            var l = LINKS[k.kind];
            return '<tr><td class="small">' + esc(k.label) + (l ? '<br><a href="' + l.href + '">' + esc(l.text) + '</a>' : '') + '</td>' +
                '<td class="text-end small">' + k.info + '</td><td class="text-end small">' + money(k.info_amount, 0) + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="p-3 small text-muted">Nothing.</div>';
    }

    function renderApplied() {
        get('mode=proposals&status=applied').then(function (d) {
            var ps = (d && d.proposals) || [];
            $('lb-applied').innerHTML = ps.length ? '<table class="table table-sm mb-0"><tbody>' + ps.slice(0, 40).map(function (p) {
                var undoable = !(p.subject_type === 'journal' && p.after && p.after.action !== 'reverse_entry');
                return '<tr><td class="small">' + esc(p.title) + '<br><span class="text-muted">' + esc(p.result_note || '') + '</span></td><td class="text-end">' +
                    (undoable ? '<button type="button" class="btn btn-sm btn-outline-danger" data-undo="' + p.id + '">Undo</button>' : '') + '</td></tr>';
            }).join('') + '</tbody></table>' : '<div class="p-3 small text-muted">Nothing applied yet.</div>';
        });
    }

    document.addEventListener('toggle', function (ev) {
        var d = ev.target;
        if (d && d.tagName === 'DETAILS' && d.open && d.getAttribute('data-kind')) loadKind(d.getAttribute('data-kind'));
    }, true);

    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        var id;
        if ((id = b.getAttribute('data-approve'))) {
            b.disabled = true;
            post({ mode: 'approve', id: Number(id) }).then(function (r) {
                say(r.message || r.error, r.ok ? 'success' : (r.status === 'stale' ? 'warning' : 'danger'));
                if (r.ok || r.status) { var tr = b.closest('tr'); if (tr) tr.remove(); refreshSoon(); } else b.disabled = false;
            });
        } else if ((id = b.getAttribute('data-skip'))) {
            post({ mode: 'skip', id: Number(id) }).then(function (r) {
                say(r.message || r.error, r.ok ? 'secondary' : 'danger');
                if (r.ok) { var tr = b.closest('tr'); if (tr) tr.remove(); refreshSoon(); }
            });
        } else if ((id = b.getAttribute('data-undo'))) {
            b.disabled = true;
            post({ mode: 'undo', id: Number(id) }).then(function (r) { load(r.message || r.error, r.ok ? 'success' : 'danger'); });
        } else if ((id = b.getAttribute('data-bulk'))) {
            ev.preventDefault();
            if (!window.confirm('Apply every open proposal of this kind at ' + summary.bulk_confidence + '% confidence or more? Each one can still be undone.')) return;
            b.disabled = true;
            post({ mode: 'approve_kind', kind: id }).then(function (r) {
                load(r.ok ? ('Applied ' + r.applied + (r.refused ? ', ' + r.refused + ' refused: ' + r.messages.join(' · ') : '') + '.') : (r.message || r.error), r.ok ? 'success' : 'danger');
            });
        } else if (b.id === 'lb-scan' || b.id === 'lb-scan-ai') {
            b.disabled = true;
            say('Looking again…', 'info');
            post({ mode: 'scan', ai_calls: b.id === 'lb-scan-ai' ? 10 : 0 }).then(function (r) {
                b.disabled = false;
                if (!r.ok) { say(r.error || 'The scan failed.', 'danger'); return; }
                var s = r.stored || {};
                load(r.proposals + ' found · ' + (s.inserted || 0) + ' new · ' + (s.kept_decided || 0) + ' already decided · ' + (s.removed || 0) + ' fixed elsewhere'
                     + (r.ai && r.ai.calls ? ' · Claude: ' + r.ai.calls + ' calls, ' + money(r.ai.cost) : ''), 'success');
            }).catch(function () { b.disabled = false; say('The scan failed.', 'danger'); });
        }
    });

    var timer = null;
    function refreshSoon() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            get('mode=summary').then(function (d) { if (d && d.ok) { summary = d; renderTotals(); renderGst(); renderApplied(); } });
        }, 400);
    }

    load();
})();
