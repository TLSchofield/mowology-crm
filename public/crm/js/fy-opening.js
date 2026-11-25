/**
 * FY2026 opening page (/crm/accounting/fy-opening.php), 2026-10-07.
 * Reads /crm/api/fy-opening.php; Book sends only the signature (the server rebuilds the entry and
 * refuses it if the books changed since the page was read).
 */
(function () {
    'use strict';
    var API = '/crm/api/fy-opening.php';
    var MODES = { receive: 'takes the filed figure', statement: 'statement balance', keep: 'kept', zero: 'closed' };
    var data = null;

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) {
        if (v === null || v === undefined) return '—';
        var n = Number(v);
        return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function say(msg, kind) {
        var el = $('fyo-msg');
        if (!msg) { el.className = 'alert d-none'; return; }
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function load(msg, kind) {
        return fetch(API + '?mode=report', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { say((d && d.error) || 'Could not read the books.', 'danger'); return; }
                data = d;
                render();
                say(msg || '', kind);
            })
            .catch(function () { say('Could not reach the server.', 'danger'); });
    }

    function render() {
        var st = [];
        if (!data.ready) st.push('<div class="alert alert-warning">Migration 1238 hasn\'t run — the figures below use the built-in copy of the filed balance sheet, and nothing can be booked until it has.</div>');
        if (data.booked) st.push('<div class="alert alert-success">Booked — entry #' + data.booked.entry_id + ' dated ' + esc(data.booked.entry_date) + '. The lines below are what a second booking would add (should be nothing).</div>');
        if (data.locked) st.push('<div class="alert alert-warning">January 2026 is locked — unlock it to book the opening.</div>');
        if (data.problems.length) st.push('<div class="alert alert-danger">' + data.problems.map(esc).join('<br>') + '</div>');
        if (!data.filed_check.balanced) st.push('<div class="alert alert-danger">The filed figures don\'t balance: assets ' + money(data.filed_check.assets) + ' vs liabilities + equity ' + money(data.filed_check.liabilities_equity) + '.</div>');
        $('fyo-status').innerHTML = st.join('');
        renderLines();
        renderRe();
        renderNotes();
        renderActions();
    }

    function renderLines() {
        $('fyo-totals').textContent = 'At ' + data.as_of + ' · ' + data.lines.length + ' lines · Dr ' + money(data.totals.debit) + ' / Cr ' + money(data.totals.credit) +
            (data.totals.balanced ? ' · balanced' : ' · DOES NOT BALANCE');
        var html = '';
        Object.keys(data.groups).forEach(function (g) {
            var grp = data.groups[g];
            if (!grp.accounts.length && !grp.filed) return;
            var isClose = g === 'close';
            html += '<tr class="mw-bbc-strong"><td>' + esc(grp.label) + (grp.side ? ' <span class="small text-muted">(' + esc(grp.side) + ')</span>' : '') + '</td>' +
                '<td class="mw-bbc-num">' + money(grp.crm) + '</td><td class="mw-bbc-num">' + (grp.side ? money(grp.filed) : '—') + '</td>' +
                '<td class="mw-bbc-num">' + money(grp.after - grp.crm) + '</td><td class="mw-bbc-num small">' + (grp.re_effect === null || grp.re_effect === undefined ? '' : money(grp.re_effect)) + '</td></tr>';
            grp.accounts.forEach(function (l) {
                var adj = l.debit > 0 ? 'Dr ' + money(l.debit) : (l.credit > 0 ? 'Cr ' + money(l.credit) : '—');
                html += '<tr><td class="mw-fyo-acct">' + esc(l.code) + ' ' + esc(l.name) +
                    ' <span class="badge ' + (l.mode === 'receive' ? 'bg-success' : (l.mode === 'zero' ? 'bg-secondary' : 'bg-info text-dark')) + '">' + esc(isClose ? 'closed to RE' : (MODES[l.mode] || l.mode)) + '</span>' +
                    (l.note ? '<div class="small text-muted">' + esc(l.note) + '</div>' : '') + '</td>' +
                    '<td class="mw-bbc-num">' + money(l.crm) + '</td><td class="mw-bbc-num">' + money(l.target) + '</td>' +
                    '<td class="mw-bbc-num ' + (Math.abs(l.debit + l.credit) >= 0.01 ? 'mw-bbc-off' : 'mw-bbc-ok') + '">' + adj + '</td><td></td></tr>';
            });
        });
        $('fyo-lines').innerHTML = '<table class="table table-sm mb-0 mw-bbc-table"><thead><tr><th>Account</th>' +
            '<th class="mw-bbc-num">CRM had</th><th class="mw-bbc-num">Filed / target</th><th class="mw-bbc-num">Adjustment</th>' +
            '<th class="mw-bbc-num" title="What correcting this line does to retained earnings">→ RE</th></tr></thead><tbody>' + html + '</tbody></table>';
    }

    function renderRe() {
        var re = data.retained;
        var rows = [
            ['CRM 3200 Retained earnings at Dec 31', re.crm_account],
            ['+ 2025 revenue less expenses (closed)', re.closing_pl],
            ['+ other equity closed (draws, 3900 opening equity)', re.closing_equity],
            ['= retained earnings the CRM implies', re.implied, true],
            ['Filed retained earnings', re.filed, true],
            ['Correction (filed − implied)', re.unexplained, true],
            ['Line on 3200 in this entry', re.adjustment]
        ];
        $('fyo-re').innerHTML = '<table class="table table-sm mb-0 mw-bbc-table"><tbody>' + rows.map(function (r) {
            return '<tr' + (r[2] ? ' class="mw-bbc-strong"' : '') + '><td>' + esc(r[0]) + '</td><td class="mw-bbc-num">' + money(r[1]) + '</td></tr>';
        }).join('') + '</tbody></table><div class="p-2 small ' + (re.matches_filed ? 'mw-bbc-ok' : 'mw-bbc-off') + '">' +
            (re.matches_filed ? 'After the entry retained earnings are ' + money(re.after) + ' — the filed figure.' : 'After the entry retained earnings would be ' + money(re.after) + ', not ' + money(re.filed) + '.') + '</div>';
    }

    function renderNotes() {
        var n = data.notes.slice();
        n.push('Source: ' + data.source + '. Filed assets ' + money(data.filed_check.assets) + ' = liabilities + equity ' + money(data.filed_check.liabilities_equity) + '.');
        n.push('The "→ RE" column is what each line\'s correction does to retained earnings; together they make the correction above.');
        n.push('2026 income statements leave this entry out (it closes 2025, it isn\'t 2026 activity).');
        $('fyo-notes').innerHTML = '<ul class="mb-0 ps-3">' + n.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
    }

    function renderActions() {
        var el = $('fyo-actions');
        if (data.booked) {
            el.innerHTML = '<p class="small mb-2">Undo reverses entry #' + data.booked.entry_id + ' (the journal keeps both).</p>' +
                '<button type="button" class="btn btn-outline-secondary btn-sm" id="fyo-undo">Undo the opening</button>';
            $('fyo-undo').addEventListener('click', function () {
                if (!window.confirm('Reverse the FY2026 opening entry?')) return;
                this.disabled = true;
                post({ mode: 'undo' }).then(function (r) { load(r.message || r.error, r.ok ? 'success' : 'warning'); });
            });
            return;
        }
        var can = data.ready && !data.locked && !data.problems.length && data.totals.balanced && data.lines.length > 1;
        el.innerHTML = '<p class="small mb-2">Books ' + data.lines.length + ' lines dated ' + esc(data.opening_date) + ' (adjusting). Retained earnings start 2026 at ' + money(data.retained.after) + '.</p>' +
            '<button type="button" class="btn btn-success" id="fyo-book"' + (can ? '' : ' disabled') + '>Book the FY2026 opening</button>';
        $('fyo-book').addEventListener('click', function () {
            if (!window.confirm('Book the FY2026 opening (' + data.lines.length + ' lines, ' + money(data.totals.debit) + ')? You can undo it afterwards.')) return;
            this.disabled = true;
            say('Booking…', 'info');
            post({ mode: 'book', signature: data.signature }).then(function (r) { load(r.message || r.error, r.ok ? 'success' : 'warning'); })
                .catch(function () { say('Could not reach the server — reload to see where it stands.', 'danger'); });
        });
    }

    load();
})();
